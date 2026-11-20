<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Migration\LegacyStore;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/AbandonedCartTestCase.php';
require_once __DIR__ . '/../../MultistoreShop.php';

/**
 * Proof 7: a reminder that the automation's switch, its workflow or its shop's account does not
 * allow ends skipped with its reason, and nothing goes through another account.
 */
final class RoutingSkipTest extends AbandonedCartTestCase
{
    use MultistoreShop;

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_') && $this->addedShops !== []) {
            foreach ($this->addedShops as $idShop) {
                self::db()->delete('configuration', '`id_shop` = ' . (int) $idShop . ' AND `name` LIKE \'SMAILY\\\\_%\'');
            }
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    public function testTheAutomationOffQueuesNothingAndSkipsWhatWasQueued(): void
    {
        $settings = new AutomationSettings();
        $settings->setEnabled(Trigger::ABANDONED_CART, false, 0);
        LegacyStore::reloadConfiguration();
        $idCart = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 20);
        self::assertSame(0, $this->sweep());
        self::assertNull($this->cartRow($idCart));

        $settings->setEnabled(Trigger::ABANDONED_CART, true, 0);
        LegacyStore::reloadConfiguration();
        self::assertSame(1, $this->sweep());
        $settings->setEnabled(Trigger::ABANDONED_CART, false, 0);
        LegacyStore::reloadConfiguration();
        $this->flush();

        $this->assertSkipped($idCart, 'automation_off');
    }

    public function testNoWorkflowSkips(): void
    {
        (new MappingRepository())->delete(0, Trigger::ABANDONED_CART, '', MappingRepository::ACCOUNT_DEFAULT);
        $idCart = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 20);

        $this->sweep();
        $this->flush();

        $this->assertSkipped($idCart, 'no_workflow');
    }

    public function testASeparateStoresShopWithoutItsOwnAccountSendsNothingThroughAnother(): void
    {
        $this->openMultistore();
        $shopA = $this->defaultShopId();
        $shopB = $this->addShop((int) \Shop::getGroupFromShop($shopA));
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        $settings = new AutomationSettings();
        $mappings = new MappingRepository();
        foreach ([$shopA, $shopB] as $idShop) {
            $settings->setEnabled(Trigger::ABANDONED_CART, true, $idShop);
            $mappings->upsert($idShop, Trigger::ABANDONED_CART, '', MappingRepository::ACCOUNT_DEFAULT, 200 + $idShop);
        }
        Services::instance()->get(AccountStore::class)->save(new Account($shopA, '', 'shopa', 'user-a', 'not-a-real-password'));
        LegacyStore::reloadConfiguration();
        $this->mock->acceptCredentials('user-a', 'not-a-real-password');
        $this->services = $this->services();

        $cartA = $this->fixture->cart($this->fixture->customer(self::ADDRESS, false, false, '', $shopA), 20, 2, $shopA);
        $cartB = $this->fixture->cart($this->fixture->customer('shopper2@example.test', false, false, '', $shopB), 20, 2, $shopB);
        self::assertSame(2, $this->sweep());
        $this->flush();

        $requests = $this->mock->requests();
        self::assertSame(['user-a'], array_values(array_unique(array_column($requests, 'username'))), 'only shop A\'s account is used');
        self::assertSame([200 + $shopA], array_column(array_column($this->reminders(), 'body'), 'autoresponder'));
        self::assertSame(CartTracker::MAILED, $this->cartRow($cartA)['status']);
        self::assertSame([CartTracker::SKIPPED, 'no_account'], [$this->cartRow($cartB)['status'], $this->cartRow($cartB)['skip_reason']]);
        self::assertSame([['sent', null], ['skipped', 'no_account']], array_map(static fn (array $row): array => [$row['status'], $row['last_error']], $this->events()));
    }

    private function assertSkipped(int $idCart, string $reason): void
    {
        self::assertSame([], $this->reminders());
        self::assertSame([CartTracker::SKIPPED, $reason], [$this->cartRow($idCart)['status'], $this->cartRow($idCart)['skip_reason']]);
        $events = array_values(array_filter($this->events(), static fn (array $row): bool => $row['status'] === 'skipped'));
        self::assertSame([$reason], array_column($events, 'last_error'));
    }
}
