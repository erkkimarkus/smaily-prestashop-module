<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartSweeper;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/AbandonedCartTestCase.php';

/**
 * A store that 2.2.0 left (tests/fixtures/legacy/2.2.0-configured.sql: reminder on, workflow 102,
 * delay 45 minutes, every template field) reminds after the upgrade with its own workflow, delay
 * and fields, and never reminds a cart that 2.x reminded.
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
    public function testACartThat2xRemindedIsNeverRemindedAgain(): void
    {
        self::assertSame([CartTracker::MAILED, null], [$this->cartRow($this->legacyCart)['status'], $this->cartRow($this->legacyCart)['email']]);
        $this->fixture->age($this->legacyCart, 50);

        self::assertSame(0, $this->sweep());
        $this->flush();

        self::assertSame([], $this->reminders());
        self::assertSame(CartTracker::MAILED, $this->cartRow($this->legacyCart)['status']);
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
     * Proof 8: the reminder uses the migrated workflow, delay and field selection.
     */
    public function testTheReminderUsesTheMigratedWorkflowDelayAndFields(): void
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
            self::assertNotSame('', $address['product_' . $field . '_1'], $field . ' is selected');
        }
    }
}
