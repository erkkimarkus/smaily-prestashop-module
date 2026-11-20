<?php
declare(strict_types=1);

namespace Tests\Integration\Log;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogFilter;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogRows;
use PrestaShop\Module\SmailyForPrestaShop\Log\Resend;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;
use PrestaShop\Module\SmailyForPrestaShop\Log\Retry;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use Tests\Integration\Automation\AbandonedCart\AbandonedCartTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../Automation/AbandonedCart/AbandonedCartTestCase.php';

/**
 * The Log's writes in the shop, against the Smaily mock: the queue keeps what each attempt sent and
 * what came back, without secrets; "Send again" queues a failed row's event once as a new row that
 * the next flush delivers; "Retry" returns failed rows to pending once and leaves alone, and
 * counts, the rows that are not safe to send again.
 *
 * The store of AbandonedCartTestCase (one account that the mock accepts), with contact sync and
 * the welcome automation on.
 */
final class LogActionsTest extends AbandonedCartTestCase
{
    private const PASSWORD = 'not-a-real-password';

    protected function setUp(): void
    {
        parent::setUp();
        $contacts = new ContactsSettings();
        $contacts->setMode(new ContactSyncMode(ContactSyncMode::SUBSCRIBERS, false), 0);
        $contacts->setFields(['first_name', 'last_name'], 0);
        $contacts->setEnabled(true, 0);
        $contacts->confirm(0);
        (new MappingRepository())->upsert(0, Trigger::WELCOME, '', MappingRepository::ACCOUNT_DEFAULT, 101);
        (new AutomationSettings())->setEnabled(Trigger::WELCOME, true, 0);
        LegacyStore::reloadConfiguration();
        $this->services = $this->services();
    }

    public function testEachAttemptKeepsItsRequestAndAnswerWithoutSecrets(): void
    {
        $delivered = $this->sync('shopper1@example.test');
        $welcome = $this->welcome('shopper1@example.test');
        self::db()->delete('smaily_connect_cart');
        $skipped = $this->queue()->enqueue('automation.abandoned_cart', $this->idShop, ['id_cart' => 999999], 'shopper2@example.test', 'en');
        $this->flush();
        $this->mock->acceptCredentials('api-user', 'changed-in-smaily');
        $refused = $this->sync('shopper3@example.test');
        $this->flush();

        $row = self::stored($delivered);
        self::assertSame('sent', $row['status']);
        $contact = json_decode((string) $row['last_request'], true);
        self::assertSame(['shopper1@example.test', 'Testfirst', 'Testlast'], [$contact[0]['email'], $contact[0]['first_name'], $contact[0]['last_name']], 'the contact shows in full');
        self::assertCount(1, $contact);
        self::assertSame(['http_status' => 200, 'body' => ['code' => 101]], json_decode((string) $row['last_response'], true));

        $trigger = json_decode((string) self::stored($welcome)['last_request'], true);
        self::assertSame([101, 'shopper1@example.test', false], [$trigger['autoresponder'], $trigger['addresses'][0]['email'], $trigger['force_opt_in']]);

        self::assertSame(['skipped', null, null], array_values(array_intersect_key(self::stored($skipped), ['status' => 1, 'last_request' => 1, 'last_response' => 1])));

        $failed = self::stored($refused);
        self::assertSame(['failed', 'permanent_http_401: Smaily API credentials were rejected'], [$failed['status'], $failed['last_error']]);
        self::assertSame('shopper3@example.test', json_decode((string) $failed['last_request'], true)[0]['email']);
        self::assertSame(['http_status' => 401, 'body' => []], json_decode((string) $failed['last_response'], true), 'the answer keeps its status, never its text');

        $everything = (string) json_encode(self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`', true, false));
        foreach ([self::PASSWORD, 'changed-in-smaily', base64_encode('api-user:' . self::PASSWORD), 'Authorization', 'Unauthorized'] as $secret) {
            self::assertStringNotContainsString($secret, $everything);
        }
    }

    public function testAKeyThatLooksLikeASecretIsRedactedBeforeItIsStored(): void
    {
        $id = $this->queue()->enqueue('contact.sync', $this->idShop, ['email' => 'shopper1@example.test'], 'shopper1@example.test', 'en');
        $row = $this->queue()->claim(10)[0];

        $this->queue()->recordExchange(
            $row,
            [['email' => 'shopper1@example.test', 'api_key' => 'not-a-real-key', 'nested' => ['password' => self::PASSWORD]]],
            ['http_status' => 200, 'body' => ['code' => 101, 'access_token' => 'not-a-real-token']]
        );
        $this->queue()->recordExchange($row, [['email' => 'shopper1@example.test']], null);
        $this->queue()->recordExchange(['claim_token' => 'another-flush'] + $row, [['email' => 'other']], ['http_status' => 500, 'body' => null]);

        $stored = self::stored($id);
        self::assertSame('[{"email":"shopper1@example.test"}]', $stored['last_request'], 'the latest attempt of the row\'s own claim');
        self::assertSame('{"http_status":200,"body":{"code":101,"access_token":"[redacted]"}}', $stored['last_response'], 'no answer keeps the earlier one');

        $this->queue()->recordExchange($row, [['api_key' => 'not-a-real-key']], ['http_status' => 200, 'body' => ['list' => array_fill(0, 9000, 'contact@example.test')]]);
        $stored = self::stored($id);
        self::assertSame('[{"api_key":"[redacted]"}]', $stored['last_request']);
        self::assertSame('{"http_status":200,"body":null}', $stored['last_response'], 'an answer too long for the column keeps its status');
    }

    public function testSendAgainQueuesTheEventOnceAndTheNextFlushDeliversIt(): void
    {
        $this->mock->acceptCredentials('api-user', 'changed-in-smaily');
        $failed = $this->welcome('shopper1@example.test');
        $this->flush();
        $this->mock->acceptCredentials('api-user', self::PASSWORD);
        $before = self::stored($failed);
        self::assertSame('failed', $before['status']);
        self::assertSame([], $this->refusal($failed));

        $copy = (new Resend($this->queue(), new CartTracker()))->resend($before, 'Testfirst Testlast');

        self::assertSame($before, self::stored($failed), 'the failed row stays as history');
        $queued = self::stored($copy);
        self::assertSame(
            ['pending', 0, $before['event_type'], $before['id_shop'], $before['entity_id'], $before['language'], null, null],
            [$queued['status'], (int) $queued['attempts'], $queued['event_type'], $queued['id_shop'], $queued['entity_id'], $queued['language'], $queued['last_error'], $queued['last_request']]
        );
        $payload = json_decode((string) $queued['payload'], true);
        self::assertSame([$failed, 'Testfirst Testlast'], [$payload['_resend']['of'], $payload['_resend']['by']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $payload['_resend']['at']);
        unset($payload['_resend']);
        self::assertSame(json_decode((string) $before['payload'], true), $payload);
        self::assertSame([$failed => ResendGuard::REASON_QUEUED], $this->refusal($failed), 'a second Send again queues nothing');

        $posts = count($this->posts('/api/autoresponder.php'));
        self::assertSame(1, $this->flush());

        self::assertSame('sent', self::stored($copy)['status']);
        $sent = $this->posts('/api/autoresponder.php');
        self::assertCount($posts + 1, $sent);
        $last = end($sent);
        self::assertTrue($last['authorized']);
        self::assertSame('shopper1@example.test', $last['body']['addresses'][0]['email']);
        self::assertStringNotContainsString('_resend', (string) json_encode($last['body']), 'the record never reaches Smaily');
        self::assertSame([$failed => ResendGuard::REASON_SUPERSEDED], $this->refusal($failed), 'the welcome reached the contact');
        self::assertSame('failed', self::stored($failed)['status']);
    }

    public function testSendAgainOfAReminderMovesItsCartToTheNewRow(): void
    {
        $idCart = $this->fixture->cart($this->fixture->customer(self::ADDRESS, false, true), 20);
        $this->mock->acceptCredentials('api-user', 'changed-in-smaily');
        $this->sweep();
        $this->flush();
        $failed = self::stored((int) $this->cartRow($idCart)['event_id']);
        self::assertSame(['failed', CartTracker::OPEN], [$failed['status'], $this->cartRow($idCart)['status']]);
        $this->mock->acceptCredentials('api-user', self::PASSWORD);

        $copy = (new Resend($this->queue(), new CartTracker()))->resend($failed, 'Testfirst Testlast');

        self::assertSame([CartTracker::OPEN, $copy], [$this->cartRow($idCart)['status'], (int) $this->cartRow($idCart)['event_id']]);
        $this->flush();
        self::assertSame(['sent', null], [self::stored($copy)['status'], self::stored($copy)['last_error']]);
        self::assertCount(1, $this->reminders());
        self::assertSame(CartTracker::MAILED, $this->cartRow($idCart)['status']);
    }

    public function testRetryReturnsFailedRowsToPendingOnceAndCountsTheRowsItLeavesAlone(): void
    {
        $failed = ['status' => 'failed', 'attempts' => 5, 'next_retry_at' => null, 'last_error' => 'Smaily API request failed with HTTP 500'];
        $sync = $this->sync('shopper1@example.test', $failed);
        $welcome = $this->welcome('shopper2@example.test', $failed);
        $superseded = $this->welcome('shopper3@example.test', $failed);
        $this->welcome('shopper3@example.test', ['status' => 'sent']);
        $erased = $this->sync('shopper4@example.test', ['entity_id' => ResendGuard::ERASED_ENTITY] + $failed);
        $delivered = $this->sync('shopper5@example.test', ['status' => 'sent']);
        $otherShop = $this->sync('shopper6@example.test', ['id_shop' => $this->idShop + 100] + $failed);
        $rows = new LogRows([$this->idShop], static fn (string $term): array => FailureMessage::codesShowing($term, static fn (string $text): string => $text));
        $retry = new Retry($rows, new ResendGuard(), $this->queue());

        self::assertSame([1, 0], $retry->retry(new LogFilter(), [$sync, $delivered, $otherShop]), 'the ticked rows: one failed row of this shop');
        $row = self::stored($sync);
        self::assertSame(['pending', 0, null, 'Smaily API request failed with HTTP 500'], [$row['status'], (int) $row['attempts'], $row['next_retry_at'], $row['last_error']]);
        self::assertSame('sent', self::stored($delivered)['status']);
        self::assertSame('failed', self::stored($otherShop)['status'], 'a row of another shop is not touched');
        self::assertSame([0, 0], $retry->retry(new LogFilter(), [$sync, $delivered, $otherShop]), 'a second retry finds nothing failed');

        self::assertSame([1, 1], $retry->retry(new LogFilter(['type' => 'automation']), null), 'every row of the filter: one retried, the superseded one skipped');
        self::assertSame(['pending', 'failed'], [self::stored($welcome)['status'], self::stored($superseded)['status']]);
        self::assertSame([0, 2], $retry->retry(new LogFilter(), null), 'no filter: the two rows the guard refuses remain');
        self::assertSame('failed', self::stored($erased)['status']);
        self::assertSame([0, 0], $retry->retry(new LogFilter(['status' => 'sent']), null));

        self::assertSame(2, $this->flush());
        self::assertSame(['sent', 'sent'], [self::stored($sync)['status'], self::stored($welcome)['status']], 'the next flush delivers the retried rows');
        self::assertSame('shopper1@example.test', $this->posts('/api/contact.php')[0]['body'][0]['email']);
    }

    /**
     * @param array<string, mixed> $columns stored as given, over a pending row
     */
    private function sync(string $email, array $columns = []): int
    {
        $id = $this->queue()->enqueue('contact.sync', $this->idShop, [
            'email' => $email,
            'source' => 'customer',
            'subscribed' => true,
            'consent_changed' => false,
            'customer' => ['firstname' => 'Testfirst', 'lastname' => 'Testlast'],
        ], $email, 'en');

        return $this->store($id, $columns);
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function welcome(string $email, array $columns = []): int
    {
        $id = $this->queue()->enqueue('automation.welcome', $this->idShop, ['email' => $email, 'fields' => ['first_name' => 'Testfirst']], $email, 'en');

        return $this->store($id, $columns);
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function store(int $id, array $columns): int
    {
        if ($columns !== []) {
            self::db()->update(EventQueue::TABLE, array_map(static fn ($value) => $value === null ? null : pSQL((string) $value), $columns), '`id` = ' . $id, 0, true);
        }

        return $id;
    }

    private function queue(): EventQueue
    {
        return $this->services->get(QueueProvider::EVENT_QUEUE);
    }

    /**
     * @return array<int, string>
     */
    private function refusal(int $id): array
    {
        return (new ResendGuard())->refusals([$id => self::stored($id)]);
    }

    /**
     * @return array<string, string|null>
     */
    private static function stored(int $id): array
    {
        return self::db()->getRow('SELECT * FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `id` = ' . $id, false);
    }

    /**
     * The POST requests that the mock received on a path.
     *
     * @return list<array<string, mixed>>
     */
    private function posts(string $path): array
    {
        return array_values(array_filter(
            $this->mock->requests(),
            static fn (array $request): bool => $request['method'] === 'POST' && $request['path'] === $path
        ));
    }
}
