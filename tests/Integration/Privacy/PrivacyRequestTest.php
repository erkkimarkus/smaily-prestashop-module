<?php
declare(strict_types=1);

namespace Tests\Integration\Privacy;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\GdprPaceSettings;
use PrestaShop\Module\SmailyForPrestaShop\Discount\CodeIssuer;
use PrestaShop\Module\SmailyForPrestaShop\Discount\TemplateRules;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\CustomerDeleteHandler;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\GdprPace;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\PrivacyRequest;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingRecord;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Crypto;

require_once __DIR__ . '/PrivacyTestCase.php';

/**
 * A privacy request in the shop against the engine mock: the export lists the module's own rows of
 * one shopper and each connection's answer (contract §8); the erasure deletes what could still be
 * sent, clears what is kept, removes the unused discount codes and queues the engine's deletion
 * (contract §9) once per connection, on the retry ladder; and PrestaShop's GDPR module gets both
 * through its hooks. Nothing of an engine's error text is kept, and nothing of another shopper is
 * read or changed.
 */
final class PrivacyRequestTest extends PrivacyTestCase
{
    private const SHOPPER = 'shopper1@example.test';

    private const OTHER = 'shopper2@example.test';

    /** 70 characters: its queue rows are stored under the keyed hash. */
    private const LONG = 'shopper.having.a.very.long.invented.name@a-long-subdomain.example.test';

    private const REMOTE_TEXT = 'Remote text about shopper1@example.test';

    public function testAnExportListsTheStoresRowsAndEachConnectionsAnswer(): void
    {
        $history = $this->history();
        $first = $this->connect();
        $second = $this->connectAlso($this->idShop + 7);

        $export = $this->services->get(PrivacyRequest::class)->export(' Shopper1@Example.test ', 0);

        $store = $export['store'];
        self::assertSame(['queue', 'carts', 'attribution', 'cart_attribution', 'codes', 'preference'], array_keys($store));
        self::assertSame(['type', 'status', 'created_at'], array_keys($store['queue'][0]), 'what was queued and when, never the message');
        $types = array_column($store['queue'], 'type');
        self::assertContains('contact.sync', $types);
        self::assertContains('automation.welcome', $types);
        self::assertSame(
            [[$history['reminded_cart'], 'mailed']],
            array_map(static fn (array $row): array => [$row['cart'], $row['status']], $store['carts'])
        );
        self::assertSame(
            [['order' => $history['order'], 'recommendation' => '11111111-2222-4333-8444-555555555555', 'context' => 'welcome', 'visitor_token' => 'vt_invented_token']],
            array_map(static fn (array $row): array => array_diff_key($row, ['created_at' => 1]), $store['attribution'])
        );
        self::assertSame(
            [[$history['unused_code'], false], [$history['used_code'], true]],
            array_map(static fn (array $row): array => [$row['code'], $row['used']], $store['codes']),
            'the codes of the account, each with whether an order used it'
        );
        self::assertSame(['code', 'created_at', 'expires_at', 'used'], array_keys($store['codes'][0]));
        self::assertSame([true, 'account'], [$store['preference']['opted_out'], $store['preference']['source']]);

        self::assertSame(
            [[$first->id(), 'ok', self::SHOPPER], [$second->id(), 'ok', self::SHOPPER]],
            array_map(static fn (array $answer): array => [$answer['connection'], $answer['status'], $answer['data']['customer']['email']], $export['campaign_intelligence']),
            'one answer per connection'
        );
        self::assertNotSame($export['campaign_intelligence'][0]['data']['export_metadata']['tenant_id'], $export['campaign_intelligence'][1]['data']['export_metadata']['tenant_id']);
        $gets = $this->engineRequests('GET', self::SHOPPER, '/export');
        self::assertCount(2, $gets, 'one attempt per connection, with the address in lower case');
        self::assertNotSame($gets[0]['key'], $gets[1]['key']);
        self::assertStringNotContainsString('shopper2', (string) json_encode($export), 'nothing of another shopper');
        self::assertStringNotContainsString($history['others_code'], (string) json_encode($export));
    }

    public function testAnEngineThatFailsLeavesNotAvailableAndNothingOfItsText(): void
    {
        $this->row(self::SHOPPER);
        $first = $this->connect();
        $second = $this->connectAlso($this->idShop + 7);
        $logged = (int) self::db()->getValue('SELECT COALESCE(MAX(`id_log`), 0) FROM `' . _DB_PREFIX_ . 'log`');

        $this->engine->fail('customer_export', 500, 'internal_error', self::REMOTE_TEXT);
        $export = $this->services->get(PrivacyRequest::class)->export(self::SHOPPER, 0);

        self::assertSame(
            [['connection' => $first->id(), 'status' => 'not_available', 'data' => null], ['connection' => $second->id(), 'status' => 'not_available', 'data' => null]],
            $export['campaign_intelligence']
        );
        self::assertCount(1, $export['store']['queue'], 'the store\'s rows are exported all the same');
        self::assertStringNotContainsString('Remote text', (string) json_encode($export));
        $lines = self::db()->executeS('SELECT `message` FROM `' . _DB_PREFIX_ . 'log` WHERE `id_log` > ' . $logged, true, false) ?: [];
        self::assertCount(2, $lines, 'each failure leaves one line in the shop\'s log');
        self::assertStringStartsWith('Smaily Connect: the Campaign Intelligence export of a privacy request failed with ', $lines[0]['message']);
        self::assertStringNotContainsString('Remote text', (string) json_encode($lines));
        self::assertStringNotContainsString('shopper1', (string) json_encode($lines));
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'log` WHERE `id_log` > ' . $logged);
        self::assertCount(2, $this->engineRequests('GET', self::SHOPPER, '/export'), 'one attempt each, no retry');

        $this->engine->set('fail', []);
        $this->engine->set('unknown_customers', [self::SHOPPER]);
        $export = $this->services->get(PrivacyRequest::class)->export(self::SHOPPER, 0);
        self::assertSame(['no_data', 'no_data'], array_column($export['campaign_intelligence'], 'status'), 'HTTP 404: the engine holds nothing of the shopper');
        self::assertStringNotContainsString('No customer with the email', (string) json_encode($export), 'the mock\'s 404 names the address in its message');
    }

    public function testAnErasureDeletesWhatCouldStillGoAndClearsWhatIsKept(): void
    {
        $history = $this->history();
        $first = $this->connect();
        $second = $this->connectAlso($this->idShop + 7);
        $sent = $this->row(self::SHOPPER, ['updated_at' => '2026-11-01 10:00:00']);
        $failed = $this->row('Shopper1@Example.test', ['event_type' => 'automation.welcome', 'status' => 'failed', 'attempts' => 5, 'last_error' => 'Smaily API request failed with HTTP 500']);
        $skipped = $this->row(self::SHOPPER, ['status' => 'skipped', 'last_error' => 'not_in_audience', 'last_request' => null, 'last_response' => null]);
        $pending = $this->row(self::SHOPPER, ['status' => 'pending', 'last_request' => null, 'last_response' => null]);
        $processing = $this->row(self::SHOPPER, ['status' => 'processing', 'claim_token' => str_repeat('a', 32)]);
        $choice = $this->row(self::SHOPPER, ['event_type' => 'engine.profiling_consent', 'entity_id' => ContactEntity::key(self::SHOPPER), 'payload' => '{"connection":1,"email":"shopper1@example.test","opt_out":true}']);
        $long = $this->row(self::LONG, ['entity_id' => ContactEntity::of(self::LONG)]);
        $others = $this->row(self::OTHER);
        $before = ['queue' => count(self::table(EventQueue::TABLE)), 'cart_rules' => $this->cartRules(), 'customers' => $this->rowCount('customer'), 'orders' => $this->rowCount('orders'), 'carts' => $this->rowCount('cart')];
        $othersRow = self::stored($others);

        $erased = $this->services->get(PrivacyRequest::class)->erase(self::SHOPPER, 0, $this->idShop);

        self::assertSame(2, $erased['engine_queued']);
        self::assertSame(1, $erased['codes_removed'], 'the unused code');
        self::assertSame(1, $erased['codes_kept'], 'the used code stays with its order');
        self::assertSame(1, $erased['attribution']);
        self::assertSame(2, $erased['carts'], 'the reminded cart, and the cart that the next sweep would have picked up');
        self::assertGreaterThanOrEqual(2, $erased['queue_removed']);
        self::assertGreaterThanOrEqual(4, $erased['queue_cleared']);

        // The rows that could still be sent are gone; the closed rows are kept without the contact.
        self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `id` IN (' . $pending . ', ' . $processing . ')', false));
        foreach ([$sent => 'sent', $failed => 'failed', $skipped => 'skipped', $choice => 'sent'] as $id => $status) {
            $row = self::stored($id);
            self::assertSame(
                [$status, '[erased]', '[]', null, null],
                [$row['status'], $row['entity_id'], $row['payload'], $row['last_request'], $row['last_response']],
                'row ' . $id
            );
        }
        self::assertSame('2026-11-01 10:00:00', self::stored($sent)['updated_at'], 'the retention of a kept row counts on from its last outcome');
        self::assertSame('Smaily API request failed with HTTP 500', self::stored($failed)['last_error']);
        self::assertSame($othersRow, self::stored($others), 'another shopper\'s row is untouched');
        self::assertSame(ContactEntity::of(self::LONG), self::stored($long)['entity_id'], 'and so is the row of another address under its keyed hash');

        // The Log refuses to send an erased row again.
        self::assertSame([$failed => ResendGuard::REASON_ERASED], (new ResendGuard())->refusals([$failed => self::stored($failed)]));

        // The engine's deletion waits in the queue, once per connection, without a readable address.
        $deletes = array_values(array_filter(self::table(EventQueue::TABLE, '`id`'), static fn (array $row): bool => $row['event_type'] === CustomerDeleteHandler::EVENT_TYPE));
        self::assertSame(
            [[$this->idShop, '[erased]', 'pending', $first->id()], [$this->idShop + 7, '[erased]', 'pending', $second->id()]],
            array_map(static fn (array $row): array => [(int) $row['id_shop'], $row['entity_id'], $row['status'], json_decode((string) $row['payload'], true)['connection']], $deletes)
        );
        $secret = json_decode((string) $deletes[0]['payload'], true)['address_secret'];
        self::assertSame(self::SHOPPER, $this->services->get(Crypto::class)->decrypt($secret), 'the row holds the address encrypted, for the call');
        self::assertStringNotContainsString('shopper1', (string) json_encode(self::table(EventQueue::TABLE)), 'no queue row holds the address in the clear');
        self::assertStringNotContainsString('Testfirst', (string) json_encode(array_filter(self::table(EventQueue::TABLE), static fn (array $row): bool => $row['entity_id'] === '[erased]')));

        // The cart tracker: the shopper's rows are tombstones, and an active cart got one.
        $reminded = (new CartTracker())->row($history['reminded_cart']);
        self::assertSame(['erased', null, null, null], [$reminded['status'], $reminded['email'], $reminded['id_customer'], $reminded['event_id']]);
        $tombstone = (new CartTracker())->row($history['active_cart']);
        self::assertSame(['erased', null, null], [$tombstone['status'], $tombstone['email'], $tombstone['id_customer']]);
        $othersCart = (new CartTracker())->row($history['others_cart']);
        self::assertSame(['mailed', self::OTHER], [$othersCart['status'], $othersCart['email']]);
        self::assertSame(0, $this->sweep(), 'no reminder is queued for an erased cart');

        // Attribution: the browser's token is gone, what led to the sale stays.
        self::assertSame(
            [['id_order' => (string) $history['order'], 'rec_id' => '11111111-2222-4333-8444-555555555555', 'context' => 'welcome', 'visitor_token' => null]],
            array_map(static fn (array $row): array => array_map(static fn ($value) => $value === null ? null : (string) $value, array_diff_key($row, ['created_at' => 1])), self::table(self::ATTRIBUTION_TABLE))
        );

        // Discount codes: only the shopper's unused code went.
        $codes = array_column(self::db()->executeS('SELECT `code` FROM `' . _DB_PREFIX_ . 'cart_rule`', true, false) ?: [], 'code');
        self::assertNotContains($history['unused_code'], $codes);
        self::assertContains($history['used_code'], $codes);
        self::assertContains($history['others_code'], $codes);
        self::assertSame($before['cart_rules'] - 1, $this->cartRules(), 'the template and every other cart rule stay');

        // What the design keeps: PrestaShop's own rows, and the personalization record.
        self::assertSame([$before['customers'], $before['orders'], $before['carts']], [$this->rowCount('customer'), $this->rowCount('orders'), $this->rowCount('cart')]);
        $record = self::table(ProfilingRecord::TABLE);
        self::assertSame([ContactEntity::key(self::SHOPPER), 1], [$record[0]['address_key'], (int) $record[0]['opted_out']], 'the opt-out stays in force, under the keyed hash');

        // A second erasure finds nothing of the shopper and queues the deletion anew.
        $again = $this->services->get(PrivacyRequest::class)->erase(self::SHOPPER, 0, $this->idShop);
        self::assertSame(['queue_removed' => 0, 'queue_cleared' => 0, 'carts' => 0, 'attribution' => 0, 'codes_removed' => 0, 'codes_kept' => 1, 'engine_queued' => 2], $again);
    }

    public function testWhatAShoppersCartsKeptIsExportedAndRemovedByAnErasure(): void
    {
        $history = $this->history();
        // A cart that only the tracker ties to the address: a visitor who typed it at the checkout.
        $typed = $this->fixture->cart(0, 10);
        self::db()->insert('smaily_connect_cart', [
            'id_cart' => $typed,
            'id_shop' => $this->idShop,
            'email' => 'Shopper1@Example.test',
            'id_lang' => (int) \Configuration::get('PS_LANG_DEFAULT'),
            'status' => CartTracker::OPEN,
            'last_activity_at' => date('Y-m-d H:i:s', time() - 600),
        ]);
        foreach ([$history['active_cart'], $typed, $history['others_cart']] as $idCart) {
            self::db()->insert(self::CART_ATTRIBUTION_TABLE, [
                'id_cart' => $idCart,
                'rec_id' => '11111111-2222-4333-8444-555555555555',
                'context' => 'storefront',
                'visitor_token' => 'vt_invented_token',
                'updated_at' => '2026-11-22 10:00:00',
            ]);
        }

        $store = $this->services->get(PrivacyRequest::class)->export(self::SHOPPER, 0)['store'];

        self::assertSame(['queue', 'carts', 'attribution', 'cart_attribution', 'codes', 'preference'], array_keys($store));
        $row = ['recommendation' => '11111111-2222-4333-8444-555555555555', 'context' => 'storefront', 'visitor_token' => 'vt_invented_token', 'updated_at' => '2026-11-22 10:00:00'];
        self::assertSame(
            [['cart' => $history['active_cart']] + $row, ['cart' => $typed] + $row],
            $store['cart_attribution'],
            'the carts of the account and the cart of the typed address, not another shopper\'s'
        );

        $erased = $this->services->get(PrivacyRequest::class)->erase(self::SHOPPER, 0, $this->idShop);

        self::assertSame(3, $erased['attribution'], 'the order\'s token and the two carts\' rows');
        self::assertSame([$history['others_cart']], array_map('intval', array_column(self::table(self::CART_ATTRIBUTION_TABLE, '`id_cart`'), 'id_cart')));
        self::assertSame([], $this->services->get(PrivacyRequest::class)->export(self::SHOPPER, 0)['store']['cart_attribution']);
    }

    public function testTheEnginesDeletionGoesOncePerConnectionAndAgainAfterAFailure(): void
    {
        $first = $this->connect();
        $second = $this->connectAlso($this->idShop + 7);
        $this->engine->fail('customer_delete', 503, 'internal_error', self::REMOTE_TEXT);

        $this->services->get(PrivacyRequest::class)->erase(self::SHOPPER, 0, $this->idShop);
        self::assertSame(2, $this->flush());

        $rows = self::table(EventQueue::TABLE, '`id`');
        foreach ($rows as $row) {
            self::assertSame(['pending', 1, 'Campaign Intelligence answered HTTP 503 (internal_error).'], [$row['status'], (int) $row['attempts'], $row['last_error']]);
            self::assertNotNull($row['next_retry_at'], 'the deletion waits for its next attempt');
            self::assertArrayHasKey('address_secret', json_decode((string) $row['payload'], true), 'and keeps the address it needs');
        }
        self::assertStringNotContainsString('Remote text', (string) json_encode($rows));
        self::assertCount(2, $this->engineRequests('DELETE', self::SHOPPER));
        self::assertSame(0, $this->flush(), 'nothing is due before the ladder\'s step');

        $this->engine->set('fail', []);
        self::makeDue();
        self::assertSame(2, $this->flush());

        $rows = self::table(EventQueue::TABLE, '`id`');
        self::assertSame(
            [['sent', null, ['connection' => $first->id()]], ['sent', null, ['connection' => $second->id()]]],
            array_map(static fn (array $row): array => [$row['status'], $row['last_error'], json_decode((string) $row['payload'], true)], $rows),
            'a delivered row no longer holds the address'
        );
        $deletes = $this->engineRequests('DELETE', self::SHOPPER);
        self::assertCount(4, $deletes, 'two failed attempts, then one delivery per connection');
        self::assertSame(['confirm' => true, 'reason' => 'user_request'], $deletes[2]['body']);
        self::assertNotSame($deletes[2]['key'], $deletes[3]['key']);
        self::assertNull($rows[0]['last_request'], 'the row keeps no request');

        // The engine has deleted the shopper: a later deletion meets HTTP 404, which is done.
        $this->services->get(PrivacyRequest::class)->erase(self::SHOPPER, 0, $this->idShop);
        self::assertSame(2, $this->flush());
        self::assertSame(['sent', 'sent', 'sent', 'sent'], array_column(self::table(EventQueue::TABLE, '`id`'), 'status'));
    }

    public function testADeletionThatIsParkedOrSkippedLosesItsAddress(): void
    {
        $connection = $this->connect();
        $this->engine->set('tenant_inactive', true);

        $this->services->get(PrivacyRequest::class)->erase(self::SHOPPER, 0, $this->idShop);
        self::assertSame(1, $this->flush());

        $parked = self::table(EventQueue::TABLE)[0];
        self::assertSame(
            ['failed', 'permanent_http_403: Campaign Intelligence answered HTTP 403 (tenant_inactive).', ['connection' => $connection->id()]],
            [$parked['status'], $parked['last_error'], json_decode((string) $parked['payload'], true)]
        );
        self::assertSame([(int) $parked['id'] => ResendGuard::REASON_ERASED], (new ResendGuard())->refusals([(int) $parked['id'] => $parked]), 'the Log cannot send it again; a new erasure queues the call anew');

        $this->engine->set('tenant_inactive', false);
        $this->services->get(PrivacyRequest::class)->erase(self::SHOPPER, 0, $this->idShop);
        $this->services->get(Connections::class)->delete($connection);
        self::assertSame(1, $this->flush());
        $skipped = self::table(EventQueue::TABLE, '`id`')[1];
        self::assertSame(['skipped', 'no_connection', ['connection' => $connection->id()]], [$skipped['status'], $skipped['last_error'], json_decode((string) $skipped['payload'], true)]);
        self::assertCount(1, $this->engineRequests('DELETE', self::SHOPPER), 'the refused attempt alone: a row without a connection calls nobody');
    }

    public function testADeletionThatMeetsA429KeepsItsAddressAndItsAttemptsAndAnExportStillGoes(): void
    {
        $connection = $this->connect();
        $this->engine->fail('customer_delete', 429, 'rate_limit_exceeded', self::REMOTE_TEXT);

        $this->services->get(PrivacyRequest::class)->erase(self::SHOPPER, 0, $this->idShop);
        $before = time();
        self::assertSame(1, $this->flush());

        $row = self::table(EventQueue::TABLE)[0];
        self::assertSame(
            ['pending', 0, 'Campaign Intelligence answered HTTP 429 (rate_limit_exceeded).'],
            [$row['status'], (int) $row['attempts'], $row['last_error']],
            'the erasure is never dropped and spends no attempt'
        );
        self::assertArrayHasKey('address_secret', json_decode((string) $row['payload'], true), 'it keeps the address it needs');
        self::assertGreaterThanOrEqual($before + GdprPace::DEFAULT_PAUSE_SECONDS, strtotime($row['next_retry_at'] . ' UTC'), 'a 429 without a wait holds a minute');

        // The export goes at once, also while the store's requests are held, and is counted.
        $export = $this->services->get(PrivacyRequest::class)->export(self::SHOPPER);
        self::assertSame(['ok'], array_column($export['campaign_intelligence'], 'status'));
        $stored = json_decode((string) \Configuration::getGlobalValue(GdprPaceSettings::CALLS), true);
        self::assertCount(2, $stored[$connection->tenantId()]['calls'], 'the deletion and the export');

        $this->engine->set('fail', []);
        $this->passSeconds(GdprPace::DEFAULT_PAUSE_SECONDS);
        self::makeDue();
        self::assertSame(1, $this->flush());
        $row = self::table(EventQueue::TABLE)[0];
        self::assertSame(['sent', 0, ['connection' => $connection->id()]], [$row['status'], (int) $row['attempts'], json_decode((string) $row['payload'], true)]);
        self::assertStringNotContainsString('Remote text', (string) json_encode(self::table(EventQueue::TABLE)));
    }

    public function testPrestaShopsGdprModuleGetsTheExportAndTheErasureThroughItsHooks(): void
    {
        $history = $this->history();
        $connection = $this->connect();
        $this->hooksUseTheMocks();
        $module = self::module();
        $customer = new \Customer($history['customer']);

        // As psgdpr calls it: the customer as an array, for this module alone.
        $rows = json_decode((string) \Hook::exec('actionExportGDPRData', (array) $customer, (int) $module->id), true);

        self::assertIsArray($rows);
        $kinds = array_count_values(array_column($rows, 'Data'));
        self::assertSame(1, $kinds['Abandoned cart']);
        self::assertSame(1, $kinds['Order attribution']);
        self::assertSame(2, $kinds['Discount code']);
        self::assertSame(1, $kinds['Personalization preference']);
        self::assertSame(1, $kinds['Campaign Intelligence']);
        self::assertGreaterThanOrEqual(2, $kinds['Queued message']);
        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                self::assertIsString($column);
                self::assertTrue(is_scalar($value), 'psgdpr prints a row as flat columns');
            }
        }
        $engine = array_values(array_filter($rows, static fn (array $row): bool => $row['Data'] === 'Campaign Intelligence'))[0];
        self::assertSame($connection->id(), $engine['Connection']);
        self::assertSame(self::SHOPPER, json_decode($engine['Content'], true)['customer']['email']);
        self::assertStringNotContainsString('shopper2', (string) json_encode($rows));

        self::assertSame('[]', \Hook::exec('actionExportGDPRData', ['phone' => '5550100'], (int) $module->id), 'a phone number names nobody the module knows');
        $guest = json_decode((string) \Hook::exec('actionExportGDPRData', ['email' => self::OTHER], (int) $module->id), true);
        self::assertContains('Queued message', array_column($guest, 'Data'), 'a request for an address alone');

        self::assertSame('true', \Hook::exec('actionDeleteGDPRCustomer', (array) $customer, (int) $module->id));

        self::assertStringNotContainsString('shopper1', (string) json_encode(self::table(EventQueue::TABLE)));
        self::assertSame('erased', (new CartTracker())->row($history['reminded_cart'])['status']);
        self::assertNotContains($history['unused_code'], array_column(self::db()->executeS('SELECT `code` FROM `' . _DB_PREFIX_ . 'cart_rule`', true, false) ?: [], 'code'));
        self::assertSame(1, $this->flush());
        self::assertCount(1, $this->engineRequests('DELETE', self::SHOPPER));
        self::assertSame('true', \Hook::exec('actionDeleteGDPRCustomer', ['phone' => '5550100'], (int) $module->id));
    }

    public function testAnAddressWithACapitalOutsideAsciiIsFoundUnderTheHashOfItsWriters(): void
    {
        // The writers take the keyed hash of the address as given; it lowers ASCII letters only.
        $given = 'Ärni.Shopper@example.test';
        $this->services->get(ProfilingRecord::class)->write($given, true, ProfilingRecord::SOURCE_ACCOUNT);
        $choice = $this->row($given, ['event_type' => 'engine.profiling_consent', 'entity_id' => ContactEntity::key($given), 'payload' => '{"connection":1,"opt_out":true}']);

        $export = $this->services->get(PrivacyRequest::class)->export($given, 0);
        self::assertTrue($export['store']['preference']['opted_out']);
        self::assertSame(['engine.profiling_consent'], array_column($export['store']['queue'], 'type'));

        $this->services->get(PrivacyRequest::class)->erase($given, 0, $this->idShop);
        self::assertSame('[erased]', self::stored($choice)['entity_id']);
    }

    public function testAnErasedCartRowLeavesWithTheRetentionOfTheClosedRows(): void
    {
        $customer = $this->fixture->customer(self::SHOPPER);
        $old = $this->fixture->cart($customer, 60 * 24 * 31);
        $recent = $this->fixture->cart($customer, 60 * 24 * 29);
        foreach ([$old => 31, $recent => 29] as $idCart => $days) {
            self::db()->insert('smaily_connect_cart', [
                'id_cart' => $idCart,
                'id_shop' => $this->idShop,
                'id_customer' => $customer,
                'email' => self::SHOPPER,
                'id_lang' => (int) \Configuration::get('PS_LANG_DEFAULT'),
                'status' => CartTracker::OPEN,
                'last_activity_at' => date('Y-m-d H:i:s', time() - $days * 86400),
            ]);
        }
        $tracker = new CartTracker();

        self::assertSame(2, $tracker->erase(self::SHOPPER, [$customer]));
        self::assertSame(0, $tracker->purge(), 'an erased row holds no address to purge');
        self::assertSame(1, $tracker->deleteClosed(10), 'the janitor deletes it 30 days after the cart\'s last change, as a closed row');

        self::assertNull($tracker->row($old));
        self::assertSame(CartTracker::ERASED, $tracker->row($recent)['status']);
        self::assertSame([], $tracker->ofShopper(self::SHOPPER, [$customer]), 'nothing ties an erased row to the shopper');
    }

    /**
     * A shopper with a history in every table the module keeps, and another shopper beside:
     * queue rows, a reminded cart and an active one, an order with attribution, an unused and a
     * used discount code of the account, and an opt-out.
     *
     * @return array{customer: int, order: int, reminded_cart: int, active_cart: int, others_cart: int, unused_code: string, used_code: string, others_code: string}
     */
    private function history(): array
    {
        $template = $this->template();
        $customer = $this->fixture->customer(self::SHOPPER, false, true);
        $other = $this->fixture->customer(self::OTHER, false, true);
        $unused = $this->issue($template, $customer);
        $used = $this->issue($template, $customer);
        $othersCode = $this->issue($template, $other);

        $ordered = $this->fixture->cart($customer, 1);
        (new \Cart($ordered))->addCartRule((int) \CartRule::getIdByCode($used));
        $order = $this->fixture->order($ordered);
        self::db()->insert(self::ATTRIBUTION_TABLE, [
            'id_order' => $order,
            'rec_id' => '11111111-2222-4333-8444-555555555555',
            'context' => 'welcome',
            'visitor_token' => 'vt_invented_token',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        // The order's own hooks queued rows through the shop's services; the test starts from its own.
        self::db()->delete(EventQueue::TABLE);
        self::db()->delete('smaily_connect_cart');

        $this->row(self::SHOPPER);
        $this->row(self::SHOPPER, ['event_type' => 'automation.welcome', 'payload' => (string) json_encode(['email' => self::SHOPPER, 'fields' => ['welcome_coupon_code' => $unused]])]);
        $this->row(self::OTHER);

        $reminded = $this->fixture->cart($customer, 60 * 30);
        $othersCart = $this->fixture->cart($other, 60 * 30);
        foreach ([[$reminded, $customer, self::SHOPPER], [$othersCart, $other, self::OTHER]] as [$idCart, $idCustomer, $email]) {
            self::db()->insert('smaily_connect_cart', [
                'id_cart' => $idCart,
                'id_shop' => $this->idShop,
                'id_customer' => $idCustomer,
                'email' => $email,
                'id_lang' => (int) \Configuration::get('PS_LANG_DEFAULT'),
                'status' => CartTracker::MAILED,
                'last_activity_at' => date('Y-m-d H:i:s', time() - 30 * 3600),
                'mailed_at' => date('Y-m-d H:i:s', time() - 29 * 3600),
            ]);
        }
        $active = $this->fixture->cart($customer, 30);
        $this->services->get(ProfilingRecord::class)->write(self::SHOPPER, true, ProfilingRecord::SOURCE_ACCOUNT);

        return [
            'customer' => $customer,
            'order' => $order,
            'reminded_cart' => $reminded,
            'active_cart' => $active,
            'others_cart' => $othersCart,
            'unused_code' => $unused,
            'used_code' => $used,
            'others_code' => $othersCode,
        ];
    }

    /**
     * A code of the welcome automation, tied to the customer account, as the issuer makes it.
     */
    private function issue(int $template, int $idCustomer): string
    {
        $code = (new CodeIssuer(new TemplateRules()))->issue($template, 7, $this->idShop, $idCustomer, Trigger::WELCOME);
        self::assertNotNull($code);

        return $code['code'];
    }

    private function cartRules(): int
    {
        return $this->rowCount('cart_rule');
    }

    private function rowCount(string $table): int
    {
        return (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . $table . '`', false);
    }
}
