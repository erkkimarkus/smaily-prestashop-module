<?php
declare(strict_types=1);

namespace Tests\Integration\Discount;

use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Discount\CodeIssuer;
use PrestaShop\Module\SmailyForPrestaShop\Discount\IssuedCodes;
use PrestaShop\Module\SmailyForPrestaShop\Discount\TemplateRules;
use PrestaShop\Module\SmailyForPrestaShop\Install\Uninstaller;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/DiscountTestCase.php';

/**
 * The codes the module issued: the janitor removes the ones nobody used 30 days after their end
 * date and keeps a used one, a fresh one and every rule of the merchant; an uninstall leaves the
 * codes in place.
 */
final class IssuedCodesTest extends DiscountTestCase
{
    private const DAY = 86400;

    public function testTheJanitorRemovesAnUnusedCodeThirtyDaysPastItsEndAndKeepsTheOthers(): void
    {
        $template = $this->template();
        $merchants = $this->template(['date_to' => date('Y-m-d H:i:s', time() - 40 * self::DAY)]);
        $old = $this->issue($template, time() - 38 * self::DAY);
        $kept = $this->issue($template, time() - 30 * self::DAY);
        $fresh = $this->issue($template, time());
        $used = $this->issue($template, time());
        $idCart = $this->fixture->cart($this->fixture->customer('shopper1@example.test'), 1);
        (new \Cart($idCart))->addCartRule($used);
        $this->fixture->order($idCart);
        self::db()->update('cart_rule', ['date_to' => date('Y-m-d H:i:s', time() - 40 * self::DAY)], '`id_cart_rule` = ' . $used);

        self::assertSame(1, (new IssuedCodes())->deleteExpiredUnused());

        self::assertSame([$kept, $fresh, $used], self::issuedIds(), 'more than 30 days past its end goes; less, a fresh one and a used one stay');
        self::assertNotFalse(self::db()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_rule` WHERE `id_cart_rule` = ' . $merchants), 'a rule of the merchant is never removed');
        self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cart_rule_lang` WHERE `id_cart_rule` = ' . $old));
        self::assertSame(0, (new IssuedCodes())->deleteExpiredUnused());
        self::assertSame(1, (new IssuedCodes(static fn (): int => time() + 2 * self::DAY))->deleteExpiredUnused(), 'two days later the next one is due');
    }

    public function testTheJanitorRemovesABatchACall(): void
    {
        $template = $this->template();
        for ($count = 0; $count < 3; ++$count) {
            $this->issue($template, time() - 60 * self::DAY);
        }

        self::assertSame(2, (new IssuedCodes())->deleteExpiredUnused(2));
        self::assertCount(1, self::issuedIds());
    }

    public function testTheDailyJanitorJobRemovesTheCodes(): void
    {
        $this->issue($this->template(), time() - 60 * self::DAY);

        $this->services->get(Tick::class)->run('tick', 50);

        self::assertSame([], self::issuedIds());
    }

    public function testAnUninstallLeavesTheIssuedCodesInPlace(): void
    {
        $id = $this->issue($this->template(), time());

        try {
            self::assertTrue((new Uninstaller(Services::fromProviders([]), new LegacyOverride(_PS_OVERRIDE_DIR_)))->uninstall());
            self::assertSame([$id], self::issuedIds(), 'shoppers hold the codes in their emails');
        } finally {
            LegacyStore::restore();
        }
    }

    private function issue(int $template, int $now, int $idCustomer = 0): int
    {
        $clock = static fn (): int => $now;
        $code = (new CodeIssuer(new TemplateRules($clock), $clock))->issue($template, 1, $this->idShop, $idCustomer, Trigger::WELCOME);
        self::assertNotNull($code);

        return (int) \CartRule::getIdByCode($code['code']);
    }
}
