<?php
declare(strict_types=1);

namespace Tests\Integration\Privacy;

use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingConsent;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingRecord;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;
use PrestaShop\Module\SmailyForPrestaShop\Report\ConnectionHealth;

require_once __DIR__ . '/PrivacyTestCase.php';

/**
 * A shopper's choice about personalization, in the shop against the engine mock: the store's
 * record holds the newest choice under the address's keyed hash; a choice is recorded first and
 * then queued for every connection (contract §10); of two quick choices the last one stays, in
 * the store and at the engine; leaving marketing stops profiling as in the Magento connector; and
 * nothing of an engine's error text is stored.
 */
final class ProfilingConsentTest extends PrivacyTestCase
{
    private const ADDRESS_A = 'shopper1@example.test';

    /** What the mock's error answers say: an engine's text can name a shopper. */
    private const REMOTE_TEXT = 'Remote text about shopper1@example.test';

    public function testTheRecordHoldsTheNewestChoiceUnderTheKeyedHashAndNoAddress(): void
    {
        $now = 1795000000;
        $record = new ProfilingRecord(static function () use (&$now): int {
            return $now;
        });

        self::assertNull($record->find(self::ADDRESS_A));
        self::assertFalse($record->optedOut(self::ADDRESS_A), 'no choice: the shopper is profiled');

        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $now), $record->write(' Shopper1@Example.TEST ', true, ProfilingRecord::SOURCE_ACCOUNT));
        self::assertSame(
            ['opted_out' => true, 'changed_at' => gmdate('Y-m-d H:i:s', $now), 'source' => 'account'],
            $record->find(self::ADDRESS_A),
            'every spelling of the address is one shopper'
        );
        $now += 5;
        $record->write(self::ADDRESS_A, false, ProfilingRecord::SOURCE_SUBSCRIBE);
        self::assertSame(['opted_out' => false, 'changed_at' => gmdate('Y-m-d H:i:s', $now), 'source' => 'subscribe'], $record->find(self::ADDRESS_A));
        self::assertNull($record->find('shopper2@example.test'));

        $rows = self::table(ProfilingRecord::TABLE);
        self::assertCount(1, $rows, 'one row per address');
        self::assertSame(ContactEntity::key(self::ADDRESS_A), $rows[0]['address_key']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $rows[0]['address_key']);
        self::assertStringNotContainsString('shopper1', (string) json_encode($rows), 'the table never holds the address');
    }

    public function testAChoiceIsRecordedFirstAndQueuedForEveryConnection(): void
    {
        $consent = $this->services->get(ProfilingConsent::class);

        $consent->setAllowed(self::ADDRESS_A, false, $this->idShop);
        self::assertFalse($consent->isAllowed(self::ADDRESS_A), 'the store keeps the choice without a connection');
        self::assertSame([], self::table('smaily_connect_event_queue'), 'and nothing is queued for an engine that is not there');

        $first = $this->connect();
        $second = $this->connectAlso($this->idShop + 7);
        $consent->setAllowed('Shopper1@Example.test', true, $this->idShop);

        self::assertTrue($consent->isAllowed(self::ADDRESS_A));
        $rows = self::table('smaily_connect_event_queue', '`id`');
        self::assertSame(
            [
                ['engine.profiling_consent', $this->idShop, ContactEntity::key(self::ADDRESS_A), 'pending', ['connection' => $first->id(), 'email' => self::ADDRESS_A, 'opt_out' => false]],
                ['engine.profiling_consent', $this->idShop + 7, ContactEntity::key(self::ADDRESS_A), 'pending', ['connection' => $second->id(), 'email' => self::ADDRESS_A, 'opt_out' => false]],
            ],
            array_map(static fn (array $row): array => [$row['event_type'], (int) $row['id_shop'], $row['entity_id'], $row['status'], json_decode((string) $row['payload'], true)], $rows),
            'one row per connection, in the connection\'s shop, under the keyed hash'
        );

        self::assertSame(2, $this->flush());
        $posts = $this->engineRequests('POST', self::ADDRESS_A, '/opt-out');
        self::assertCount(2, $posts);
        self::assertSame(['opt_out' => false, 'reason' => 'user_preference'], $posts[0]['body'], 'an opt-in carries no time');
        self::assertNotSame($posts[0]['key'], $posts[1]['key'], 'each with its own connection\'s key');
        self::assertSame(['sent', 'sent'], array_column(self::table('smaily_connect_event_queue', '`id`'), 'status'));
    }

    public function testOfTwoQuickChoicesTheLastOneStaysInTheStoreAndAtTheEngine(): void
    {
        $this->connect();
        $consent = $this->services->get(ProfilingConsent::class);

        $consent->setAllowed(self::ADDRESS_A, false, $this->idShop);
        $consent->setAllowed(self::ADDRESS_A, true, $this->idShop);
        self::assertSame(2, $this->flush());

        self::assertTrue($consent->isAllowed(self::ADDRESS_A));
        $rows = self::table('smaily_connect_event_queue', '`id`');
        self::assertSame([['skipped', 'preference_replaced'], ['sent', null]], array_map(static fn (array $row): array => [$row['status'], $row['last_error']], $rows));
        $posts = $this->engineRequests('POST', self::ADDRESS_A, '/opt-out');
        self::assertSame([false], array_column(array_column($posts, 'body'), 'opt_out'), 'the replaced choice never reached the engine');

        // Out, in and out again: the first and the last say what the record says.
        $consent->setAllowed(self::ADDRESS_A, false, $this->idShop);
        $consent->setAllowed(self::ADDRESS_A, true, $this->idShop);
        $consent->setAllowed(self::ADDRESS_A, false, $this->idShop);
        self::assertSame(3, $this->flush());

        self::assertFalse($consent->isAllowed(self::ADDRESS_A));
        self::assertSame(['skipped', 'sent', 'sent', 'skipped', 'sent'], array_column(self::table('smaily_connect_event_queue', '`id`'), 'status'));
        $posts = $this->engineRequests('POST', self::ADDRESS_A, '/opt-out');
        self::assertTrue(end($posts)['body']['opt_out'], 'the engine ends at the last choice');
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', end($posts)['body']['opted_out_at']);
    }

    public function testAnOlderChoiceThatIsRetriedLaterNeverUndoesTheNewerOne(): void
    {
        $this->connect();
        $consent = $this->services->get(ProfilingConsent::class);
        $this->engine->fail('customer_opt_out', 503, 'internal_error', self::REMOTE_TEXT);

        $consent->setAllowed(self::ADDRESS_A, false, $this->idShop);
        self::assertSame(1, $this->flush());
        $row = self::table('smaily_connect_event_queue')[0];
        self::assertSame(['pending', 1, 'Campaign Intelligence answered HTTP 503 (internal_error).'], [$row['status'], (int) $row['attempts'], $row['last_error']]);
        self::assertNotNull($row['next_retry_at'], 'the row waits on the retry ladder');

        // The shopper changes their mind while the first choice waits; then the engine answers.
        $consent->setAllowed(self::ADDRESS_A, true, $this->idShop);
        $this->engine->set('fail', []);
        self::makeDue();
        self::assertSame(2, $this->flush());

        self::assertSame(['skipped', 'sent'], array_column(self::table('smaily_connect_event_queue', '`id`'), 'status'));
        $posts = $this->engineRequests('POST', self::ADDRESS_A, '/opt-out');
        self::assertSame([true, false], array_column(array_column($posts, 'body'), 'opt_out'), 'the failed attempt, then the newer choice alone');
        self::assertStringNotContainsString('Remote text', (string) json_encode(self::table('smaily_connect_event_queue')), 'no column holds the engine\'s text');
    }

    public function testAnUnknownShopperIsDoneARefusalParksTheRowAndAMissingConnectionSkipsIt(): void
    {
        $connection = $this->connect();
        $consent = $this->services->get(ProfilingConsent::class);
        $this->engine->set('unknown_customers', [self::ADDRESS_A]);

        $consent->setAllowed(self::ADDRESS_A, false, $this->idShop);
        self::assertSame(1, $this->flush());
        self::assertSame(['sent', null], [self::table('smaily_connect_event_queue')[0]['status'], self::table('smaily_connect_event_queue')[0]['last_error']], 'HTTP 404: the engine holds nobody to exclude');

        $this->engine->set('tenant_inactive', true);
        $consent->setAllowed('shopper2@example.test', false, $this->idShop);
        self::assertSame(1, $this->flush());
        $refused = self::table('smaily_connect_event_queue', '`id`')[1];
        self::assertSame(
            ['failed', 1, 'permanent_http_403: Campaign Intelligence answered HTTP 403 (tenant_inactive).', null],
            [$refused['status'], (int) $refused['attempts'], $refused['last_error'], $refused['next_retry_at']],
            'a refusal is parked at once, with the status and the engine\'s code'
        );
        self::assertStringNotContainsString('deactivated', (string) json_encode($refused), 'and never with its message');

        $this->engine->set('tenant_inactive', false);
        $consent->setAllowed('shopper3@example.test', false, $this->idShop);
        $this->services->get(\PrestaShop\Module\SmailyForPrestaShop\Engine\Connections::class)->delete($connection);
        self::assertSame(1, $this->flush());
        $skipped = self::table('smaily_connect_event_queue', '`id`')[2];
        self::assertSame(['skipped', 'no_connection'], [$skipped['status'], $skipped['last_error']]);
        self::assertSame([], $this->engineRequests('POST', 'shopper3@example.test', '/opt-out'));
    }

    public function testLeavingMarketingStopsProfilingAndJoiningAgainLiftsIt(): void
    {
        $consent = $this->services->get(ProfilingConsent::class);
        $record = $this->services->get(ProfilingRecord::class);
        $left = ['email' => self::ADDRESS_A, 'subscribed' => false, 'consent_changed' => true];
        $joined = ['email' => self::ADDRESS_A, 'subscribed' => true, 'consent_changed' => true];

        self::assertSame(0, $consent->onContactEvent($this->idShop, 'en', $left));
        self::assertNull($record->find(self::ADDRESS_A), 'a shop without a connection records nothing');

        $this->connect();
        self::assertSame(0, $consent->onContactEvent($this->idShop, 'en', ['consent_changed' => false] + $left), 'a save that changed no consent');
        self::assertSame(0, $consent->onContactEvent($this->idShop, 'en', $joined), 'a first subscription has no opt-out to lift');
        self::assertNull($record->find(self::ADDRESS_A));

        self::assertSame(1, $consent->onContactEvent($this->idShop, 'en', $left));
        self::assertSame([true, 'unsubscribe'], [$record->find(self::ADDRESS_A)['opted_out'], $record->find(self::ADDRESS_A)['source']]);
        self::assertFalse($consent->isAllowed(self::ADDRESS_A));

        self::assertSame(1, $consent->onContactEvent($this->idShop, 'en', $joined));
        self::assertSame([false, 'subscribe'], [$record->find(self::ADDRESS_A)['opted_out'], $record->find(self::ADDRESS_A)['source']]);
        self::assertSame(2, $this->flush());
        $posts = $this->engineRequests('POST', self::ADDRESS_A, '/opt-out');
        self::assertSame([false], array_column(array_column($posts, 'body'), 'opt_out'), 'the engine hears where it ended');

        // An opt-out of the shopper's own: leaving marketing keeps it, and joining again does not lift it.
        $consent->setAllowed(self::ADDRESS_A, false, $this->idShop);
        self::assertSame(0, $consent->onContactEvent($this->idShop, 'en', $left));
        self::assertSame(0, $consent->onContactEvent($this->idShop, 'en', $joined));
        self::assertSame([true, 'account'], [$record->find(self::ADDRESS_A)['opted_out'], $record->find(self::ADDRESS_A)['source']]);
    }

    public function testAnUnsubscribeThatTheReconcileTakesFromSmailyStopsProfilingToo(): void
    {
        $this->connect();
        $idCustomer = $this->fixture->customer(self::ADDRESS_A, false, true);
        \Configuration::updateGlobalValue(\PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(\PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings::CONFIRMED, '1');
        $this->mode(\PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode::SUBSCRIBERS);
        $this->services = $this->services();
        $this->mock->setUnsubscribers([self::ADDRESS_A]);

        $changed = $this->services->get(\PrestaShop\Module\SmailyForPrestaShop\Contacts\Reconciler::class)
            ->run(new \PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline(microtime(true) + 15, static fn (): float => microtime(true)));

        self::assertSame(1, $changed);
        self::assertSame(0, (int) self::db()->getValue('SELECT `newsletter` FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer` = ' . $idCustomer));
        $record = $this->services->get(ProfilingRecord::class)->find(self::ADDRESS_A);
        self::assertSame([true, 'unsubscribe'], [$record['opted_out'], $record['source']], 'as the Magento connector, whoever recorded the unsubscribe');
        $rows = self::table('smaily_connect_event_queue');
        self::assertSame([ProfilingConsent::EVENT_TYPE], array_column($rows, 'event_type'), 'the engine hears it; nothing goes back to Smaily');
        self::assertTrue(json_decode((string) $rows[0]['payload'], true)['opt_out']);
        \Configuration::deleteByName(\PrestaShop\Module\SmailyForPrestaShop\Contacts\Reconciler::CURSORS);
    }

    public function testAnAccountSaveThatUnticksTheNewsletterStopsProfilingThroughTheHooks(): void
    {
        $this->connect();
        $this->hooksUseTheMocks();
        $idCustomer = $this->fixture->customer(self::ADDRESS_A, false, true);
        $customer = new \Customer($idCustomer);
        $events = $this->services->get(\PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents::class);

        $customer->newsletter = false;
        self::assertTrue($customer->update(), 'PrestaShop fires the customer update hooks');
        $events->commit();

        $record = $this->services->get(ProfilingRecord::class)->find(self::ADDRESS_A);
        self::assertSame([true, 'unsubscribe'], [$record['opted_out'], $record['source']]);
        $queued = array_values(array_filter(self::table('smaily_connect_event_queue'), static fn (array $row): bool => $row['event_type'] === ProfilingConsent::EVENT_TYPE));
        self::assertCount(1, $queued);
        self::assertTrue(json_decode((string) $queued[0]['payload'], true)['opt_out']);
    }

    public function testABulkOfOptOutsKeepsToTheEnginesLimitAndSpendsNoAttempt(): void
    {
        $this->connect();
        $consent = $this->services->get(ProfilingConsent::class);
        for ($i = 1; $i <= 25; ++$i) {
            $consent->setAllowed('bulk' . $i . '@example.test', false, $this->idShop);
        }

        $sent = [];
        foreach ([25, 15, 5] as $minute => $due) {
            self::assertSame($due, $this->flush(), 'minute ' . $minute . ': every due row is claimed');
            self::assertSame(0, $this->flush(), 'a deferred row is not due again in the same minute');
            $rows = self::table('smaily_connect_event_queue', '`id`');
            $sent[] = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'sent'));
            self::assertSame([0], array_values(array_unique(array_map('intval', array_column($rows, 'attempts')))), 'no row spent an attempt');
            foreach ($rows as $row) {
                if ($row['status'] === 'pending') {
                    self::assertNull($row['last_error'], 'a row held back has no failure');
                    self::assertNotNull($row['next_retry_at'], 'and waits for the next minute');
                }
            }
            // A minute on, and one second more: the requests of one flush may span a second.
            $this->passSeconds(61);
            self::makeDue();
        }

        self::assertSame([10, 20, 25], $sent, 'ten requests a minute, all delivered in the end');
        self::assertCount(25, array_filter($this->engine->requests(), static fn (array $request): bool => substr((string) $request['path'], -8) === '/opt-out'));
    }

    public function testA429HoldsTheRowAndTheRestOfTheStoresRowsForTheWaitItAskedFor(): void
    {
        $connection = $this->connect();
        $consent = $this->services->get(ProfilingConsent::class);
        $this->engine->fail('customer_opt_out', 429, 'rate_limit_exceeded', self::REMOTE_TEXT, 42);
        foreach (['shopper1@example.test', 'shopper2@example.test', 'shopper3@example.test'] as $address) {
            $consent->setAllowed($address, false, $this->idShop);
        }

        $before = time();
        self::assertSame(3, $this->flush());
        $after = time();

        $rows = self::table('smaily_connect_event_queue', '`id`');
        self::assertSame(
            [['pending', 0, 'Campaign Intelligence answered HTTP 429 (rate_limit_exceeded).'], ['pending', 0, null], ['pending', 0, null]],
            array_map(static fn (array $row): array => [$row['status'], (int) $row['attempts'], $row['last_error']], $rows),
            'the row that met the 429 and the two after it wait, and none spends an attempt'
        );
        self::assertCount(1, array_unique(array_column($rows, 'next_retry_at')), 'all until the same moment');
        $until = strtotime($rows[0]['next_retry_at'] . ' UTC');
        self::assertGreaterThanOrEqual($before + 42, $until);
        self::assertLessThanOrEqual($after + 42, $until);
        self::assertCount(1, $this->engineRequests('POST', self::ADDRESS_A, '/opt-out'));
        self::assertCount(0, $this->engineRequests('POST', 'shopper2@example.test', '/opt-out'), 'the rest are not asked');
        self::assertNull(
            $this->services->get(ConnectionHealth::class)->engine($connection->tenantId())['last_error_code'],
            'a 429 says nothing of the engine store\'s health'
        );
        self::assertSame(0, $this->flush(), 'nothing is due before the wait ends');

        $this->engine->set('fail', []);
        $this->passSeconds(42);
        self::makeDue();
        self::assertSame(3, $this->flush());
        self::assertSame(
            [['sent', 0, null], ['sent', 0, null], ['sent', 0, null]],
            array_map(static fn (array $row): array => [$row['status'], (int) $row['attempts'], $row['last_error']], self::table('smaily_connect_event_queue', '`id`'))
        );
    }
}
