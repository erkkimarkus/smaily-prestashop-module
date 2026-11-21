<?php
declare(strict_types=1);

namespace Tests\Integration\Discount;

use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Discount\CodeIssuer;
use PrestaShop\Module\SmailyForPrestaShop\Discount\TemplateRules;

require_once __DIR__ . '/DiscountTestCase.php';

/**
 * Which cart rules can be a template, what a code issued from one holds, and that PrestaShop
 * itself enforces the code at the cart: once, until its last day, for its customer, with the
 * template's restrictions.
 */
final class CodeIssuerTest extends DiscountTestCase
{
    public function testATemplateIsAMerchantsRuleThatHasNotEndedAndIsUsableInTheShop(): void
    {
        $disabled = $this->template();
        $enabled = $this->template(['active' => true, 'code' => 'SHAREDCODE']);
        $ended = $this->template(['date_to' => date('Y-m-d H:i:s', time() - 60)]);
        $otherShop = $this->template(['shop_restriction' => true]);
        self::db()->insert('cart_rule_shop', ['id_cart_rule' => $otherShop, 'id_shop' => $this->idShop + 100]);
        $thisShop = $this->template(['shop_restriction' => true]);
        self::db()->insert('cart_rule_shop', ['id_cart_rule' => $thisShop, 'id_shop' => $this->idShop]);
        $issued = (int) \CartRule::getIdByCode($this->issuer()->issue($disabled, 7, $this->idShop, 0, Trigger::WELCOME)['code']);
        $templates = new TemplateRules();

        $listed = $templates->listFor([$this->idShop], (int) \Configuration::get('PS_LANG_DEFAULT'));

        $ids = array_column($listed, 'id');
        self::assertSame([], array_diff([$disabled, $enabled, $thisShop], $ids), 'enabled and disabled rules alike');
        self::assertSame([], array_intersect([$ended, $otherShop, $issued], $ids));
        self::assertSame('Ten percent ' . \Language::getIsoById((int) \Configuration::get('PS_LANG_DEFAULT')), $listed[array_search($disabled, $ids, true)]['name']);
        self::assertNotContains($thisShop, array_column($templates->listFor([$this->idShop, $this->idShop + 100], 1), 'id'), 'settings for several shops list a rule of all of them');
        self::assertContains($disabled, array_column($templates->listFor([$this->idShop, $this->idShop + 100], 1), 'id'));

        foreach ([$disabled, $enabled, $thisShop] as $id) {
            self::assertNotNull($templates->usable($id, $this->idShop));
        }
        foreach ([$ended, $otherShop, $issued, 99999999] as $id) {
            self::assertNull($templates->usable($id, $this->idShop));
        }
        self::assertNull($this->issuer()->issue($ended, 7, $this->idShop, 0, Trigger::WELCOME));
        self::assertNull($this->issuer()->issue($issued, 7, $this->idShop, 0, Trigger::WELCOME), 'a code is never a template');
    }

    public function testACodeIsACopyOfTheTemplateThatWorksOnceUntilItsLastDay(): void
    {
        $template = $this->template(['minimum_amount' => 5, 'group_restriction' => true, 'product_restriction' => true]);
        self::db()->insert('cart_rule_group', ['id_cart_rule' => $template, 'id_group' => (int) \Configuration::get('PS_CUSTOMER_GROUP')]);
        self::db()->insert('cart_rule_product_rule_group', ['id_cart_rule' => $template, 'quantity' => 1]);
        $group = (int) self::db()->Insert_ID();
        self::db()->insert('cart_rule_product_rule', ['id_product_rule_group' => $group, 'type' => 'products']);
        self::db()->insert('cart_rule_product_rule_value', ['id_product_rule' => (int) self::db()->Insert_ID(), 'id_item' => 1]);
        $customer = $this->fixture->customer('shopper1@example.test');
        $now = strtotime(date('Y-m-d 10:30:00'));

        $code = $this->issuer($now)->issue($template, 3, $this->idShop, $customer, Trigger::FIRST_ORDER);

        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{10}$/', $code['code']);
        self::assertSame(date('Y-m-d', strtotime('+3 days', $now)), $code['expires_at']);
        $rule = new \CartRule((int) \CartRule::getIdByCode($code['code']));
        $source = new \CartRule($template);
        foreach (array_map('intval', \Language::getIDs(false)) as $idLang) {
            self::assertSame('Smaily Connect – Ten percent ' . \Language::getIsoById($idLang), $rule->name[$idLang]);
        }
        self::assertSame('smaily-connect-issued: template=' . $template . ' trigger=first_order', $rule->description);
        self::assertTrue(CodeIssuer::isIssued($rule));
        self::assertFalse(CodeIssuer::isIssued($source));
        self::assertSame(
            [$customer, 1, 1, 0, 1, date('Y-m-d H:i:s', $now), $code['expires_at'] . ' 23:59:59'],
            [(int) $rule->id_customer, (int) $rule->quantity, (int) $rule->quantity_per_user, (int) $rule->highlight, (int) $rule->active, $rule->date_from, $rule->date_to]
        );
        self::assertSame(
            [(float) $source->reduction_percent, (float) $source->minimum_amount, (int) $source->group_restriction, (int) $source->product_restriction, (int) $source->partial_use],
            [(float) $rule->reduction_percent, (float) $rule->minimum_amount, (int) $rule->group_restriction, (int) $rule->product_restriction, (int) $rule->partial_use],
            'the discount and its conditions are the template\'s'
        );
        self::assertSame([(int) \Configuration::get('PS_CUSTOMER_GROUP')], array_map('intval', array_column(
            self::db()->executeS('SELECT `id_group` FROM `' . _DB_PREFIX_ . 'cart_rule_group` WHERE `id_cart_rule` = ' . (int) $rule->id),
            'id_group'
        )), 'the restrictions are copied');
        self::assertSame(1, (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cart_rule_product_rule_group` g'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'cart_rule_product_rule` r ON r.`id_product_rule_group` = g.`id_product_rule_group`'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'cart_rule_product_rule_value` v ON v.`id_product_rule` = r.`id_product_rule`'
            . ' WHERE g.`id_cart_rule` = ' . (int) $rule->id . ' AND v.`id_item` = 1'
        ));
        self::assertSame([0, 100, 5, 1], [(int) $source->active, (int) $source->quantity, (int) $source->quantity_per_user, (int) $source->highlight], 'the template is untouched');
        self::assertSame('The merchant\'s own note', $source->description);
    }

    public function testACodeNeverOutlivesItsTemplate(): void
    {
        $now = strtotime(date('Y-m-d 10:30:00'));
        $midnight = $this->template(['date_to' => date('Y-m-d 00:00:00', strtotime('+2 days', $now))]);
        $afternoon = $this->template(['date_to' => date('Y-m-d 15:00:00', strtotime('+2 days', $now))]);

        $first = $this->issuer($now)->issue($midnight, 7, $this->idShop, 0, Trigger::WELCOME);
        $second = $this->issuer($now)->issue($afternoon, 7, $this->idShop, 0, Trigger::WELCOME);

        self::assertSame(date('Y-m-d', strtotime('+1 day', $now)), $first['expires_at'], 'an end at midnight leaves the day before as the last valid day');
        self::assertSame(date('Y-m-d 00:00:00', strtotime('+2 days', $now)), (new \CartRule((int) \CartRule::getIdByCode($first['code'])))->date_to);
        self::assertSame(date('Y-m-d', strtotime('+2 days', $now)), $second['expires_at']);
        self::assertSame(date('Y-m-d 15:00:00', strtotime('+2 days', $now)), (new \CartRule((int) \CartRule::getIdByCode($second['code'])))->date_to);
    }

    public function testACodeWhoseRowIsStoredButNotFinishedLeavesNoRuleBehind(): void
    {
        $template = $this->template();
        // The id of the next cart rule already holds a name: PrestaShop stores the copy's main row
        // and then fails on its names.
        $probe = $this->rule($this->issuer()->issue($template, 7, $this->idShop, 0, Trigger::WELCOME)['code']);
        $next = (int) $probe->id + 1;
        $probe->delete();
        self::db()->insert('cart_rule_lang', ['id_cart_rule' => $next, 'id_lang' => (int) \Configuration::get('PS_LANG_DEFAULT'), 'name' => 'A leftover name']);

        try {
            $this->issuer()->issue($template, 7, $this->idShop, 0, Trigger::WELCOME);
            self::fail('The issuer reported a code that PrestaShop did not finish.');
        } catch (\Throwable $error) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $error);
        } finally {
            self::db()->delete('cart_rule_lang', '`id_cart_rule` = ' . $next);
        }

        self::assertSame([], self::issuedIds(), 'no half-made rule stays');
        self::assertFalse(self::db()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_rule` WHERE `id_cart_rule` = ' . $next));
        self::assertNotNull($this->issuer()->issue($template, 7, $this->idShop, 0, Trigger::WELCOME), 'the next attempt makes its code');
    }

    public function testPrestaShopTakesTheCodeOnceForItsCustomerUntilItsLastDay(): void
    {
        $template = $this->template();
        $owner = $this->fixture->customer('shopper1@example.test');
        $other = $this->fixture->customer('shopper2@example.test');
        $ownerCart = $this->fixture->cart($owner, 1);
        $otherCart = $this->fixture->cart($other, 1);

        $tied = $this->rule($this->issuer()->issue($template, 7, $this->idShop, $owner, Trigger::WELCOME)['code']);
        $untied = $this->rule($this->issuer()->issue($template, 7, $this->idShop, 0, Trigger::WELCOME)['code']);
        $expired = $this->rule($this->issuer(time() - 9 * 86400)->issue($template, 7, $this->idShop, 0, Trigger::WELCOME)['code']);

        self::assertFalse($this->valid(new \CartRule($template), $ownerCart), 'the disabled template itself is refused');
        self::assertTrue($this->valid($tied, $ownerCart));
        self::assertFalse($this->valid($tied, $otherCart), 'a customer\'s code is refused in another customer\'s cart');
        self::assertTrue($this->valid($untied, $ownerCart));
        self::assertTrue($this->valid($untied, $otherCart), 'an untied code works for whoever enters it');
        self::assertFalse($this->valid($expired, $ownerCart), 'after its last valid day it is refused');

        $total = (new \Cart($otherCart))->getOrderTotal(true, \Cart::BOTH_WITHOUT_SHIPPING);
        (new \Cart($otherCart))->addCartRule((int) $untied->id);
        \CartRule::cleanCache();
        \Cache::clean('*');
        self::assertEqualsWithDelta($total * 0.9, (new \Cart($otherCart))->getOrderTotal(true, \Cart::BOTH_WITHOUT_SHIPPING), 0.02, 'the template\'s discount');
        $this->fixture->order($otherCart);

        $used = new \CartRule((int) $untied->id);
        self::assertSame(0, (int) $used->quantity);
        self::assertFalse($this->valid($used, $ownerCart), 'a second use is refused');
    }

    /**
     * @param int|null $now the issuer's Unix time; the real one when null
     */
    private function issuer(?int $now = null): CodeIssuer
    {
        $clock = $now === null ? null : static fn (): int => $now;

        return new CodeIssuer(new TemplateRules($clock), $clock);
    }

    private function rule(string $code): \CartRule
    {
        return new \CartRule((int) \CartRule::getIdByCode($code));
    }

    /**
     * Whether PrestaShop accepts the rule for the cart, as its cart page asks.
     */
    private function valid(\CartRule $rule, int $idCart): bool
    {
        $context = \Context::getContext();
        $saved = [$context->cart, $context->customer, $context->currency];
        $context->cart = new \Cart($idCart);
        $context->customer = new \Customer((int) $context->cart->id_customer);
        $context->currency = new \Currency((int) $context->cart->id_currency);
        try {
            return (bool) $rule->checkValidity($context, false, false);
        } finally {
            [$context->cart, $context->customer, $context->currency] = $saved;
        }
    }
}
