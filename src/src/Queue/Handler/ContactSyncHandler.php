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

namespace PrestaShop\Module\SmailyForPrestaShop\Queue\Handler;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactAudience;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ExchangeSource;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\HandlerInterface;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Scope\Scope;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\ApiException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;

/**
 * Sends `contact.sync` rows to Smaily's `contact.php`: the rows of a flush batch go in one request
 * per Smaily account, through the account of each row's shop and language (ScopeResolver).
 *
 * The payload of a row records what the store saw; the consent mode, the fields and the account
 * are read when the row is sent:
 *   {"email": string, "source": "customer"|"newsletter_block", "subscribed": bool,
 *    "consent_changed": bool, "checkout": bool, "guest": bool, "id_lang": int,
 *    "customer": {"firstname", "lastname", "birthday", "website", "id_customer", "id_gender",
 *    "id_default_group"}}
 * (ContactAudience::syncs() describes each value; `guest` is true for a guest-checkout customer;
 * `checkout`, `guest`, `id_lang` and `customer` may be left out.)
 *
 * Smaily answers one code for a whole request, so a group that it refuses as invalid data (203) goes
 * again one contact per request, and only the refused contact fails (Magento PRO-3753). Any other
 * failure of the wire is the outcome of every row of the group. A row that cannot go ends skipped
 * with a reason code: its shop's contact sync is off, the contact is outside the shop's audience,
 * a newer change of the same contact already reached Smaily (Contacts\NewerChange, asked at every
 * attempt, so a late or retried row never undoes a newer delivered change; owner, 2026-11-26), or
 * its account is missing or has no credentials. A reason never names the contact.
 *
 * exchanges() gives each sent row its own contact of the request body, never the contacts posted
 * with it, and the answer to that request.
 */
final class ContactSyncHandler implements HandlerInterface, ExchangeSource
{
    public const EVENT_TYPE = 'contact.sync';

    public const SKIPPED_SYNC_OFF = 'contact_sync_off';

    public const SKIPPED_NOT_IN_AUDIENCE = 'not_in_audience';

    public const SKIPPED_NEWER_CHANGE = 'newer_change_sent';

    public const SKIPPED_NO_ACCOUNT = 'no_account';

    public const SKIPPED_NO_CREDENTIALS = 'no_credentials';

    public const FAILED_MALFORMED_PAYLOAD = 'malformed_payload';

    private ScopeResolver $scopes;

    private ContactsSettings $settings;

    private SubscriberPayloadBuilder $builder;

    private Transport $transport;

    /** @var callable(array<int, array<string, mixed>>): list<int> */
    private $overtaken;

    /** @var array<int, array{request: array<int|string, mixed>, response: array{http_status: int, body: mixed}|null}> */
    private array $exchanges = [];

    /**
     * @param callable(array<int, array<string, mixed>>): list<int> $overtaken the ids of the rows
     *                                                                         that a newer delivered
     *                                                                         change overtook, among
     *                                                                         queue rows by id
     *                                                                         (NewerChange::among())
     */
    public function __construct(ScopeResolver $scopes, ContactsSettings $settings, SubscriberPayloadBuilder $builder, Transport $transport, callable $overtaken)
    {
        $this->scopes = $scopes;
        $this->settings = $settings;
        $this->builder = $builder;
        $this->transport = $transport;
        $this->overtaken = $overtaken;
    }

    public function handle(array $events): array
    {
        $this->exchanges = [];
        $overtaken = array_flip(($this->overtaken)(array_column($events, null, 'id')));
        $outcomes = [];
        /** @var array<string, array{client: Client, contacts: array<int, array<string, string|int>>}> $groups */
        $groups = [];
        /** @var array<string, Scope> $scopes */
        $scopes = [];
        foreach ($events as $event) {
            $id = (int) $event['id'];
            $idShop = (int) $event['id_shop'];
            $payload = self::decode((string) $event['payload']);
            if ($payload === null) {
                $outcomes[$id] = Failure::permanent(self::FAILED_MALFORMED_PAYLOAD);
                continue;
            }
            if (!$this->settings->syncActive($idShop)) {
                $outcomes[$id] = new Skipped(self::SKIPPED_SYNC_OFF);
                continue;
            }
            $audience = new ContactAudience($this->settings->mode($idShop));
            if (!$audience->syncs($payload['source'], $payload['subscribed'], $payload['consent_changed'], $payload['checkout'], $payload['guest'])) {
                $outcomes[$id] = new Skipped(self::SKIPPED_NOT_IN_AUDIENCE);
                continue;
            }
            if (isset($overtaken[$id])) {
                $outcomes[$id] = new Skipped(self::SKIPPED_NEWER_CHANGE);
                continue;
            }
            $scope = $scopes[$idShop . ':' . $payload['id_lang']] ??= $this->scopes->resolve($idShop, $payload['id_lang']);
            $account = $scope->account();
            if ($account === null) {
                $outcomes[$id] = new Skipped(self::SKIPPED_NO_ACCOUNT);
                continue;
            }
            if (!$account->hasCredentials()) {
                $outcomes[$id] = new Skipped(self::SKIPPED_NO_CREDENTIALS);
                continue;
            }

            $key = $account->idShop() . ':' . $account->language();
            $groups[$key]['client'] ??= new Client($this->transport, $account->subdomain(), $account->username(), $account->password());
            $groups[$key]['contacts'][$id] = $this->builder->build(
                $payload['email'],
                $idShop,
                $scope->language(),
                $this->settings->fields($idShop),
                $payload['customer'],
                $audience->unsubscribedStatus($payload['subscribed'], $payload['consent_changed'])
            );
        }

        foreach ($groups as $group) {
            $outcomes += $this->send($group['client'], $group['contacts']);
        }

        return $outcomes;
    }

    public function exchanges(): array
    {
        return $this->exchanges;
    }

    /**
     * @param array<int, array<string, string|int>> $contacts by row id
     *
     * @return array<int, true|Failure> by row id
     */
    private function send(Client $client, array $contacts): array
    {
        $outcome = true;
        try {
            $client->post(Client::ENDPOINT_CONTACT, array_values($contacts));
        } catch (ApiException $error) {
            if (count($contacts) > 1 && $error->getSmailyCode() === ApiException::CODE_INVALID_DATA) {
                $outcomes = [];
                foreach ($contacts as $id => $contact) {
                    $outcomes += $this->send($client, [$id => $contact]);
                }

                return $outcomes;
            }
            $outcome = Failure::of($error);
        } catch (SmailyClientException $error) {
            $outcome = Failure::of($error);
        }
        $exchange = $client->lastExchange();
        if ($exchange !== null) {
            foreach ($contacts as $id => $contact) {
                $this->exchanges[$id] = ['request' => [$contact], 'response' => $exchange['response']];
            }
        }

        return array_fill_keys(array_keys($contacts), $outcome);
    }

    /**
     * The payload of a row, or null when it is not one.
     *
     * @return array{email: string, source: string, subscribed: bool, consent_changed: bool, checkout: bool, guest: bool, id_lang: int, customer: array<string, mixed>}|null
     */
    private static function decode(string $json): ?array
    {
        $payload = json_decode($json, true);
        if (!is_array($payload)
            || !is_string($payload['email'] ?? null) || trim($payload['email']) === ''
            || !in_array($payload['source'] ?? null, ContactAudience::SOURCES, true)
            || !is_bool($payload['subscribed'] ?? null)
            || !is_bool($payload['consent_changed'] ?? null)
            || !is_bool($payload['checkout'] ?? false)
            || !is_bool($payload['guest'] ?? false)
            || !is_int($payload['id_lang'] ?? 0)
            || !is_array($payload['customer'] ?? [])
        ) {
            return null;
        }

        return [
            'email' => $payload['email'],
            'source' => $payload['source'],
            'subscribed' => $payload['subscribed'],
            'consent_changed' => $payload['consent_changed'],
            'checkout' => $payload['checkout'] ?? false,
            'guest' => $payload['guest'] ?? false,
            'id_lang' => $payload['id_lang'] ?? 0,
            'customer' => $payload['customer'] ?? [],
        ];
    }
}
