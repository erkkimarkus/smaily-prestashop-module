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

namespace PrestaShop\Module\SmailyForPrestaShop\Contacts\Import;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\AdminTime;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactAudience;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scope\Scope;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\ApiException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;

/**
 * The first import and the daily refresh of contacts (ARCHITECTURE 6.2): the existing contacts of
 * the consent mode's audience reach Smaily once per run.
 *
 * A run is a job of the job table for one shop, or for every shop (id_shop 0). The tick takes it in
 * pages of PAGE_SIZE records, one page after another until the deadline passes, and stores the
 * cursor and the counts after each page, so a run spans ticks, survives a stop and resumes where it
 * stood. Each record is weighed by the settings of its own shop: a shop whose contact sync is not
 * active sends nothing, and ContactAudience decides who is in the audience (inAudience() is the one
 * place that asks it). A contact goes through the account of its shop and language
 * (ScopeResolver), in the shape of SubscriberPayloadBuilder, and never with a subscription status:
 * an import passes no consent change, so it never re-subscribes anyone.
 *
 * Smaily refuses a whole request at once, so a page group that it refuses as invalid data (203)
 * goes again one contact per request. A permanent refusal counts the contacts as failed and the run
 * goes on; a temporary failure (a 5xx, a 429, the network) ends the run as failed before the page,
 * with where it was thrown as `last_error` (Failure::location()), and start() resumes it from there.
 * The counts, the cursor and `last_error` hold numbers, ids and reason codes only, never a contact.
 *
 * A contact that Smaily refuses alone in its request (its answer code, as 203) is a refused
 * contact: the counts keep how many (`refused`) and, for the Log, the first REFUSED_KEPT of them
 * as [source, id, reason] (`refused_contacts`): the record's ContactAudience source and its
 * customer or newsletter-block id, never its address (owner, 2026-11-26; Linear CON-433).
 */
final class ContactImport
{
    /** The first import, which the admin starts. */
    public const TYPE_IMPORT = 'contacts_import';

    /** The daily refresh, which the scheduler starts. */
    public const TYPE_REFRESH = 'contacts_refresh';

    public const TYPES = [self::TYPE_IMPORT, self::TYPE_REFRESH];

    public const PAGE_SIZE = 500;

    /** How many refused contacts a run keeps by id for the Log; the rest are counted. */
    public const REFUSED_KEPT = 50;

    /** No shop of the run has an active contact sync. */
    public const REASON_SYNC_OFF = 'contact_sync_off';

    /** An import that has not moved for this long is stalled (as Magento's, PRO-3915). */
    public const STALLED_SECONDS = 3600;

    public const REASON_NO_ACCOUNT = 'no_account';

    public const REASON_NO_CREDENTIALS = 'no_credentials';

    private JobStore $jobs;

    private ContactSource $source;

    private ScopeResolver $scopes;

    private ContactsSettings $settings;

    private SubscriberPayloadBuilder $builder;

    private Transport $transport;

    private int $pageSize;

    /** @var array<int, array{active: bool, audience: ContactAudience, fields: list<string>}> for one tick */
    private array $shops = [];

    /** @var array<string, Scope> for one tick */
    private array $scopeCache = [];

    public function __construct(JobStore $jobs, ContactSource $source, ScopeResolver $scopes, ContactsSettings $settings, SubscriberPayloadBuilder $builder, Transport $transport, int $pageSize = self::PAGE_SIZE)
    {
        $this->jobs = $jobs;
        $this->source = $source;
        $this->scopes = $scopes;
        $this->settings = $settings;
        $this->builder = $builder;
        $this->transport = $transport;
        $this->pageSize = $pageSize;
    }

    /**
     * Starts the first import of one shop (0 = every shop), or resumes the one that was stopped or
     * failed, from its cursor. An import that is queued or running is left as it is, unless it is
     * stalled: that one is cancelled and a fresh import starts from the first contact ("Run
     * again" on its card; Magento's JobManager::startIfIdle()). After a finished import, a start
     * imports again from the first contact. With no active contact sync in its shops, the import
     * is done at once with REASON_SYNC_OFF.
     *
     * @return int the job id
     */
    public function start(int $idShop = 0): int
    {
        $latest = $this->jobs->latest(self::TYPE_IMPORT, $idShop);
        if ($latest !== null && in_array($latest['status'], JobStore::ACTIVE, true)) {
            if (!self::isStalled($latest)) {
                return (int) $latest['id'];
            }
            $this->jobs->update(
                (int) $latest['id'],
                ['status' => JobStore::STATUS_CANCELLED, 'finished_at' => JobStore::now()],
                JobStore::ACTIVE
            );
            $latest = null;
        }
        if ($latest !== null && in_array($latest['status'], [JobStore::STATUS_CANCELLED, JobStore::STATUS_FAILED], true)) {
            $this->jobs->update((int) $latest['id'], ['status' => JobStore::STATUS_QUEUED, 'finished_at' => null, 'last_error' => null]);

            return (int) $latest['id'];
        }

        $this->shops = [];
        [$total, $active] = $this->total($idShop);
        if (!$active) {
            return $this->jobs->create(self::TYPE_IMPORT, $idShop, JobStore::STATUS_DONE, self::counts($total), self::REASON_SYNC_OFF);
        }

        return $this->jobs->create(self::TYPE_IMPORT, $idShop, $total > 0 ? JobStore::STATUS_QUEUED : JobStore::STATUS_DONE, self::counts($total));
    }

    /**
     * Stops the first import of one shop (0 = every shop) after the page it is sending; start()
     * resumes it.
     *
     * @return bool whether an import was queued or running
     */
    public function stop(int $idShop = 0): bool
    {
        $latest = $this->jobs->latest(self::TYPE_IMPORT, $idShop);
        if ($latest === null || !in_array($latest['status'], JobStore::ACTIVE, true)) {
            return false;
        }
        $this->jobs->update(
            (int) $latest['id'],
            ['status' => JobStore::STATUS_CANCELLED, 'finished_at' => JobStore::now()],
            JobStore::ACTIVE
        );

        return true;
    }

    /**
     * The newest run of a type for one shop (0 = every shop), or null when none ran. `status` is a
     * JobStore status; `stalled` whether nothing has moved a queued or running run for
     * STALLED_SECONDS; `total` is the audience when the run started; `skipped` counts the contacts
     * of the audience whose account is missing or has no credentials; `refused` those of `failed`
     * that Smaily refused one by one; the times are UTC, but `finished`, the end in the shop's time
     * zone as the employee's language writes it (Admin\AdminTime), for the import card ('' while
     * the run goes on).
     *
     * @return array{id: int, type: string, id_shop: int, status: string, stalled: bool, total: int, sent: int, failed: int, skipped: int, refused: int, started_at: ?string, updated_at: string, finished_at: ?string, finished: string, last_error: ?string}|null
     */
    public function progress(int $idShop = 0, string $type = self::TYPE_IMPORT): ?array
    {
        $job = $this->jobs->latest($type, $idShop);
        if ($job === null) {
            return null;
        }
        $counts = self::decodeCounts($job['counts']);

        return [
            'id' => (int) $job['id'],
            'type' => (string) $job['type'],
            'id_shop' => (int) $job['id_shop'],
            'status' => (string) $job['status'],
            'stalled' => self::isStalled($job),
            'total' => $counts['total'],
            'sent' => $counts['sent'],
            'failed' => $counts['failed'],
            'skipped' => $counts['skipped'],
            'refused' => $counts['refused'],
            'started_at' => $job['started_at'] === null ? null : (string) $job['started_at'],
            'updated_at' => (string) $job['updated_at'],
            'finished_at' => $job['finished_at'] === null ? null : (string) $job['finished_at'],
            'finished' => $job['finished_at'] === null ? '' : AdminTime::forEmployee()->ofUtc((string) $job['finished_at']),
            'last_error' => $job['last_error'] === null ? null : (string) $job['last_error'],
        ];
    }

    /**
     * How many contacts the first import of one shop (0 = every shop) sends in each consent mode,
     * by each shop's "include guests" (Magento's estimate on the import card, PRO-3582): the
     * counts of ContactSource::groups(), weighed by ContactAudience as the import weighs them.
     * Whether contact sync is active is not asked: the card says that on its own.
     *
     * @return array<string, int> by mode
     */
    public function estimate(int $idShop = 0): array
    {
        $groups = $this->source->groups($idShop);
        $estimate = [];
        foreach ([ContactSyncMode::SUBSCRIBERS, ContactSyncMode::ALL_CUSTOMERS, ContactSyncMode::CHECKOUT_OPTIN] as $mode) {
            $estimate[$mode] = 0;
            foreach ($groups as $group) {
                $guests = $this->settings->mode($group['id_shop'])->includesGuests();
                if (self::inAudience(new ContactAudience(new ContactSyncMode($mode, $guests)), $group)) {
                    $estimate[$mode] += $group['count'];
                }
            }
        }

        return $estimate;
    }

    /**
     * Whether nothing has moved a queued or running import for STALLED_SECONDS.
     *
     * @param array<string, mixed> $job
     */
    private static function isStalled(array $job): bool
    {
        return in_array($job['status'], JobStore::ACTIVE, true)
            && (int) strtotime($job['updated_at'] . ' UTC') <= time() - self::STALLED_SECONDS;
    }

    /**
     * Queues the daily refresh of every shop, unless an import or a refresh is still to run, or
     * the audience is empty.
     *
     * @return int|null the job id, or null when none was queued
     */
    public function scheduleRefresh(): ?int
    {
        if ($this->jobs->next(self::TYPES) !== null) {
            return null;
        }
        $this->shops = [];
        [$total, $active] = $this->total(0);
        if (!$active || $total === 0) {
            return null;
        }

        return $this->jobs->create(self::TYPE_REFRESH, 0, JobStore::STATUS_QUEUED, self::counts($total));
    }

    /**
     * Sends pages of the oldest runs that are still to run until the deadline passes or none is
     * left. A run takes at least one page per tick.
     */
    public function run(Deadline $deadline): void
    {
        $this->shops = [];
        $this->scopeCache = [];
        $taken = [];
        while (($job = $this->jobs->next(self::TYPES)) !== null && !isset($taken[(int) $job['id']])) {
            $taken[(int) $job['id']] = true;
            $this->runJob($job, $deadline);
            if ($deadline->passed()) {
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function runJob(array $job, Deadline $deadline): void
    {
        $id = (int) $job['id'];
        if ($job['status'] === JobStore::STATUS_QUEUED) {
            $this->jobs->update($id, ['status' => JobStore::STATUS_RUNNING, 'started_at' => $job['started_at'] ?? JobStore::now()], [JobStore::STATUS_QUEUED]);
        }
        $cursor = $job['cursor'] === null ? null : (string) $job['cursor'];
        $counts = self::decodeCounts($job['counts']);
        $refused = self::refusedContacts($job['counts']);

        do {
            $current = $this->jobs->find($id);
            if ($current === null || $current['status'] !== JobStore::STATUS_RUNNING) {
                return;
            }
            [$records, $next] = $this->source->page($cursor, (int) $job['id_shop'], $this->pageSize);
            if ($next === null) {
                $this->jobs->update($id, ['status' => JobStore::STATUS_DONE, 'finished_at' => JobStore::now()], [JobStore::STATUS_RUNNING]);

                return;
            }

            $outcome = $this->sendPage($records);
            if ($outcome['temporary'] !== null) {
                $this->jobs->update(
                    $id,
                    ['status' => JobStore::STATUS_FAILED, 'finished_at' => JobStore::now(), 'last_error' => self::cut($outcome['temporary'])],
                    [JobStore::STATUS_RUNNING]
                );

                return;
            }
            foreach (['sent', 'failed', 'skipped'] as $count) {
                $counts[$count] += $outcome[$count];
            }
            $counts['refused'] += count($outcome['refused']);
            $refused = array_slice(array_merge($refused, $outcome['refused']), 0, self::REFUSED_KEPT);
            $cursor = $next;
            $columns = ['cursor' => $cursor, 'counts' => $counts + ['refused_contacts' => $refused]];
            if ($outcome['reason'] !== null) {
                $columns['last_error'] = self::cut($outcome['reason']);
            }
            $this->jobs->update($id, $columns, [JobStore::STATUS_RUNNING]);
        } while (!$deadline->passed());
    }

    /**
     * Sends the records of a page that are in their shop's audience, one request per account.
     *
     * @param list<array<string, mixed>> $records
     *
     * @return array{sent: int, failed: int, skipped: int, refused: list<array{0: string, 1: int, 2: string}>, reason: ?string, temporary: ?string}
     */
    private function sendPage(array $records): array
    {
        $outcome = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'refused' => [], 'reason' => null, 'temporary' => null];
        /** @var array<string, array{client: Client, contacts: list<array<string, string|int>>, records: list<array{0: string, 1: int}>}> $groups */
        $groups = [];
        foreach ($records as $record) {
            $idShop = (int) $record['id_shop'];
            $shop = $this->shop($idShop);
            if (!$shop['active'] || !self::inAudience($shop['audience'], $record)) {
                continue;
            }
            $scope = $this->scopeCache[$idShop . ':' . $record['id_lang']] ??= $this->scopes->resolve($idShop, (int) $record['id_lang']);
            $account = $scope->account();
            if ($account === null || !$account->hasCredentials()) {
                ++$outcome['skipped'];
                $outcome['reason'] = $account === null ? self::REASON_NO_ACCOUNT : self::REASON_NO_CREDENTIALS;
                continue;
            }

            $key = $account->idShop() . ':' . $account->language();
            $groups[$key]['client'] ??= new Client($this->transport, $account->subdomain(), $account->username(), $account->password());
            $groups[$key]['contacts'][] = $this->builder->build(
                (string) $record['email'],
                $idShop,
                $scope->language(),
                $shop['fields'],
                $record['customer'],
                $shop['audience']->unsubscribedStatus((bool) $record['newsletter'], false)
            );
            $groups[$key]['records'][] = [(string) $record['source'], (int) $record['id']];
        }

        foreach ($groups as $group) {
            [$sent, $failure, $refused] = $this->send($group['client'], $group['contacts'], $group['records']);
            if ($failure !== null && !$failure->isPermanent()) {
                $outcome['temporary'] = $failure->reason();

                return $outcome;
            }
            $outcome['sent'] += $sent;
            $outcome['failed'] += count($group['contacts']) - $sent;
            $outcome['refused'] = array_merge($outcome['refused'], $refused);
            if ($failure !== null) {
                $outcome['reason'] = $failure->reason();
            }
        }

        return $outcome;
    }

    /**
     * Posts contacts in one request; a group refused as invalid data goes again one by one. A
     * contact that Smaily refuses alone is a refused contact: [source, id, reason] of its record.
     *
     * @param list<array<string, string|int>> $contacts
     * @param list<array{0: string, 1: int}> $records each contact's source and id
     *
     * @return array{0: int, 1: ?Failure, 2: list<array{0: string, 1: int, 2: string}>} how many Smaily took, the last failure, and the refused contacts
     */
    private function send(Client $client, array $contacts, array $records): array
    {
        try {
            $client->post(Client::ENDPOINT_CONTACT, $contacts);

            return [count($contacts), null, []];
        } catch (ApiException $error) {
            if (count($contacts) > 1 && $error->getSmailyCode() === ApiException::CODE_INVALID_DATA) {
                $sent = 0;
                $last = null;
                $refused = [];
                foreach ($contacts as $index => $contact) {
                    [$one, $failure, $alone] = $this->send($client, [$contact], [$records[$index]]);
                    if ($failure !== null && !$failure->isPermanent()) {
                        return [$sent, $failure, $refused];
                    }
                    $sent += $one;
                    $last = $failure ?? $last;
                    $refused = array_merge($refused, $alone);
                }

                return [$sent, $last, $refused];
            }
            $failure = self::failure($error);

            return [0, $failure, count($contacts) === 1 && $failure->isPermanent() ? [[$records[0][0], $records[0][1], $failure->reason()]] : []];
        } catch (SmailyClientException $error) {
            return [0, self::failure($error), []];
        }
    }

    /**
     * A failed request's outcome (Failure::of()). A failure that stops the run keeps where it was
     * thrown (Failure::location()) as its reason, never the client's sentence, as Magento's stopped
     * import keeps its ErrorLocation (CON-233): the card shows it in the admin's language.
     */
    private static function failure(SmailyClientException $error): Failure
    {
        $failure = Failure::of($error);

        return $failure->isPermanent() ? $failure : Failure::temporary(Failure::location($error));
    }

    /**
     * Whether a record (or a group of records of one kind) is in the audience: the one place that
     * asks ContactAudience about an import.
     *
     * @param array<string, mixed> $record with `source`, `newsletter` and `is_guest`
     */
    private static function inAudience(ContactAudience $audience, array $record): bool
    {
        return $record['source'] === ContactAudience::SOURCE_CUSTOMER
            ? $audience->importsCustomer((bool) $record['newsletter'], (bool) $record['is_guest'])
            : $audience->importsNewsletterGuests();
    }

    /**
     * How many records of one shop (0 = every shop) are in the audience, and whether any of its
     * shops has an active contact sync.
     *
     * @return array{0: int, 1: bool}
     */
    private function total(int $idShop): array
    {
        $total = 0;
        foreach ($this->source->groups($idShop) as $group) {
            $shop = $this->shop($group['id_shop']);
            if ($shop['active'] && self::inAudience($shop['audience'], $group)) {
                $total += $group['count'];
            }
        }
        $active = false;
        foreach ($idShop > 0 ? [$idShop] : self::shopIds() as $shopId) {
            $active = $active || $this->shop($shopId)['active'];
        }

        return [$total, $active];
    }

    /**
     * @return array{active: bool, audience: ContactAudience, fields: list<string>}
     */
    private function shop(int $idShop): array
    {
        return $this->shops[$idShop] ??= [
            'active' => $this->settings->syncActive($idShop),
            'audience' => new ContactAudience($this->settings->mode($idShop)),
            'fields' => $this->settings->fields($idShop),
        ];
    }

    /**
     * @return list<int>
     */
    private static function shopIds(): array
    {
        $rows = Sql::rows('SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'shop` WHERE `deleted` = 0');

        return array_map('intval', array_column($rows, 'id_shop'));
    }

    /**
     * @return array{total: int, sent: int, failed: int, skipped: int, refused: int}
     */
    private static function counts(int $total): array
    {
        return ['total' => $total, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'refused' => 0];
    }

    /**
     * The refused contacts that a job's counts keep for the Log: [source, id, reason] each.
     *
     * @param mixed $json
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
    public static function refusedContacts($json): array
    {
        $counts = is_string($json) ? json_decode($json, true) : null;
        $refused = [];
        foreach ((array) ($counts['refused_contacts'] ?? []) as $entry) {
            if (is_array($entry) && count($entry) === 3 && in_array($entry[0], ContactAudience::SOURCES, true)) {
                $refused[] = [(string) $entry[0], (int) $entry[1], (string) $entry[2]];
            }
        }

        return $refused;
    }

    /**
     * @param mixed $json
     *
     * @return array{total: int, sent: int, failed: int, skipped: int, refused: int}
     */
    private static function decodeCounts($json): array
    {
        $counts = is_string($json) ? json_decode($json, true) : null;
        $counts = is_array($counts) ? $counts : [];

        return array_map(static fn ($value): int => (int) $value, array_intersect_key($counts, self::counts(0)) + self::counts(0));
    }

    private static function cut(string $reason): string
    {
        return substr($reason, 0, 255);
    }
}
