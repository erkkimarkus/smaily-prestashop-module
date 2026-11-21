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

namespace PrestaShop\Module\SmailyForPrestaShop\Log;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartHandler;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartSweeper;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\Capture\CaptureEndpoint;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MarketingConsent;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactImport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;

/**
 * What a row's stored `last_error` says to the merchant, as the Magento connector's Log words it.
 *
 * A refusal that was parked at once is stored as `permanent_http_<code>: <message>` or
 * `permanent_envelope_<code>: <message>` (Queue\Failure): the list and Details show the message,
 * and Details show the class beside it. A skip, and a handler's own verdict that a row can never
 * be sent, is stored as a reason code; every code that a handler or an import can store shows as a
 * sentence, in the admin's language: Magento's sentence where Magento has the reason, else one of
 * the same form. Any other text (the Smaily client's own message) shows as stored. Magento has no
 * stale contact sync; that sentence follows its sentence for a replaced profiling choice.
 * Magento tells its merchant nothing of its capture limit; that sentence is this module's own.
 */
final class FailureMessage
{
    /** The sentence of each reason code of a queue row. */
    public const REASONS = [
        MarketingConsent::SKIPPED_NO_MARKETING_CONSENT => 'Skipped: this shopper has not agreed to marketing emails in the store, and is not a subscribed contact in Smaily. Nothing was sent.',
        MarketingConsent::SKIPPED_UNSUBSCRIBED_IN_SMAILY => 'Skipped: this shopper unsubscribed in Smaily. Nothing was sent.',
        AbandonedCartSweeper::SKIPPED_RECENTLY_REMINDED => 'Skipped: this address already got an abandoned-cart reminder for another cart in the last 24 hours. Nothing was sent.',
        AbandonedCartHandler::SKIPPED_CUSTOMER_DELETED => 'Skipped: this shopper\'s customer account was deleted in the store. Nothing was sent.',
        CaptureEndpoint::SKIPPED_CAPTURE_LIMIT => 'Skipped: the store reached its limit of 2000 checkout addresses in one hour. An address typed at checkout after that was not recorded, and gets no abandoned-cart reminder.',
        Router::SKIPPED_NO_WORKFLOW => 'Skipped: no Smaily workflow is mapped to this automation trigger. Nothing was sent.',
        ContactSyncHandler::SKIPPED_STALE_SUBSCRIPTION => 'Skipped: the shopper has since changed their newsletter subscription, and the newer choice already reached Smaily. Nothing was sent.',
        ContactSyncHandler::SKIPPED_SYNC_OFF => 'Skipped: contact synchronization is off for this shop. Nothing was sent.',
        ContactSyncHandler::SKIPPED_NOT_IN_AUDIENCE => 'Skipped: the Contacts settings of this shop do not sync this contact. Nothing was sent.',
        ContactSyncHandler::SKIPPED_NO_ACCOUNT => 'Skipped: no Smaily account is saved for this shop and language. Nothing was sent.',
        ContactSyncHandler::SKIPPED_NO_CREDENTIALS => 'Skipped: the Smaily account for this shop and language has no subdomain, username or password. Nothing was sent.',
        Router::SKIPPED_AUTOMATION_OFF => 'Skipped: this automation is switched off for this shop. Nothing was sent.',
        Router::SKIPPED_UNKNOWN_TRIGGER => 'Skipped: this version of the module does not know this automation trigger. Nothing was sent.',
        AbandonedCartHandler::SKIPPED_TOO_OLD => 'Skipped: this cart was left more than 24 hours ago, and a reminder is never sent for a cart that old. Nothing was sent.',
        AbandonedCartHandler::SKIPPED_ALREADY_REMINDED => 'Skipped: the reminder of this cart was already sent or closed. Nothing was sent.',
        AbandonedCartHandler::SKIPPED_NOT_TRACKED => 'Skipped: this cart no longer waits for a reminder. Nothing was sent.',
        AbandonedCartSweeper::SKIPPED_PAYLOAD_FAILED => 'Skipped: the contents of this cart could not be read. Nothing was sent.',
        ContactSyncHandler::FAILED_MALFORMED_PAYLOAD => 'The data stored with this row is incomplete, so it cannot be sent.',
    ];

    /** The sentence of a reason code of an import job, where it differs from a queue row's. */
    public const JOB_REASONS = [
        ContactImport::REASON_NO_ACCOUNT => 'Some contacts were left out: no Smaily account is saved for their shop and language.',
        ContactImport::REASON_NO_CREDENTIALS => 'Some contacts were left out: the Smaily account for their shop and language has no subdomain, username or password.',
    ];

    private const CLASS_PATTERN = '/^(' . Failure::CLASS_REGEX . '):\s*/';

    /**
     * @param callable(string): string $translate the admin's translation of an EN string
     * @param bool $job whether the error is an import job's
     */
    public static function forDisplay(?string $lastError, callable $translate, bool $job = false): string
    {
        $message = (string) preg_replace(self::CLASS_PATTERN, '', trim((string) $lastError));
        $sentence = ($job ? self::JOB_REASONS[$message] ?? null : null) ?? self::REASONS[$message] ?? null;

        return $sentence !== null ? $translate($sentence) : $message;
    }

    /**
     * The failure class of a stored `last_error`, or '' when the failure was not parked at once.
     */
    public static function failureClass(?string $lastError): string
    {
        preg_match(self::CLASS_PATTERN, trim((string) $lastError), $matches);

        return $matches[1] ?? '';
    }

    /**
     * The reason codes that the admin reads with $term in them, because they show as a sentence:
     * the Last Error filter finds a row by the words its column shows.
     *
     * @param callable(string): string $translate
     *
     * @return list<string>
     */
    public static function codesShowing(string $term, callable $translate): array
    {
        $codes = [];
        foreach ($term === '' ? [] : [self::REASONS, self::JOB_REASONS] as $sentences) {
            foreach ($sentences as $code => $sentence) {
                if (mb_stripos($translate($sentence), $term) !== false) {
                    $codes[] = $code;
                }
            }
        }

        return array_values(array_unique($codes));
    }
}
