<?php
declare(strict_types=1);

namespace Tests\Integration\Discount;

use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Welcome\WelcomeTrigger;
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
 * date and keeps a used one, a fresh one and every rule of the merchant; a privacy request finds a
 * shopper's codes by customer account and by address and removes the unused ones; an uninstall
 * leaves the codes in place.
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

    public function testAShoppersCodesAreFoundByCustomerAccountAndByAddressAndTheUnusedOnesRemoved(): void
    {
        $template = $this->template();
        $this->discount(Trigger::WELCOME, $template);
        $customer = $this->fixture->customer('shopper1@example.test');
        $other = $this->fixture->customer('shopper2@example.test');
        $tied = $this->issue($template, time(), $customer);
        $usedTied = $this->issue($template, time(), $customer);
        $othersCode = $this->issue($template, time(), $other);
        $idCart = $this->fixture->cart($customer, 1);
        (new \Cart($idCart))->addCartRule($usedTied);
        $this->fixture->order($idCart);
        // A guest's code is tied to nobody: only its queue row names the address.
        $this->automationRow(WelcomeTrigger::EVENT_TYPE, 'Guest1@Example.test');
        $this->automationRow(WelcomeTrigger::EVENT_TYPE, 'guest2@example.test');
        $this->flush();
        $guestCode = $this->posted(0)['welcome_coupon_code'];
        $codes = new IssuedCodes();

        $ofCustomer = $codes->ofCustomer($customer);
        self::assertSame([$tied, $usedTied], array_column($ofCustomer, 'id'));
        self::assertSame([false, true], array_column($ofCustomer, 'used'));
        self::assertSame(['id', 'code', 'created_at', 'expires_at', 'used'], array_keys($ofCustomer[0]));
        self::assertSame((new \CartRule($tied))->code, $ofCustomer[0]['code']);
        self::assertSame((new \CartRule($tied))->date_to, $ofCustomer[0]['expires_at']);
        self::assertSame([], $codes->ofCustomer(0), 'an untied code is nobody\'s');

        $ofAddress = $codes->ofAddress('guest1@example.TEST');
        self::assertSame([$guestCode], array_column($ofAddress, 'code'), 'any letter case, and only this address');
        self::assertSame([], $codes->ofAddress('guest3@example.test'));
        self::assertSame([], $codes->ofAddress(''));

        self::assertSame(['removed' => 1, 'kept' => 1], $codes->removeUnused(array_column($ofCustomer, 'id')));
        self::assertSame(['removed' => 1, 'kept' => 0], $codes->removeUnused(array_column($ofAddress, 'id')));
        self::assertSame(['removed' => 0, 'kept' => 0], $codes->removeUnused([$template, 99999999]), 'a rule of the merchant is left alone');

        $left = self::issuedIds();
        self::assertContains($usedTied, $left, 'a used code stays with its order');
        self::assertContains($othersCode, $left);
        self::assertNotContains($tied, $left);
        self::assertSame([], $codes->ofAddress('guest1@example.test'));
        self::assertCount(1, $codes->ofAddress('guest2@example.test'));
        self::assertNotFalse(self::db()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_rule` WHERE `id_cart_rule` = ' . $template));
    }

    public function testTheRemainderVoucherOfACodeIsTheShoppersAndIsNeverSelected(): void
    {
        $template = $this->credit('', ['active' => false]);
        $customer = $this->fixture->customer('shopper1@example.test');
        $guest = $this->fixture->customer('guest1@example.test', true);
        $tied = $this->issue($template, time(), $customer);
        $untied = $this->issue($template, time());
        $untiedCode = (new \CartRule($untied))->code;
        $this->queue()->enqueue(WelcomeTrigger::EVENT_TYPE, $this->idShop, ['email' => 'guest1@example.test', 'fields' => ['welcome_coupon_code' => $untiedCode]], 'guest1@example.test', 'en');

        $ofCustomer = $this->spendPartOf($tied, $customer)['remainder'];
        $ofGuest = $this->spendPartOf($untied, $guest)['remainder'];

        $codes = new IssuedCodes();
        foreach ([[$ofCustomer, $tied, $customer], [$ofGuest, $untied, 0]] as [$idRemainder, $idCode, $owner]) {
            $remainder = new \CartRule($idRemainder);
            $code = new \CartRule($idCode);
            self::assertSame([$code->description, $code->code . '-2', $owner], [$remainder->description, $remainder->code, (int) $remainder->id_customer], 'PrestaShop copies the description under a new code');
            self::assertGreaterThan(0, (float) $remainder->reduction_amount);
            self::assertFalse(CodeIssuer::isIssued($remainder));
            self::assertTrue(CodeIssuer::isIssued($code));
            self::assertNull((new TemplateRules())->usable($idRemainder, $this->idShop), 'and it is no template');
        }
        self::assertSame([], array_intersect([$ofCustomer, $ofGuest, $tied, $untied], array_column((new TemplateRules())->listFor([$this->idShop], (int) \Configuration::get('PS_LANG_DEFAULT')), 'id')));
        self::assertSame([$tied, $untied], self::issuedIds());
        self::assertSame([[$tied, true]], array_map(static fn (array $row): array => [$row['id'], $row['used']], $codes->ofCustomer($customer)));
        self::assertSame([[$untied, true]], array_map(static fn (array $row): array => [$row['id'], $row['used']], $codes->ofAddress('guest1@example.test')));
        self::assertSame(['removed' => 0, 'kept' => 2], $codes->removeUnused([$ofCustomer, $ofGuest, $tied, $untied]));

        // Long past its end: the janitor still leaves the shopper's credit alone.
        self::db()->update('cart_rule', ['date_to' => date('Y-m-d H:i:s', time() - 40 * self::DAY)], '`id_cart_rule` IN (' . $ofCustomer . ', ' . $ofGuest . ')');
        self::assertSame(0, $codes->deleteExpiredUnused());
        foreach ([$ofCustomer, $ofGuest] as $idRemainder) {
            self::assertSame(1, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cart_rule` WHERE `id_cart_rule` = ' . $idRemainder . ' AND `reduction_amount` > 0'), 'the remainder keeps its value');
        }
    }

    public function testARuleOfAnEarlierDevelopmentBuildIsNeitherACodeNorATemplate(): void
    {
        $old = $this->template(['description' => 'smaily-connect-issued: template=1 trigger=welcome']);
        $named = $this->template(['description' => 'smaily-connect-issued: code=OTHERCODE2 template=1 trigger=welcome', 'code' => 'MERCHANT22']);

        foreach ([$old, $named] as $id) {
            self::assertFalse(CodeIssuer::isIssued(new \CartRule($id)));
            self::assertNull((new TemplateRules())->usable($id, $this->idShop));
        }
        self::assertSame([], self::issuedIds());
        self::db()->update('cart_rule', ['date_to' => date('Y-m-d H:i:s', time() - 40 * self::DAY)], '`id_cart_rule` IN (' . $old . ', ' . $named . ')');
        self::assertSame(0, (new IssuedCodes())->deleteExpiredUnused(), 'the safe side: what is not surely a code stays');
        self::assertSame(['removed' => 0, 'kept' => 0], (new IssuedCodes())->removeUnused([$old, $named]));
    }

    public function testACodeThatOnlySitsInACartIsNotUsed(): void
    {
        $template = $this->template();
        $customer = $this->fixture->customer('shopper1@example.test');
        $expired = $this->issue($template, time() - 38 * self::DAY);
        $fresh = $this->issue($template, time(), $customer);
        $idCart = $this->fixture->cart($customer, 1);
        self::db()->insert('cart_cart_rule', ['id_cart' => $idCart, 'id_cart_rule' => $expired]);
        self::assertTrue((new \Cart($idCart))->addCartRule($fresh));
        $codes = new IssuedCodes();

        self::assertSame([false], array_column($codes->ofCustomer($customer), 'used'));
        self::assertSame(1, $codes->deleteExpiredUnused(), 'a code 30 days past its end goes, also from a cart that still holds it');
        self::assertSame(['removed' => 1, 'kept' => 0], $codes->removeUnused([$fresh]));

        self::assertSame([], self::issuedIds());
        self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cart_cart_rule` WHERE `id_cart` = ' . $idCart), 'PrestaShop takes the code out of the cart');
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
