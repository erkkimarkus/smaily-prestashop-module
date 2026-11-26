<?php
declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogStatus;
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
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';

/**
 * A contact sync row carries the consent of the moment it was queued, and a row that failed is sent
 * again later. End to end in the shop, with the capture's rows, the runtime's flusher and the Smaily
 * mock: a late or retried row is skipped once a newer change of the same contact reached Smaily, in
 * either direction, and an older change that reached Smaily later never stands in a newer one's
 * way (owner, 2026-11-26). Rows that wait together go in their order.
 */
final class ContactSyncOrderTest extends IntegrationTestCase
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
                self::db()->insert(self::ACCOUNT_TABLE, array_map(static fn ($value) => pSQL((string) $value), $row));
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
     * then the first row's retry comes due, and is skipped.
     *
     * @dataProvider addresses
     */
    public function testTheRetryOfASubscriptionIsSkippedAfterANewerUnsubscribeWasDelivered(string $address): void
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
        self::assertSame([EventQueue::STATUS_SKIPPED, ContactSyncHandler::SKIPPED_NEWER_CHANGE, 1], [$row['status'], $row['last_error'], (int) $row['attempts']]);
        self::assertSame(
            [[$address, 0], [$address, 1]],
            $this->statusesSent(),
            'the failed first attempt, then the unsubscribe: nothing marks the contact subscribed after it'
        );
        self::assertSame(LogStatus::SKIPPED, LogStatus::ofQueueRow($row['status'], $row['event_type'], $row['last_error']), 'not a failure');
        self::assertSame(
            'Skipped: a newer change of this contact already reached Smaily, and this older one would undo it. Nothing was sent.',
            FailureMessage::forDisplay($row['last_error'], static fn (string $text): string => $text),
            'what the Log shows'
        );
    }

    /**
     * The opposite direction: an unsubscribe that failed is skipped after a newer subscription of
     * the contact was delivered. The newer choice stands.
     */
    public function testTheRetryOfAnUnsubscribeIsSkippedAfterANewerSubscriptionWasDelivered(): void
    {
        $this->mock->failContactWrites(true);
        $unsubscribe = $this->capture('shopper1@example.test', false);
        $this->flush();

        $this->mock->failContactWrites(false);
        $subscribe = $this->capture('shopper1@example.test', true);
        $this->flush();
        $this->makeDue($unsubscribe);
        $this->flush();

        self::assertSame([EventQueue::STATUS_SKIPPED, ContactSyncHandler::SKIPPED_NEWER_CHANGE], [$this->row($unsubscribe)['status'], $this->row($unsubscribe)['last_error']]);
        self::assertSame(EventQueue::STATUS_SENT, $this->row($subscribe)['status']);
        self::assertSame([['shopper1@example.test', 1], ['shopper1@example.test', 0]], $this->statusesSent());
    }

    /**
     * An unsubscribe is never skipped in favour of an older subscription: both fail, the older
     * subscription's retry is delivered first, and the newer unsubscribe still goes after it.
     */
    public function testAnUnsubscribeGoesAfterAnOlderSubscriptionThatReachedSmailyLater(): void
    {
        $this->mock->failContactWrites(true);
        $subscribe = $this->capture('shopper1@example.test', true);
        $this->flush();
        $unsubscribe = $this->capture('shopper1@example.test', false);
        $this->flush();

        $this->mock->failContactWrites(false);
        $this->makeDue($subscribe);
        $this->flush();
        self::assertSame([EventQueue::STATUS_SENT, EventQueue::STATUS_PENDING], [$this->row($subscribe)['status'], $this->row($unsubscribe)['status']]);
        $this->makeDue($unsubscribe);
        $this->flush();

        self::assertSame([EventQueue::STATUS_SENT, null], [$this->row($unsubscribe)['status'], $this->row($unsubscribe)['last_error']]);
        self::assertSame(
            [['shopper1@example.test', 0], ['shopper1@example.test', 1], ['shopper1@example.test', 0], ['shopper1@example.test', 1]],
            $this->statusesSent(),
            'the contact ends unsubscribed, the newer choice'
        );
    }

    /**
     * A guest-checkout customer's ticked box is a subscription like any other: its retry is skipped
     * after a newer delivered consent change of the address, here an unsubscribe through the
     * newsletter block. The address is the contact, whatever the source of a row.
     */
    public function testTheRetryOfAGuestsSubscriptionIsSkippedAfterANewerConsentChangeOfTheAddress(): void
    {
        $this->mock->failContactWrites(true);
        $guest = $this->capture('guest1@example.test', true, ['guest' => true, 'checkout' => true]);
        $this->flush();

        $this->mock->failContactWrites(false);
        $block = $this->capture('guest1@example.test', false, ['source' => 'newsletter_block', 'customer' => []]);
        $this->flush();
        $this->makeDue($guest);
        $this->flush();

        self::assertSame([EventQueue::STATUS_SKIPPED, ContactSyncHandler::SKIPPED_NEWER_CHANGE], [$this->row($guest)['status'], $this->row($guest)['last_error']]);
        self::assertSame(EventQueue::STATUS_SENT, $this->row($block)['status']);
        self::assertSame([['guest1@example.test', 0], ['guest1@example.test', 1]], $this->statusesSent());
    }

    /**
     * A newer row that changed no consent (a profile save, an order) sends no subscription status:
     * the guest's subscription is still delivered by its retry. An older profile save is skipped
     * after it.
     */
    public function testANewerRowWithoutAConsentChangeOvertakesOnlyAnOlderRowWithoutOne(): void
    {
        $this->mock->failContactWrites(true);
        $guest = $this->capture('guest1@example.test', true, ['guest' => true, 'checkout' => true]);
        $profile = $this->capture('guest1@example.test', true, ['guest' => true, 'checkout' => true, 'consent_changed' => false]);
        $this->flush();

        $this->mock->failContactWrites(false);
        $order = $this->capture('guest1@example.test', true, ['guest' => true, 'checkout' => true, 'consent_changed' => false]);
        $this->flush();
        $this->makeDue($guest);
        $this->makeDue($profile);
        $this->flush();

        self::assertSame([EventQueue::STATUS_SENT, EventQueue::STATUS_SENT], [$this->row($guest)['status'], $this->row($order)['status']]);
        self::assertSame([EventQueue::STATUS_SKIPPED, ContactSyncHandler::SKIPPED_NEWER_CHANGE], [$this->row($profile)['status'], $this->row($profile)['last_error']]);
        self::assertSame(
            [['guest1@example.test', 0], ['guest1@example.test', null], ['guest1@example.test', null], ['guest1@example.test', 0]],
            $this->statusesSent()
        );
    }

    /**
     * Two changes of one contact that wait together go in their order, in one request.
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
