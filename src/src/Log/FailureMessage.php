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

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartSweeper;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MarketingConsent;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;

/**
 * What a row's stored `last_error` says to the merchant, as the Magento connector's Log words it.
 *
 * A refusal that was parked at once is stored as `permanent_http_<code>: <message>` or
 * `permanent_envelope_<code>: <message>` (Queue\Failure): the list and Details show the message,
 * and Details show the class beside it. A skip is stored as a reason code; the codes of REASONS
 * show as Magento's sentence, in the admin's language, and any other code shows as stored. Magento
 * has no stale contact sync; that sentence follows its sentence for a replaced profiling choice.
 */
final class FailureMessage
{
    /** The sentence of each skip reason that has one, by reason code. */
    public const REASONS = [
        MarketingConsent::SKIPPED_NO_MARKETING_CONSENT => 'Skipped: this shopper has not agreed to marketing emails in the store, and is not a subscribed contact in Smaily. Nothing was sent.',
        MarketingConsent::SKIPPED_UNSUBSCRIBED_IN_SMAILY => 'Skipped: this shopper unsubscribed in Smaily. Nothing was sent.',
        AbandonedCartSweeper::SKIPPED_RECENTLY_REMINDED => 'Skipped: this address already got an abandoned-cart reminder for another cart in the last 24 hours. Nothing was sent.',
        Router::SKIPPED_NO_WORKFLOW => 'Skipped: no Smaily workflow is mapped to this automation trigger. Nothing was sent.',
        ContactSyncHandler::SKIPPED_STALE_SUBSCRIPTION => 'Skipped: the shopper has since changed their newsletter subscription, and the newer choice already reached Smaily. Nothing was sent.',
    ];

    private const CLASS_PATTERN = '/^(' . Failure::CLASS_REGEX . '):\s*/';

    /**
     * @param callable(string): string $translate the admin's translation of an EN string
     */
    public static function forDisplay(?string $lastError, callable $translate): string
    {
        $message = (string) preg_replace(self::CLASS_PATTERN, '', trim((string) $lastError));

        return isset(self::REASONS[$message]) ? $translate(self::REASONS[$message]) : $message;
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
        foreach ($term === '' ? [] : self::REASONS as $code => $sentence) {
            if (mb_stripos($translate($sentence), $term) !== false) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
