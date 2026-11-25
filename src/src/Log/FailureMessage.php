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
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\History\HistoryJobs;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\CatalogManifest;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IdentityMergeHandler;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestFlusher;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\EngineFailure;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingConsentHandler;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Flusher;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client as SmailyClient;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\ApiException;

/**
 * What a row's stored `last_error` says to the merchant, as the Magento connector's Log words it.
 *
 * A refusal that was parked at once is stored as `permanent_http_<code>: <message>` or
 * `permanent_envelope_<code>: <message>` (Queue\Failure): the list and Details show the message,
 * and Details show the class beside it. A skip, and a handler's own verdict that a row can never
 * be sent, is stored as a reason code; every code that a handler or an import can store shows as a
 * sentence, in the admin's language: Magento's sentence where Magento has the reason, else one of
 * the same form. A client's or the queue's own message is stored as English source text with its
 * values (the HTTP status, a code) filled in, whatever the admin's language, and shows translated
 * with those values (MESSAGES), as Magento's Log shows it. A Campaign Intelligence row stores the
 * error code of Engine\Client, or of the engine's answer, and shows a sentence for it
 * (ENGINE_CODES), with the HTTP status or the code where it has none; never the engine's text.
 * Any other text shows as stored.
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
        ProfilingConsentHandler::SKIPPED_REPLACED => 'Skipped: the shopper has since changed their personalization preference, and the newer preference is sent in its own row. Nothing was sent.',
        EngineFailure::SKIPPED_NO_CONNECTION => 'Skipped: the Campaign Intelligence connection of this row no longer exists. Nothing was sent.',
        // Campaign Intelligence: the ingest queue and the identity merge.
        IngestFlusher::SKIPPED_PRODUCT_GONE => 'Skipped: this product is no longer in the shop. Nothing was sent.',
        IngestFlusher::SKIPPED_PRODUCT_EXISTS => 'Skipped: this product is still in the shop, so it was not removed from Campaign Intelligence. Nothing was sent.',
        IngestFlusher::SKIPPED_CUSTOMER_GONE => 'Skipped: this customer is no longer in the store. Nothing was sent.',
        IngestFlusher::SKIPPED_NOT_IN_SCOPE => 'Skipped: this customer is not among the customers that the store sends to Campaign Intelligence (in the contact sync, with an order in the history period, or signed in with marketing consent). Nothing was sent.',
        IngestFlusher::SKIPPED_ORDER_GONE => 'Skipped: this order, or its customer, is no longer in the store. Nothing was sent.',
        IngestFlusher::FAILED_ITEM_REFUSED => 'Campaign Intelligence refused this row. The response shows the field it refused.',
        CatalogManifest::FAILED_TOO_MANY => 'Not sent: the store has more than 50,000 product rows, the most the nightly product list can hold.',
        IdentityMergeHandler::SKIPPED_OPTED_OUT => 'Skipped: the shopper opted out of personalized recommendations, so their browsing is not linked to their address. Nothing was sent.',
        IdentityMergeHandler::SKIPPED_NOTHING_TO_SEND => 'Skipped: the shopper withdrew the marketing consent, and the visit has no visitor token. Nothing was sent.',
        IdentityMergeHandler::FAILED_NOT_SENT => 'Campaign Intelligence does not have this customer yet, so the login was not linked to them. The store sends a customer who is in your contact sync, has an order or logs in with marketing consent; Retry links the login after that.',
        IdentityMergeHandler::FAILED_CUSTOMER_REFUSED => 'Campaign Intelligence refused the customer, so the login was not linked to them. Retry sends the customer and the login again.',
        IdentityMergeHandler::FAILED_NOT_FOUND => 'Campaign Intelligence did not find this customer even after the store sent them, so the login was not linked to them. Retry sends the customer and the login again.',
    ];

    /** The sentence of a reason code of an import job, where it differs from a queue row's. */
    public const JOB_REASONS = [
        ContactImport::REASON_NO_ACCOUNT => 'Some contacts were left out: no Smaily account is saved for their shop and language.',
        ContactImport::REASON_NO_CREDENTIALS => 'Some contacts were left out: the Smaily account for their shop and language has no subdomain, username or password.',
        // The imports to Campaign Intelligence.
        HistoryJobs::REASON_STALLED => 'Stalled: nothing moved this import for over an hour. Press Run again in Settings > Intelligence to start a fresh import. If it stalls again, check that the background tasks run.',
        HistoryJobs::REASON_NOT_ACTIVE => 'Cancelled: Campaign Intelligence does not accept the connection of this store, so there was nowhere to send the data. Start the import again in Settings > Intelligence once the connection works.',
        HistoryJobs::REASON_NO_CONNECTION => 'Cancelled: the Campaign Intelligence connection of this import no longer exists.',
    ];

    /**
     * The sentence of each error code that a Campaign Intelligence row stores (Engine\Client): the
     * client's own codes and the codes of the contract's error answers to an ingest request or an
     * identity merge. A request that may pass later stores the code alone, a refusal stores it
     * after its failure class (`permanent_http_<status>: <code>`). Another code shows as ENGINE_HTTP
     * when it is `http_<status>`, else as ENGINE_REFUSED or ENGINE_FAILED with the code.
     */
    public const ENGINE_CODES = [
        Client::NETWORK_ERROR => 'Campaign Intelligence did not answer: the store could not connect to it, or no answer came in time.',
        Client::MALFORMED_ANSWER => 'Campaign Intelligence returned a malformed answer.',
        Client::HOST_NOT_ALLOWED => 'The address of this request is not an address of Campaign Intelligence, so nothing was sent.',
        Client::KEY_UNREADABLE => 'The API key of this Campaign Intelligence connection cannot be read, so nothing was sent. Connect again under Settings > Intelligence.',
        'unauthorised' => 'Campaign Intelligence did not accept this store\'s connection. Connect again under Settings > Intelligence.',
        'tenant_inactive' => 'This store is no longer active in Campaign Intelligence.',
        'plugin_update_required' => 'Campaign Intelligence needs a newer version of Smaily Connect. Update the module.',
        'validation_failed' => 'Campaign Intelligence refused the data of this row as invalid.',
        'invalid_json' => 'Campaign Intelligence could not read the request.',
        'rate_limit_exceeded' => 'Campaign Intelligence asked the store to send more slowly for a while.',
        'customer_not_found' => 'Campaign Intelligence does not have this customer.',
    ];

    /** A Campaign Intelligence row's `http_<status>`: the engine's answer named no code. */
    public const ENGINE_HTTP = 'Campaign Intelligence answered with an error (HTTP %status%).';

    /** A Campaign Intelligence refusal with a code that ENGINE_CODES does not hold. */
    public const ENGINE_REFUSED = 'Campaign Intelligence refused the request (%code%).';

    /**
     * A Campaign Intelligence request with a code that ENGINE_CODES does not hold that may pass
     * later: the sentence that EngineException stores for such a failure.
     */
    public const ENGINE_FAILED = EngineException::MESSAGE_FAILED;

    /**
     * The messages that a row stores with values in them, as their English source: a stored text is
     * read as the first source whose wording it has, each placeholder standing for a value. They are
     * the MESSAGE constants of Smaily\Client, Smaily\Exception\ApiException,
     * Engine\Exception\EngineException (as Privacy\EngineFailure stores it) and Queue\Flusher,
     * which build the thrown text from them, and the messages of InvalidSubdomainException and
     * Queue\Failure::of(); a message added there that a row can store is listed here too, or it
     * shows in English.
     */
    public const MESSAGES = [
        SmailyClient::MESSAGE_CREDENTIALS_REJECTED,
        SmailyClient::MESSAGE_PLAN_BLOCKED,
        SmailyClient::MESSAGE_HTTP_STATUS,
        SmailyClient::MESSAGE_NO_ANSWER,
        SmailyClient::MESSAGE_NOT_CONNECTED,
        // Stored before the two above, with the HTTP client's own text; after them, as their text
        // has its wording too.
        'Smaily API request failed: %error%',
        SmailyClient::MESSAGE_MALFORMED_BODY,
        ApiException::MESSAGE,
        'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.',
        EngineException::MESSAGE_ANSWERED,
        self::ENGINE_FAILED,
        Flusher::MESSAGE_NO_HANDLER,
        Flusher::MESSAGE_NO_OUTCOME,
        // Any other error (Queue\Failure::of()); last, as its wording is the loosest.
        '%class% at %file%:%line% (code %code%)',
    ];

    private const CLASS_PATTERN = '/^(' . Failure::CLASS_REGEX . '):\s*/';

    private const PLACEHOLDER = '/%[a-z]+%/';

    /**
     * Each of MESSAGES with the pattern that reads a stored text as it and its placeholders' names,
     * built once in a request.
     *
     * @var list<array{string, string, list<string>}>|null
     */
    private static ?array $readings = null;

    /**
     * @param callable(string, array<string, string>=): string $translate the admin's translation of
     *                                                                    an EN string, with its values
     * @param bool $job whether the error is an import job's
     * @param bool $intelligence whether the row goes to Campaign Intelligence (LogRows::sourceOf())
     */
    public static function forDisplay(?string $lastError, callable $translate, bool $job = false, bool $intelligence = false): string
    {
        $message = (string) preg_replace(self::CLASS_PATTERN, '', trim((string) $lastError));
        if ($message === '') {
            return '';
        }
        $sentence = ($job ? self::JOB_REASONS[$message] ?? null : null) ?? self::REASONS[$message] ?? null;
        if ($sentence !== null) {
            return $translate($sentence);
        }
        if ($intelligence && preg_match(EngineException::CODE_PATTERN, $message) === 1) {
            return self::engineCode($message, self::failureClass($lastError) !== '', $translate);
        }
        foreach (self::readings() as [$source, $pattern, $names]) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return $translate($source, array_combine($names, array_slice($matches, 1)));
            }
        }

        return $message;
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
     * The SQL LIKE patterns of the stored texts that the admin reads with $term in them, because
     * they show as a sentence or translated: the Last Error filter finds a row by the words its
     * column shows. A reason code's pattern is the code; an engine code's is the code, alone or
     * after a failure class; a message's takes any values and a failure class before it. A term is
     * looked for in a message's own wording, not in its values. The sentence of an engine code
     * that ENGINE_CODES does not hold is found by the code only.
     *
     * @param callable(string): string $translate
     *
     * @return list<string>
     */
    public static function patternsShowing(string $term, callable $translate): array
    {
        if ($term === '') {
            return [];
        }
        $patterns = [];
        foreach ([self::REASONS, self::JOB_REASONS] as $sentences) {
            foreach ($sentences as $code => $sentence) {
                if (mb_stripos($translate($sentence), $term) !== false) {
                    $patterns[] = self::likeText($code);
                }
            }
        }
        foreach (self::ENGINE_CODES + ['http_' => self::ENGINE_HTTP] as $code => $sentence) {
            if (mb_stripos(self::wording($translate($sentence)), $term) !== false) {
                $stored = self::likeText($code) . ($code === 'http_' ? '%' : '');
                array_push($patterns, $stored, '%: ' . $stored);
            }
        }
        foreach (self::MESSAGES as $source) {
            foreach ((array) preg_split(self::PLACEHOLDER, $translate($source)) as $wording) {
                if (mb_stripos((string) $wording, $term) !== false) {
                    $parts = (array) preg_split(self::PLACEHOLDER, $source);
                    $patterns[] = '%' . implode('%', array_map(static fn ($part): string => self::likeText((string) $part), $parts));
                    break;
                }
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * The sentence of a Campaign Intelligence row's error code.
     *
     * @param bool $refused whether the row was parked at once as a refusal
     */
    private static function engineCode(string $code, bool $refused, callable $translate): string
    {
        if (isset(self::ENGINE_CODES[$code])) {
            return $translate(self::ENGINE_CODES[$code]);
        }
        if (preg_match('/^http_([0-9]{3})$/D', $code, $status) === 1) {
            return $translate(self::ENGINE_HTTP, ['%status%' => $status[1]]);
        }

        return $translate($refused ? self::ENGINE_REFUSED : self::ENGINE_FAILED, ['%code%' => $code]);
    }

    /**
     * A sentence's own words, without the placeholders.
     */
    private static function wording(string $sentence): string
    {
        return (string) preg_replace(self::PLACEHOLDER, '', $sentence);
    }

    /**
     * Each of MESSAGES, the pattern that a stored text worded as it matches, each placeholder
     * caught as the value it stands for, and the placeholders' names in order.
     *
     * @return list<array{string, string, list<string>}>
     */
    private static function readings(): array
    {
        if (self::$readings === null) {
            self::$readings = [];
            foreach (self::MESSAGES as $source) {
                preg_match_all(self::PLACEHOLDER, $source, $names);
                self::$readings[] = [$source, '/^' . preg_replace(self::PLACEHOLDER, '(.*?)', preg_quote($source, '/')) . '$/su', $names[0]];
            }
        }

        return self::$readings;
    }

    private static function likeText(string $text): string
    {
        return addcslashes($text, '%_\\');
    }
}
