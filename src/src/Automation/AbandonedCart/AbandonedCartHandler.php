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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\TriggerSender;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactAudience;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\HandlerInterface;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\ApiException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;

/**
 * Sends the `automation.abandoned_cart` rows that the sweep queued, one reminder per row, after it
 * checks again at send time, in this order:
 *  1. the cart's tracker row: an order withdrew it, or the sweep closed it (its `skip_reason`); a
 *     row whose reminder went already, or that another queue row carries, sends nothing;
 *  2. the cart has an order, or its address ordered since the cart's last change: `ordered`;
 *  3. the cart's last change is more than 24 hours ago: `too_old`;
 *  4. the router's workflow and account (`automation_off`, `no_workflow`, `no_account`,
 *     `no_credentials`);
 *  5. Smaily's status of the contact, read through that account: an unsubscribed contact gets no
 *     reminder (`unsubscribed_in_smaily`); a read that fails sends nothing and the row takes the
 *     retry ladder;
 *  6. the consent of the shop's mode (ContactAudience::remindsCart()): `no_marketing_consent`.
 * Then TriggerSender posts the reminder through the account the router matched, and the tracker
 * row records the outcome. A reason never names the contact.
 *
 * The payload: {"id_cart": int, "email": string, "fields": {...}}; a row that the sweep closed holds
 * only `id_cart`.
 */
class AbandonedCartHandler implements HandlerInterface
{
    public const EVENT_TYPE = 'automation.abandoned_cart';

    public const SKIPPED_ORDERED = 'ordered';

    public const SKIPPED_TOO_OLD = 'too_old';

    public const SKIPPED_UNSUBSCRIBED_IN_SMAILY = 'unsubscribed_in_smaily';

    public const SKIPPED_NO_MARKETING_CONSENT = 'no_marketing_consent';

    public const SKIPPED_ALREADY_REMINDED = 'already_reminded';

    public const SKIPPED_NOT_TRACKED = 'not_tracked';

    public const FAILED_MALFORMED_PAYLOAD = 'malformed_payload';

    private const NEWSLETTER_TABLE = 'emailsubscription';

    private CartTracker $tracker;

    private CartAddress $addresses;

    private Router $router;

    private TriggerSender $sender;

    private ContactsSettings $contacts;

    private Transport $transport;

    public function __construct(CartTracker $tracker, CartAddress $addresses, Router $router, TriggerSender $sender, ContactsSettings $contacts, Transport $transport)
    {
        $this->tracker = $tracker;
        $this->addresses = $addresses;
        $this->router = $router;
        $this->sender = $sender;
        $this->contacts = $contacts;
        $this->transport = $transport;
    }

    public function handle(array $events): array
    {
        $outcomes = [];
        foreach ($events as $event) {
            $outcomes[(int) $event['id']] = $this->one($event);
        }

        return $outcomes;
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return true|Skipped|Failure
     */
    private function one(array $event)
    {
        $payload = json_decode((string) $event['payload'], true);
        if (!is_array($payload) || !is_int($payload['id_cart'] ?? null)) {
            return Failure::permanent(self::FAILED_MALFORMED_PAYLOAD);
        }
        $idCart = $payload['id_cart'];
        $row = $this->tracker->row($idCart);
        if ($row === null) {
            return new Skipped(self::SKIPPED_NOT_TRACKED);
        }
        if ($row['status'] === CartTracker::SKIPPED) {
            return new Skipped((string) $row['skip_reason']);
        }
        if ($row['status'] !== CartTracker::OPEN || (int) $row['event_id'] !== (int) $event['id']) {
            return new Skipped(self::SKIPPED_ALREADY_REMINDED);
        }
        if (!is_string($payload['email'] ?? null) || !is_array($payload['fields'] ?? null)) {
            return Failure::permanent(self::FAILED_MALFORMED_PAYLOAD);
        }
        $email = $payload['email'];
        $idShop = (int) $event['id_shop'];

        $skip = $this->ordered($idCart, $email, (string) $row['last_activity_at']) ? self::SKIPPED_ORDERED : null;
        if ($skip === null && (string) $row['last_activity_at'] < $this->tracker->format(-CartTracker::WINDOW_SECONDS)) {
            $skip = self::SKIPPED_TOO_OLD;
        }
        if ($skip !== null) {
            return $this->skip($idCart, $skip);
        }

        $match = $this->router->route(Trigger::ABANDONED_CART, $idShop, (string) $event['language']);
        if ($match instanceof Skipped) {
            return $this->skip($idCart, $match->reason());
        }
        try {
            $status = $this->status($match->account(), $email);
        } catch (SmailyClientException $error) {
            return Failure::of($error);
        }
        $address = $this->addresses->read($idCart);
        $reminds = (new ContactAudience($this->contacts->mode($idShop)))->remindsCart(
            $address !== null && $address['newsletter'],
            $this->newsletterBlock($email, $idShop),
            $address !== null && $address['box_ticked'],
            $status
        );
        if (!$reminds) {
            return $this->skip($idCart, $status === ContactAudience::SMAILY_UNSUBSCRIBED ? self::SKIPPED_UNSUBSCRIBED_IN_SMAILY : self::SKIPPED_NO_MARKETING_CONSENT);
        }

        $outcome = $this->sender->send(Trigger::ABANDONED_CART, $idShop, (string) $event['language'], $email, $payload['fields']);
        if ($outcome === true) {
            $this->tracker->mailed($idCart);
        } elseif ($outcome instanceof Skipped) {
            $this->tracker->skip($idCart, $outcome->reason());
        }

        return $outcome;
    }

    private function skip(int $idCart, string $reason): Skipped
    {
        $this->tracker->skip($idCart, $reason);

        return new Skipped($reason);
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
     * Whether the cart has an order, or its address placed an order since the cart's last change.
     */
    private function ordered(int $idCart, string $email, string $since): bool
    {
        return (bool) \Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . $idCart
            . ' UNION SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` o'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'customer` cu ON cu.`id_customer` = o.`id_customer`'
            . ' WHERE cu.`email` = \'' . pSQL($email) . '\' AND o.`date_add` >= \'' . pSQL($since) . '\'',
            false
        );
    }

    /**
     * Whether the address has an active newsletter-block row in the shop.
     */
    private function newsletterBlock(string $email, int $idShop): bool
    {
        $table = _DB_PREFIX_ . self::NEWSLETTER_TABLE;
        if (!\Db::getInstance()->executeS('SHOW TABLES LIKE \'' . $table . '\'', true, false)) {
            return false;
        }

        return (bool) \Db::getInstance()->getValue(
            'SELECT 1 FROM `' . $table . '` WHERE `email` = \'' . pSQL($email) . '\' AND `active` = 1 AND `id_shop` = ' . $idShop,
            false
        );
    }
}
