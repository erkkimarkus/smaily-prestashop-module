<?php
declare(strict_types=1);

namespace Tests\Integration\Log;

use PrestaShop\Module\SmailyForPrestaShop\Log\LogFilter;

require_once __DIR__ . '/LogTestCase.php';

/**
 * The Log's list in the shop's database: the rows of both queues and the import jobs together,
 * newest first, filtered by source, type, entity, status, last error and the two day ranges, in
 * pages, and narrowed to the selected shops.
 */
final class LogRowsTest extends LogTestCase
{
    public function testTheListMergesBothQueuesAndTheJobsNewestFirstInPages(): void
    {
        $oldest = self::event(['created_at' => '2026-11-20 10:00:00']);
        $ingest = self::ingest(['created_at' => '2026-11-20 10:01:00']);
        $job = self::job(['started_at' => '2026-11-20 10:02:00', 'updated_at' => '2026-11-20 10:09:00']);
        $newest = self::event(['event_type' => 'automation.welcome', 'created_at' => '2026-11-20 10:03:00']);
        $waitingJob = self::job(['status' => 'queued', 'started_at' => null, 'updated_at' => '2026-11-20 09:00:00']);

        $rows = self::rows();
        $all = $rows->page(new LogFilter(), 0, 20);

        self::assertSame(
            ['smaily-' . $newest, 'job-' . $job, 'intelligence-' . $ingest, 'smaily-' . $oldest, 'job-' . $waitingJob],
            self::logIds($all)
        );
        self::assertSame(['smaily', 'smaily', 'intelligence', 'smaily', 'smaily'], array_column($all, 'source'));
        self::assertSame(['automation.welcome', 'contacts_import', 'catalog', 'contact.sync', 'contacts_import'], array_column($all, 'type'));
        self::assertSame(['shopper1@example.test', null, 'ps-12', 'shopper1@example.test', null], array_column($all, 'entity'));
        self::assertSame('2026-11-20 09:00:00', $all[4]['created_at'], 'a job that has not started is dated by its last change');
        self::assertSame(5, $rows->count(new LogFilter()));

        self::assertSame(['smaily-' . $newest, 'job-' . $job], self::logIds($rows->page(new LogFilter(), 0, 2)));
        self::assertSame(['intelligence-' . $ingest, 'smaily-' . $oldest], self::logIds($rows->page(new LogFilter(), 2, 2)));
        self::assertSame(['job-' . $waitingJob], self::logIds($rows->page(new LogFilter(), 4, 2)));
    }

    public function testAnEmptyLogListsNothing(): void
    {
        self::assertSame([], self::rows()->page(new LogFilter(), 0, 20));
        self::assertSame(0, self::rows()->count(new LogFilter()));
        self::assertSame(0, self::rows()->failedSince(0));
    }

    public function testEachFilterNarrowsTheList(): void
    {
        $sync = self::event(['created_at' => '2026-11-18 23:30:00', 'updated_at' => '2026-11-20 08:00:00']);
        $welcome = self::event(['event_type' => 'automation.welcome', 'entity_id' => 'shopper2@example.test', 'status' => 'failed', 'attempts' => 1,
            'last_error' => 'permanent_http_401: Smaily API credentials were rejected', 'created_at' => '2026-11-19 12:00:00', ]);
        $claimed = self::event(['status' => 'processing', 'created_at' => '2026-11-19 12:01:00']);
        $waiting = self::event(['status' => 'pending', 'created_at' => '2026-11-19 12:02:00']);
        $skipped = self::event(['event_type' => 'automation.abandoned_cart', 'status' => 'skipped', 'last_error' => 'no_marketing_consent', 'created_at' => '2026-11-19 12:03:00']);
        $withdrawn = self::event(['event_type' => 'automation.abandoned_cart', 'status' => 'skipped', 'last_error' => 'ordered', 'created_at' => '2026-11-19 12:04:00']);
        $ingest = self::ingest(['status' => 'failed', 'last_error' => 'Engine request failed with HTTP 500', 'created_at' => '2026-11-19 12:05:00']);
        $job = self::job(['started_at' => '2026-11-19 12:06:00']);
        $engineJob = self::job(['type' => 'catalog_import', 'id_connection' => 1, 'status' => 'failed', 'last_error' => 'x', 'started_at' => '2026-11-19 12:07:00']);

        $match = static fn (array $query): array => self::logIds(self::rows()->page(new LogFilter($query), 0, 50));
        $smaily = static fn (int ...$ids): array => array_map(static fn (int $id): string => 'smaily-' . $id, $ids);

        self::assertSame(['job-' . $job, ...$smaily($withdrawn, $skipped, $waiting, $claimed, $welcome, $sync)], $match(['source' => 'smaily']));
        self::assertSame(['job-' . $engineJob, 'intelligence-' . $ingest], $match(['source' => 'intelligence']));

        self::assertSame($smaily($withdrawn, $skipped, $welcome), $match(['type' => 'automation']));
        self::assertSame(['job-' . $engineJob, 'job-' . $job], $match(['type' => '_import']));
        self::assertSame($smaily($welcome), $match(['entity' => 'shopper2@']));
        self::assertSame($smaily($welcome), $match(['entity' => 'shopper2@', 'source' => 'smaily']), 'one table alone');
        self::assertSame(['intelligence-' . $ingest], $match(['entity' => 'ps-1']), 'an entity filter leaves the jobs out');
        self::assertSame([], $match(['entity' => '%']), 'a wildcard is searched as text');

        self::assertSame($smaily($waiting), $match(['status' => 'pending']));
        self::assertSame($smaily($claimed), $match(['status' => 'sending']));
        self::assertSame($smaily($sync), $match(['status' => 'sent']));
        self::assertSame(['job-' . $engineJob, 'intelligence-' . $ingest, 'smaily-' . $welcome], $match(['status' => 'failed']));
        self::assertSame($smaily($withdrawn), $match(['status' => 'withdrawn']));
        self::assertSame($smaily($skipped), $match(['status' => 'skipped']));
        self::assertSame(['job-' . $job], $match(['status' => 'done']));
        self::assertSame([], $match(['status' => 'cancelled']));

        self::assertSame($smaily($welcome), $match(['last_error' => 'credentials were rejected']));
        self::assertSame($smaily($skipped), $match(['last_error' => 'marketing emails']), 'a reason is found by the words the list shows');
        self::assertSame($smaily($skipped), $match(['last_error' => 'no_marketing']));
        self::assertSame(['intelligence-' . $ingest], $match(['last_error' => 'HTTP 500', 'status' => 'failed']));

        // The shop's days: 2026-11-19 in the shop's time zone holds the row created at 23:30 UTC the
        // day before only where the shop is ahead of UTC.
        $day = date('Y-m-d', (int) strtotime('2026-11-18 23:30:00 UTC'));
        self::assertContains('smaily-' . $sync, $match(['created_from' => $day, 'created_to' => $day]));
        self::assertNotContains('smaily-' . $welcome, $match(['created_to' => date('Y-m-d', (int) strtotime('2026-11-18 12:00:00 UTC'))]));
        self::assertSame($smaily($sync), $match(['updated_from' => date('Y-m-d', (int) strtotime('2026-11-20 08:00:00 UTC'))]));
        self::assertSame(3, self::rows()->count(new LogFilter(['status' => 'failed'])));
    }

    public function testTheSelectedShopsNarrowEveryPartOfTheList(): void
    {
        $everywhere = self::connection(0);
        $ofShopTwo = self::connection(2);
        $shopOne = self::event(['id_shop' => 1, 'status' => 'failed', 'updated_at' => '2026-11-20 11:00:00', 'created_at' => '2026-11-20 10:00:00']);
        $shopTwo = self::event(['id_shop' => 2, 'status' => 'failed', 'updated_at' => '2026-11-20 11:00:00', 'created_at' => '2026-11-20 10:01:00']);
        $allShopsJob = self::job(['id_shop' => 0, 'started_at' => '2026-11-20 10:02:00']);
        $shopTwoJob = self::job(['id_shop' => 2, 'started_at' => '2026-11-20 10:03:00']);
        $sharedIngest = self::ingest(['id_connection' => $everywhere, 'created_at' => '2026-11-20 10:04:00']);
        $shopTwoIngest = self::ingest(['id_connection' => $ofShopTwo, 'status' => 'failed', 'updated_at' => '2026-11-20 11:00:00', 'created_at' => '2026-11-20 10:05:00']);

        self::assertSame(
            ['intelligence-' . $sharedIngest, 'job-' . $allShopsJob, 'smaily-' . $shopOne],
            self::logIds(self::rows([1])->page(new LogFilter(), 0, 20))
        );
        self::assertSame(
            ['intelligence-' . $shopTwoIngest, 'intelligence-' . $sharedIngest, 'job-' . $shopTwoJob, 'job-' . $allShopsJob, 'smaily-' . $shopTwo],
            self::logIds(self::rows([2])->page(new LogFilter(), 0, 20))
        );
        self::assertSame(6, self::rows()->count(new LogFilter()));
        self::assertSame(6, self::rows([1, 2])->count(new LogFilter()));
        self::assertSame(3, self::rows([1])->count(new LogFilter()));

        $since = (int) strtotime('2026-11-20 10:30:00 UTC');
        self::assertSame([3, 1, 2], [self::rows()->failedSince($since), self::rows([1])->failedSince($since), self::rows([2])->failedSince($since)]);
        self::assertSame(0, self::rows()->failedSince((int) strtotime('2026-11-20 11:00:01 UTC')));

        self::assertNotNull(self::rows([1])->find('smaily-' . $shopOne));
        self::assertNull(self::rows([1])->find('smaily-' . $shopTwo), 'a row of another shop is not read');
        self::assertNull(self::rows([1])->find('job-' . $shopTwoJob));
        self::assertNull(self::rows([1])->find('intelligence-' . $shopTwoIngest));
        self::assertNotNull(self::rows([1])->find('job-' . $allShopsJob));
        self::assertSame([$shopOne], self::rows([1])->failedEventIds(new LogFilter(), null, 0, 10));
        self::assertSame([], self::rows([1])->failedEventIds(new LogFilter(), [$shopTwo], 0, 10));
    }

    public function testARowIsFoundByItsLogId(): void
    {
        $event = self::event(['payload' => '{"email":"shopper1@example.test"}', 'last_request' => '[{"email":"shopper1@example.test"}]']);
        $ingest = self::ingest();
        $job = self::job(['started_at' => null, 'updated_at' => '2026-11-20 09:00:00', 'status' => 'queued']);

        $found = self::rows()->find('smaily-' . $event);
        self::assertSame(
            ['smaily', 'smaily', 'contact.sync', 'shopper1@example.test', '[{"email":"shopper1@example.test"}]', 'sent'],
            [$found['kind'], $found['source'], $found['type'], $found['entity'], $found['last_request'], $found['status']]
        );
        self::assertSame(['intelligence', 'catalog', 'ps-12'], array_values(array_intersect_key(self::rows()->find('intelligence-' . $ingest), ['source' => 1, 'type' => 1, 'entity' => 1])));
        $foundJob = self::rows()->find('job-' . $job);
        self::assertSame(['job', 'smaily', null, null, '2026-11-20 09:00:00'], [$foundJob['kind'], $foundJob['source'], $foundJob['entity'], $foundJob['attempts'], $foundJob['created_at']]);

        foreach (['', 'smaily-0', 'smaily-' . ($event + 1000), 'other-1', 'smaily-1 OR 1=1', 'job-x', $event . ''] as $logId) {
            self::assertNull(self::rows()->find($logId), $logId);
        }
    }

    /**
     * @param list<array<string, string|null>> $rows
     *
     * @return list<string>
     */
    private static function logIds(array $rows): array
    {
        return array_map(static fn (array $row): string => $row['kind'] . '-' . $row['id'], $rows);
    }
}
