<?php
/**
 * 2024 Smaily
 *
 * NOTICE OF LICENSE
 *
 * Smaily for PrestaShop is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Smaily for PrestaShop is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Smaily for PrestaShop. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Smaily <info@smaily.com>
 * @copyright 2024 Smaily
 * @license   GPL3
 */
declare(strict_types=1);

namespace PrestaShop\Module\SmailyForPrestaShop\Automation;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactAudience;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\ApiException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;

/**
 * The consent check of an automated marketing email that no consent event triggered (the
 * abandoned-cart reminder, the first-order email), just before its send: Smaily's status of the
 * contact, read live through the account that sends, then the shop's consent mode
 * (ContactAudience::sendsAutomatedMarketing()). A read that fails sends nothing: the caller's row
 * takes the retry ladder. A reason never names the contact.
 */
final class MarketingConsent
{
    public const SKIPPED_UNSUBSCRIBED_IN_SMAILY = 'unsubscribed_in_smaily';

    public const SKIPPED_NO_MARKETING_CONSENT = 'no_marketing_consent';

    private const NEWSLETTER_TABLE = 'emailsubscription';

    private ContactsSettings $contacts;

    private Transport $transport;

    public function __construct(ContactsSettings $contacts, Transport $transport)
    {
        $this->contacts = $contacts;
        $this->transport = $transport;
    }

    /**
     * Null when the email may go, else the skip reason.
     *
     * @param bool $newsletter the customer has `newsletter = 1`
     * @param bool $boxTicked the newsletter box was ticked in this checkout
     *
     * @throws SmailyClientException when Smaily cannot be read
     */
    public function refusal(Account $account, string $email, int $idShop, bool $newsletter, bool $boxTicked): ?string
    {
        $status = $this->status($account, $email);
        $sends = (new ContactAudience($this->contacts->mode($idShop)))->sendsAutomatedMarketing(
            $newsletter,
            $this->newsletterBlock($email, $idShop),
            $boxTicked,
            $status
        );
        if ($sends) {
            return null;
        }

        return $status === ContactAudience::SMAILY_UNSUBSCRIBED ? self::SKIPPED_UNSUBSCRIBED_IN_SMAILY : self::SKIPPED_NO_MARKETING_CONSENT;
    }

    /**
     * What Smaily holds for the address: one of ContactAudience's SMAILY_* values. Code 206 is an
     * address that Smaily does not know; a contact counts as subscribed only when Smaily says so.
     *
     * @throws SmailyClientException when Smaily cannot be read
     */
    private function status(Account $account, string $email): string
    {
        $client = new Client($this->transport, $account->subdomain(), $account->username(), $account->password());
        try {
            $contact = $client->get(Client::ENDPOINT_CONTACT, ['email' => $email]);
        } catch (ApiException $error) {
            if ($error->getSmailyCode() === ApiException::CODE_EMAIL_NOT_FOUND) {
                return ContactAudience::SMAILY_UNKNOWN;
            }
            throw $error;
        }
        $fields = isset($contact[0]) && is_array($contact[0]) ? $contact[0] : $contact;

        return (string) ($fields['is_unsubscribed'] ?? '') === '0' ? ContactAudience::SMAILY_SUBSCRIBED : ContactAudience::SMAILY_UNSUBSCRIBED;
    }

    /**
     * Whether the address has an active newsletter-block row in the shop.
     */
    private function newsletterBlock(string $email, int $idShop): bool
    {
        $table = _DB_PREFIX_ . self::NEWSLETTER_TABLE;
        if (Sql::rows('SHOW TABLES LIKE \'' . $table . '\'') === []) {
            return false;
        }

        return Sql::value(
            'SELECT 1 FROM `' . $table . '` WHERE `email` = \'' . pSQL($email) . '\' AND `active` = 1 AND `id_shop` = ' . $idShop
        ) !== null;
    }
}
