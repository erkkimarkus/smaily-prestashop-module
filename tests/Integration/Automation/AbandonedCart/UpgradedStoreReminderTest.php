<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartSweeper;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\QueueJanitor;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/AbandonedCartTestCase.php';

/**
 * A store that 2.2.0 left (tests/fixtures/legacy/2.2.0-configured.sql: reminder on, workflow 102,
 * delay 45 minutes; here with no box of the 2.x field picker ticked) reminds after the upgrade with
 * its own workflow and delay and with every template field, and does not remind a cart that 2.x
 * reminded while the cart's row is kept: 30 days, as every closed row of the tracker.
 */
final class UpgradedStoreReminderTest extends AbandonedCartTestCase
{
    private int $idCustomer;

    private int $legacyCart;

    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::clear();
        LegacyStore::loadFixture('2.2.0-configured.sql');
        // What 2.2.0 stores when the form is saved with no field ticked.
        self::db()->update(
            'configuration',
            ['value' => '{"first_name":false,"last_name":false,"name":false,"description":false,"sku":false,"price":false,"base_price":false,"quantity":false}'],
            '`name` = \'SMAILY_CART_SYNCRONIZE_ADDITIONAL\''
        );
        $this->idCustomer = $this->fixture->customer(self::ADDRESS);
        $this->legacyCart = $this->fixture->cart($this->idCustomer, 60 * 26);
        // 2.x reminded this cart: its SentCart row, written a day and a half ago.
        self::db()->insert('smaily_cart', [
            'id_customer' => $this->idCustomer,
            'id_cart' => $this->legacyCart,
            'date_sent' => date('Y-m-d H:i:s', time() - 36 * 3600),
        ]);
        self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'2.2.0\' WHERE `name` = \'smailyforprestashop\'');
        self::assertTrue(Services::fromProviders(Services::PROVIDERS)->get(Upgrade300::class)->runPending());
        // Subscribers only after the upgrade: Smaily holds the shopper as subscribed.
        $this->mock->setContacts([self::ADDRESS => '0']);
        $this->services = $this->services();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'3.0.0\' WHERE `name` = \'smailyforprestashop\'');
        }
        parent::tearDown();
    }

    /**
     * Proof 5 and acceptance 2: the migrated `mailed` row keeps its cart from a reminder, also
     * once the cart changes again and is due.
     */
    public function testACartThat2xRemindedIsNotRemindedAgainWhileItsRowIsKept(): void
    {
        self::assertSame([CartTracker::MAILED, null], [$this->cartRow($this->legacyCart)['status'], $this->cartRow($this->legacyCart)['email']]);
        $this->fixture->age($this->legacyCart, 50);

        self::assertSame(0, $this->sweep());
        $this->flush();

        self::assertSame([], $this->reminders());
        self::assertSame(CartTracker::MAILED, $this->cartRow($this->legacyCart)['status']);
    }

    /**
     * The janitor deletes the migrated row 30 days after the reminder that 2.x sent, as any other
     * closed row. The cart then has no reminder state: when its shopper changes it again, it is
     * reminded once more, and once only. 2.x's own table keeps its row.
     */
    public function testACartWhoseMigratedRowIsGoneIsRemindedOnceMoreWhenItChanges(): void
    {
        $sent = date('Y-m-d H:i:s', time() - 31 * 86400);
        self::db()->update('smaily_connect_cart', ['mailed_at' => $sent, 'last_activity_at' => $sent], '`id_cart` = ' . $this->legacyCart);

        self::assertSame(1, $this->services->get(QueueJanitor::class)->run(new Deadline(microtime(true) + 10, static fn (): float => microtime(true))));
        self::assertNull($this->cartRow($this->legacyCart));
        self::assertSame('1', (string) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_cart` WHERE `id_cart` = ' . $this->legacyCart, false));
        self::assertSame(0, $this->sweep(), 'the cart did not change: it is older than a day');

        $this->fixture->age($this->legacyCart, 50);
        self::assertSame(1, $this->sweep());
        $this->flush();

        self::assertCount(1, $this->reminders());
        self::assertSame(CartTracker::MAILED, $this->cartRow($this->legacyCart)['status']);
        self::assertSame(0, $this->sweep());
        self::assertSame(0, $this->flush());
        self::assertCount(1, $this->reminders());
    }

    /**
     * A customer that 2.x reminded in the last 24 hours gets no reminder for another cart then.
     */
    public function testA2xReminderOfTheLastDayBlocksTheCustomersOtherCart(): void
    {
        self::db()->update('smaily_connect_cart', ['mailed_at' => date('Y-m-d H:i:s', time() - 3600), 'last_activity_at' => date('Y-m-d H:i:s', time() - 3600)], '`id_cart` = ' . $this->legacyCart);
        $other = $this->fixture->cart($this->idCustomer, 50);

        $this->sweep();
        $this->flush();

        self::assertSame([], $this->reminders());
        self::assertSame([CartTracker::SKIPPED, AbandonedCartSweeper::SKIPPED_RECENTLY_REMINDED], [$this->cartRow($other)['status'], $this->cartRow($other)['skip_reason']]);
    }

    /**
     * Proof 8: the reminder uses the migrated workflow and delay, and carries the shopper's names
     * and every product field though 2.x had no field ticked.
     */
    public function testTheReminderUsesTheMigratedWorkflowAndDelayAndCarriesEveryField(): void
    {
        $other = $this->fixture->cart($this->idCustomer, 30);
        self::assertSame(0, $this->sweep(), 'the migrated delay is 45 minutes');

        $this->fixture->age($other, 50);
        self::assertSame(1, $this->sweep());
        $this->flush();

        $reminders = $this->reminders();
        self::assertCount(1, $reminders);
        self::assertSame([102, 'api-user'], [$reminders[0]['body']['autoresponder'], $reminders[0]['username']]);
        $address = $reminders[0]['body']['addresses'][0];
        self::assertSame(['Testfirst', 'Testlast'], [$address['first_name'], $address['last_name']]);
        foreach (['name', 'description', 'sku', 'price', 'base_price', 'quantity'] as $field) {
            self::assertNotSame('', $address['product_' . $field . '_1'], $field . ' of the first product');
            self::assertNotSame('', $address['product_' . $field . '_2'], $field . ' of the second product');
            self::assertSame('', $address['product_' . $field . '_3'], $field . ' of an unused slot');
        }
        self::assertFalse($address['over_10_products']);
    }

    /**
     * A 2.x delay of a day or more is 1435 minutes after the upgrade (AutomationSettingsTest): a
     * cart is due in the last five minutes before it is 24 hours old, and never after.
     */
    public function testADelayCappedAt1435MinutesRemindsACartOfItsLastFiveMinutes(): void
    {
        \Configuration::updateGlobalValue(AutomationSettings::CART_DELAY_MINUTES, '1435');
        LegacyStore::reloadConfiguration();
        $young = $this->fixture->cart($this->idCustomer, 600);
        $old = $this->fixture->cart($this->idCustomer, 1445);

        self::assertSame(0, $this->sweep());
        self::assertSame([null, null], [$this->cartRow($young), $this->cartRow($old)]);

        $due = $this->fixture->cart($this->idCustomer, 1437);
        self::assertSame(1, $this->sweep());
        $this->flush();

        self::assertCount(1, $this->reminders());
        self::assertSame(CartTracker::MAILED, $this->cartRow($due)['status']);
        self::assertSame([null, null], [$this->cartRow($young), $this->cartRow($old)]);
    }
}
