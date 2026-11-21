<?php
declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\StaleSubscription;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Flusher;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Fixtures\FailingQueries;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';
require_once __DIR__ . '/../Fixtures/FailingQueries.php';

/**
 * A contact sync row carries the consent of the moment it was queued, and a row that failed is sent
 * again later. End to end in the shop, with the capture's rows, the runtime's flusher and the Smaily
 * mock: the retry of a subscription that a later delivered consent change of the same contact
 * replaced is closed as skipped and marks nobody subscribed; a late unsubscribe still goes; rows in
 * their order go as before.
 */
final class StaleSubscriptionTest extends IntegrationTestCase
{
    private const PASSWORD = 'not-a-real-password';

    private const ACCOUNT_TABLE = 'smaily_connect_account';

    /** 70 characters: its entity is a keyed hash (Queue\ContactEntity). */
    private const LONG = 'shopper.having.a.very.long.invented.name@a-long-subdomain.example.test';

    private const KEYS = [
        ContactsSettings::ENABLED,
        ContactsSettings::CONFIRMED,
        ContactsSettings::MODE,
        ContactsSettings::GUESTS,
        ContactsSettings::FIELDS,
        StoreLayout::SEPARATE_STORES,
    ];

    private SmailyMockServer $mock;

    /** @var list<array<string, mixed>> */
    private array $savedAccounts = [];

    private int $shop;

    private int $idLang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = SmailyMockServer::start();
        $this->mock->acceptCredentials('api-user', self::PASSWORD);
        $this->savedAccounts = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '`', true, false) ?: [];
        self::db()->delete(self::ACCOUNT_TABLE);
        self::db()->delete(EventQueue::TABLE);
        $this->deleteKeys();
        $this->shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $this->idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::MODE, ContactSyncMode::SUBSCRIBERS);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, '1');
        \Configuration::updateGlobalValue(ContactsSettings::FIELDS, 'store');
        $store = Services::fromProviders(Services::PROVIDERS)->get(AccountStore::class);
        self::assertTrue($store->save(new Account($this->shop, '', 'store', 'api-user', self::PASSWORD)));
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::db()->delete(EventQueue::TABLE);
            self::db()->delete(self::ACCOUNT_TABLE);
            foreach ($this->savedAccounts as $row) {
                self::db()->insert(self::ACCOUNT_TABLE, array_map(static fn ($value) => $value === null ? null : pSQL((string) $value), $row), true);
            }
            $this->deleteKeys();
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function addresses(): iterable
    {
        yield 'an address that is its own entity' => ['shopper1@example.test'];
        yield 'a 70-character address, stored under its keyed hash' => [self::LONG];
    }

    /**
     * The contact subscribes and the row fails; the contact unsubscribes and that row is delivered;
     * then the first row's retry comes due.
     *
     * @dataProvider addresses
     */
    public function testTheRetryOfASubscriptionIsSkippedAfterALaterUnsubscribeWasDelivered(string $address): void
    {
        $this->mock->failContactWrites(true);
        $subscribe = $this->capture($address, true);
        $this->flush();
        self::assertSame([EventQueue::STATUS_PENDING, 1], [$this->row($subscribe)['status'], (int) $this->row($subscribe)['attempts']]);
        self::assertNotNull($this->row($subscribe)['next_retry_at'], 'the row waits for its retry');
        self::assertSame(ContactEntity::of($address), $this->row($subscribe)['entity_id']);

        $this->mock->failContactWrites(false);
        $unsubscribe = $this->capture(strtoupper($address), false);
        $this->flush();
        self::assertSame([EventQueue::STATUS_PENDING, EventQueue::STATUS_SENT], [$this->row($subscribe)['status'], $this->row($unsubscribe)['status']]);

        $this->makeDue($subscribe);
        self::assertSame(1, $this->flush(), 'the retry runs');

        $row = $this->row($subscribe);
        self::assertSame([EventQueue::STATUS_SKIPPED, ContactSyncHandler::SKIPPED_STALE_SUBSCRIPTION, 1], [$row['status'], $row['last_error'], (int) $row['attempts']]);
        self::assertSame(
            [[$address, 0], [$address, 1]],
            $this->statusesSent(),
            'the failed first attempt, then the unsubscribe: nothing marks the contact subscribed after it'
        );
        self::assertSame(
            'Skipped: the shopper has since changed their newsletter subscription, and the newer choice already reached Smaily. Nothing was sent.',
            FailureMessage::forDisplay($row['last_error'], static fn (string $text): string => $text),
            'what the Log shows'
        );
        self::assertSame(EventQueue::STATUS_SENT, $this->row($unsubscribe)['status']);
    }

    /**
     * The same story, but the read of the contact's later rows fails when the retry comes due, the
     * way a live PrestaShop 9.2.0 store reports it (facts file 6.11). That is not "no later row":
     * read so, the subscription went out after the unsubscribe and subscribed the contact again.
     * The row fails with nothing sent, and its next attempt finds the later row.
     */
    public function testAStaleCheckThatFailsSendsNothingAndTheNextAttemptSkipsTheRow(): void
    {
        $address = 'shopper1@example.test';
        $this->mock->failContactWrites(true);
        $subscribe = $this->capture($address, true);
        $this->flush();
        $this->mock->failContactWrites(false);
        $unsubscribe = $this->capture($address, false);
        $this->flush();
        self::assertSame(EventQueue::STATUS_SENT, $this->row($unsubscribe)['status']);
        $this->makeDue($subscribe);

        $failed = FailingQueries::during('/SELECT `id`, `id_shop`, `entity_id`, `payload` FROM/', function (): void {
            self::assertSame(1, $this->flush());
        });

        self::assertSame(1, $failed);
        $row = $this->row($subscribe);
        self::assertSame([EventQueue::STATUS_PENDING, 2], [$row['status'], (int) $row['attempts']], 'the row is retried');
        self::assertStringContainsString('SqlException', (string) $row['last_error']);
        self::assertSame([[$address, 0], [$address, 1]], $this->statusesSent(), 'nothing was sent in that attempt');

        $this->makeDue($subscribe);
        self::assertSame(1, $this->flush());
        $row = $this->row($subscribe);
        self::assertSame([EventQueue::STATUS_SKIPPED, ContactSyncHandler::SKIPPED_STALE_SUBSCRIPTION], [$row['status'], $row['last_error']]);
        self::assertSame([[$address, 0], [$address, 1]], $this->statusesSent(), 'and nothing marks the contact subscribed');
    }

    /**
     * The opposite order, as the sibling connectors deliver it: a row that unsubscribes is never
     * stale. Sent late, it narrows who is subscribed; it never widens it.
     */
    public function testALateUnsubscribeStillGoesAfterALaterSubscriptionWasDelivered(): void
    {
        $this->mock->failContactWrites(true);
        $unsubscribe = $this->capture('shopper1@example.test', false);
        $this->flush();

        $this->mock->failContactWrites(false);
        $subscribe = $this->capture('shopper1@example.test', true);
        $this->flush();
        $this->makeDue($unsubscribe);
        $this->flush();

        self::assertSame([EventQueue::STATUS_SENT, EventQueue::STATUS_SENT], [$this->row($unsubscribe)['status'], $this->row($subscribe)['status']]);
        self::assertSame([['shopper1@example.test', 1], ['shopper1@example.test', 0], ['shopper1@example.test', 1]], $this->statusesSent());
    }

    /**
     * A guest-checkout customer's ticked box is a subscription like any other: its retry is skipped
     * after a later delivered consent change of the address, here an unsubscribe through the
     * newsletter block. The address is the contact, whatever the source of a row.
     */
    public function testTheRetryOfAGuestsSubscriptionIsSkippedAfterALaterConsentChangeOfTheAddress(): void
    {
        $this->mock->failContactWrites(true);
        $guest = $this->capture('guest1@example.test', true, ['guest' => true, 'checkout' => true]);
        $this->flush();

        $this->mock->failContactWrites(false);
        $block = $this->capture('guest1@example.test', false, ['source' => 'newsletter_block', 'customer' => []]);
        $this->flush();
        $this->makeDue($guest);
        $this->flush();

        self::assertSame([EventQueue::STATUS_SKIPPED, ContactSyncHandler::SKIPPED_STALE_SUBSCRIPTION], [$this->row($guest)['status'], $this->row($guest)['last_error']]);
        self::assertSame(EventQueue::STATUS_SENT, $this->row($block)['status']);
        self::assertSame([['guest1@example.test', 0], ['guest1@example.test', 1]], $this->statusesSent());
    }

    /**
     * A later row that changed no consent (a profile save, an order) replaces nothing: the guest's
     * subscription is delivered by its retry.
     */
    public function testTheRetryOfAGuestsSubscriptionGoesAfterALaterRowWithoutAConsentChange(): void
    {
        $this->mock->failContactWrites(true);
        $guest = $this->capture('guest1@example.test', true, ['guest' => true, 'checkout' => true]);
        $this->flush();

        $this->mock->failContactWrites(false);
        $order = $this->capture('guest1@example.test', true, ['guest' => true, 'checkout' => true, 'consent_changed' => false]);
        $this->flush();
        $this->makeDue($guest);
        $this->flush();

        self::assertSame([EventQueue::STATUS_SENT, EventQueue::STATUS_SENT], [$this->row($guest)['status'], $this->row($order)['status']]);
        self::assertSame([['guest1@example.test', 0], ['guest1@example.test', null], ['guest1@example.test', 0]], $this->statusesSent());
    }

    /**
     * Two changes of one contact that wait together go in their order, in one request, as before.
     */
    public function testTwoChangesInTheirOrderBothReachSmaily(): void
    {
        $subscribe = $this->capture('shopper1@example.test', true);
        $unsubscribe = $this->capture('shopper1@example.test', false);

        self::assertSame(2, $this->flush());

        self::assertSame([EventQueue::STATUS_SENT, EventQueue::STATUS_SENT], [$this->row($subscribe)['status'], $this->row($unsubscribe)['status']]);
        self::assertCount(1, $this->mock->requests());
        self::assertSame([['shopper1@example.test', 0], ['shopper1@example.test', 1]], $this->statusesSent());
    }

    /**
     * What makes a row stale, on rows as the queue stores them: only a later delivered row of the
     * same contact in the same shop with a consent change, and only for a row that subscribes.
     */
    public function testOnlyALaterDeliveredConsentChangeOfTheSameContactInTheSameShopMakesASubscriptionStale(): void
    {
        self::stored('sent', false, true);
        $afterAnEarlierChange = self::stored('pending', true, true);
        self::stored('sent', true, false);
        self::stored('pending', false, true);
        self::stored('failed', false, true);
        self::stored('skipped', false, true);
        self::stored('sent', false, true, ['id_shop' => 2]);
        self::stored('sent', false, true, ['entity_id' => 'shopper2@example.test']);
        self::stored('sent', false, true, ['event_type' => 'automation.welcome']);
        self::assertSame([], $this->stale($afterAnEarlierChange), 'no later delivered consent change of this contact in this shop');

        $unsubscribe = self::stored('pending', false, true);
        $profile = self::stored('pending', true, false);
        $withoutEntity = self::stored('pending', true, true, ['entity_id' => null]);
        $otherShop = self::stored('pending', true, true, ['id_shop' => 2]);
        $stale = self::stored('pending', true, true);
        self::stored('sent', true, true, ['entity_id' => 'Shopper1@Example.TEST']);
        $afterTheChange = self::stored('pending', true, true);

        self::assertSame(
            [$afterAnEarlierChange, $stale],
            $this->stale($afterAnEarlierChange, $unsubscribe, $profile, $withoutEntity, $otherShop, $stale, $afterTheChange),
            'a later subscription makes an earlier one stale too; the newest row carries the choice'
        );
    }

    /**
     * Writes one contact event as a request's capture does, and returns its row id.
     *
     * @param array<string, mixed> $overrides
     */
    private function capture(string $email, bool $subscribed, array $overrides = []): int
    {
        $events = new ContactEvents(
            new EventQueue(),
            new ContactsSettings(),
            new Lock(),
            static function (): Flusher {
                throw new \LogicException('The test flushes.');
            },
            null,
            static function (): void {
            }
        );
        $events->add($this->shop, $this->idLang, $overrides + [
            'email' => $email,
            'source' => 'customer',
            'subscribed' => $subscribed,
            'consent_changed' => true,
            'checkout' => false,
            'guest' => false,
            'id_lang' => $this->idLang,
            'customer' => ['firstname' => 'Testfirst', 'lastname' => 'Testlast', 'birthday' => '0000-00-00', 'website' => ''],
        ]);
        self::assertSame(1, $events->commit());

        return (int) self::db()->getValue('SELECT MAX(`id`) FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`', false);
    }

    /**
     * One flush of the runtime's queue to the Smaily mock.
     */
    private function flush(): int
    {
        $mock = $this->mock->transport();
        $services = Services::fromProviders(Services::PROVIDERS);
        $services->set(Transport::class, static fn (): Transport => $mock);

        return $services->get(QueueProvider::FLUSHER)->flush();
    }

    /**
     * The row's retry time has come.
     */
    private function makeDue(int $id): void
    {
        self::db()->update(EventQueue::TABLE, ['next_retry_at' => gmdate('Y-m-d H:i:s', time() - 1)], '`id` = ' . $id);
    }

    /**
     * The address and `is_unsubscribed` (null when left out) of every contact the mock received.
     *
     * @return list<array{string, int|null}>
     */
    private function statusesSent(): array
    {
        $sent = [];
        foreach ($this->mock->requests() as $request) {
            self::assertSame(['POST', '/api/contact.php'], [$request['method'], $request['path']]);
            foreach ($request['body'] as $contact) {
                $sent[] = [$contact['email'], $contact['is_unsubscribed'] ?? null];
            }
        }

        return $sent;
    }

    /**
     * A `contact.sync` row of shopper1 in shop 1, written as stored.
     *
     * @param array<string, mixed> $columns
     */
    private static function stored(string $status, bool $subscribed, bool $consentChanged, array $columns = []): int
    {
        $columns += [
            'id_shop' => 1,
            'event_type' => ContactSyncHandler::EVENT_TYPE,
            'entity_id' => 'shopper1@example.test',
            'payload' => (string) json_encode(['email' => 'shopper1@example.test', 'source' => 'customer', 'subscribed' => $subscribed, 'consent_changed' => $consentChanged]),
            'status' => $status,
            'attempts' => 0,
            'created_at' => '2026-11-20 10:00:00',
            'updated_at' => '2026-11-20 10:00:00',
        ];
        $values = array_map(static fn ($value) => $value === null ? null : pSQL((string) $value, true), $columns);
        self::assertTrue(self::db()->insert(EventQueue::TABLE, $values, true), self::db()->getMsgError());

        return (int) self::db()->Insert_ID();
    }

    /**
     * @return list<int>
     */
    private function stale(int ...$ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = $this->row($id);
        }
        $stale = StaleSubscription::among($rows);
        sort($stale);

        return $stale;
    }

    /**
     * @return array<string, string|null>
     */
    private function row(int $id): array
    {
        $row = self::db()->getRow('SELECT * FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `id` = ' . $id, false);
        self::assertIsArray($row);

        return $row;
    }

    private function deleteKeys(): void
    {
        foreach (self::KEYS as $key) {
            \Configuration::deleteByName($key);
        }
    }
}
