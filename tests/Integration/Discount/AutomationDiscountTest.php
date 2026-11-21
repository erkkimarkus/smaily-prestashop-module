<?php
declare(strict_types=1);

namespace Tests\Integration\Discount;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Automation\FirstOrder\FirstOrderTrigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Welcome\WelcomeTrigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Log\Resend;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/DiscountTestCase.php';

/**
 * An automation whose "Send a discount code" is on sends each email with its own code, from the
 * queue through both send paths to the Smaily mock: one code per queue row, the same code on a
 * retry and on the Log's "Send again", no code for a skipped row, and an email without a code,
 * with a notice, when the cart rule gives none.
 */
final class AutomationDiscountTest extends DiscountTestCase
{
    public function testEachAutomationsEmailCarriesItsOwnCodeAndLastValidDay(): void
    {
        $template = $this->template();
        $this->discount(Trigger::WELCOME, $template, 7);
        $this->discount(Trigger::FIRST_ORDER, $template, 14);
        $this->discount(Trigger::ABANDONED_CART, $template, 1);
        $customer = $this->fixture->customer('shopper1@example.test');
        $welcome = $this->automationRow(WelcomeTrigger::EVENT_TYPE, 'shopper1@example.test');
        $firstOrder = $this->automationRow(FirstOrderTrigger::EVENT_TYPE, 'shopper2@example.test', ['order_id' => 'ABCDEFGHI']);
        $idCart = $this->fixture->cart($this->fixture->customer('shopper3@example.test', true), 20);
        self::assertSame(1, $this->sweep());

        self::assertSame(3, $this->flush());

        $sent = [];
        foreach ($this->reminders() as $request) {
            $sent[$request['body']['autoresponder']] = $request['body']['addresses'][0];
        }
        $expected = [
            101 => ['welcome', 7, $customer],
            103 => ['first_order', 14, 0],
            self::WORKFLOW => ['abandoned_cart', 1, 0],
        ];
        $codes = [];
        foreach ($expected as $workflow => [$prefix, $days, $idCustomer]) {
            $address = $sent[$workflow];
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{10}$/', $address[$prefix . '_coupon_code']);
            self::assertSame(date('Y-m-d', strtotime('+' . $days . ' days')), $address[$prefix . '_coupon_expires_at']);
            self::assertSame([], array_filter(array_keys($address), static fn (string $name): bool => strpos($name, 'coupon') !== false && strpos($name, $prefix . '_coupon_') !== 0));
            $rule = new \CartRule((int) \CartRule::getIdByCode($address[$prefix . '_coupon_code']));
            self::assertSame($idCustomer, (int) $rule->id_customer, 'tied to the customer account of the address, when it has one');
            self::assertSame('smaily-connect-issued: template=' . $template . ' trigger=' . $prefix, $rule->description);
            $codes[] = $address[$prefix . '_coupon_code'];
        }
        self::assertCount(3, array_unique($codes));
        self::assertCount(3, self::issuedIds());
        self::assertSame('Testfirst', $sent[101]['first_name'], 'the fields the email had before stay');
        self::assertSame('ABCDEFGHI', $sent[103]['order_id']);
        self::assertSame('true', $sent[self::WORKFLOW]['is_abandoned_cart']);
        self::assertSame(CartTracker::MAILED, $this->cartRow($idCart)['status']);
        foreach ([$welcome, $firstOrder] as $id) {
            self::assertSame('sent', self::stored($id)['status']);
        }
        $stored = json_decode((string) self::stored($welcome)['payload'], true);
        self::assertSame($sent[101]['welcome_coupon_code'], $stored['fields']['welcome_coupon_code'], 'the row keeps its code');
    }

    public function testWithTheSwitchOffTheEmailIsExactlyWhatItWas(): void
    {
        $template = $this->template();
        $this->discount(Trigger::WELCOME, $template);
        (new AutomationSettings())->setDiscount(Trigger::WELCOME, false, $template, 7, 0);
        LegacyStore::reloadConfiguration();
        $this->services = $this->services();
        $id = $this->automationRow(WelcomeTrigger::EVENT_TYPE, 'shopper1@example.test');
        $before = self::stored($id)['payload'];

        $this->flush();

        self::assertSame(['email' => 'shopper1@example.test', 'first_name' => 'Testfirst'], $this->posted(0));
        self::assertSame($before, self::stored($id)['payload']);
        self::assertSame([], self::issuedIds());
    }

    public function testAFailedDeliveryIsRetriedWithTheSameCode(): void
    {
        $this->discount(Trigger::WELCOME, $this->template());
        $id = $this->automationRow(WelcomeTrigger::EVENT_TYPE, 'shopper1@example.test');
        $this->mock->failAutomationPosts(true);

        $this->flush();

        $row = self::stored($id);
        self::assertSame([EventQueue::STATUS_PENDING, 1], [$row['status'], (int) $row['attempts']], 'the row waits for its next attempt');
        $code = $this->posted(0)['welcome_coupon_code'];
        self::assertSame($code, json_decode((string) $row['payload'], true)['fields']['welcome_coupon_code']);

        $this->mock->failAutomationPosts(false);
        self::db()->update(EventQueue::TABLE, ['next_retry_at' => null], '`id` = ' . $id, 0, true);
        $this->flush();

        self::assertSame('sent', self::stored($id)['status']);
        self::assertSame([$code, $code], [$this->posted(0)['welcome_coupon_code'], $this->posted(1)['welcome_coupon_code']]);
        self::assertSame($this->posted(0)['welcome_coupon_expires_at'], $this->posted(1)['welcome_coupon_expires_at']);
        self::assertCount(1, self::issuedIds(), 'one rule exists for the event');
    }

    public function testSendAgainFromTheLogSendsTheSameCode(): void
    {
        $this->discount(Trigger::ABANDONED_CART, $this->template());
        $idCart = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 20);
        $this->sweep();
        $this->mock->acceptCredentials('api-user', 'changed-in-smaily');
        // The consent check reads Smaily first and fails with the credentials: nothing is made yet.
        $this->flush();
        self::assertSame([], self::issuedIds(), 'a row that does not reach the post makes no code');
        $this->mock->acceptCredentials('api-user', 'not-a-real-password');
        self::db()->update(EventQueue::TABLE, ['status' => 'pending', 'next_retry_at' => null], '`id` = ' . (int) $this->cartRow($idCart)['event_id'], 0, true);
        $this->mock->failAutomationPosts(true);
        $this->flush();
        $failed = (int) $this->cartRow($idCart)['event_id'];
        self::db()->update(EventQueue::TABLE, ['status' => 'failed', 'attempts' => 5, 'next_retry_at' => null], '`id` = ' . $failed, 0, true);
        $this->mock->failAutomationPosts(false);
        $code = json_decode((string) self::stored($failed)['payload'], true)['fields']['abandoned_cart_coupon_code'];

        $copy = (new Resend($this->queue(), new CartTracker()))->resend(self::stored($failed), 'Testfirst Testlast');
        $this->flush();

        self::assertSame('sent', self::stored($copy)['status']);
        $posts = $this->reminders();
        self::assertSame($code, end($posts)['body']['addresses'][0]['abandoned_cart_coupon_code']);
        self::assertCount(1, self::issuedIds());
    }

    public function testARowThatIsSkippedMakesNoCode(): void
    {
        $this->discount(Trigger::WELCOME, $this->template());
        (new AutomationSettings())->setEnabled(Trigger::WELCOME, false, 0);
        LegacyStore::reloadConfiguration();
        $this->services = $this->services();
        $id = $this->automationRow(WelcomeTrigger::EVENT_TYPE, 'shopper1@example.test');

        $this->flush();

        self::assertSame(['skipped', 'automation_off'], [self::stored($id)['status'], self::stored($id)['last_error']]);
        self::assertSame([], self::issuedIds());
    }

    /**
     * @return array<string, array{string}>
     */
    public function unusableTemplates(): array
    {
        return ['deleted' => ['deleted'], 'past its end date' => ['ended'], 'of another shop' => ['other shop']];
    }

    /**
     * @dataProvider unusableTemplates
     */
    public function testATemplateThatGivesNoCodeSendsTheEmailWithoutOneAndTheRowKeepsTheReason(string $state): void
    {
        $template = $this->template();
        $this->discount(Trigger::WELCOME, $template);
        if ($state === 'deleted') {
            (new \CartRule($template))->delete();
        } elseif ($state === 'ended') {
            self::db()->update('cart_rule', ['date_to' => date('Y-m-d H:i:s', time() - 60)], '`id_cart_rule` = ' . $template);
        } else {
            self::db()->update('cart_rule', ['shop_restriction' => 1], '`id_cart_rule` = ' . $template);
            self::db()->insert('cart_rule_shop', ['id_cart_rule' => $template, 'id_shop' => $this->idShop + 100]);
        }
        $id = $this->automationRow(WelcomeTrigger::EVENT_TYPE, 'shopper1@example.test');

        $this->flush();

        self::assertSame('sent', self::stored($id)['status'], 'the email still goes');
        self::assertSame(['email' => 'shopper1@example.test', 'first_name' => 'Testfirst'], $this->posted(0));
        self::assertSame([], self::issuedIds());
        self::assertSame(['reason' => 'rule_unavailable', 'rule' => $template], json_decode((string) self::stored($id)['payload'], true)['discount_notice']);

        // The template fixed: the next event carries a code again.
        $this->discount(Trigger::WELCOME, $this->template());
        $next = $this->automationRow(WelcomeTrigger::EVENT_TYPE, 'shopper2@example.test');
        $this->flush();
        self::assertArrayHasKey('welcome_coupon_code', $this->posted(1));
        self::assertArrayNotHasKey('discount_notice', json_decode((string) self::stored($next)['payload'], true));
    }

    public function testACodeThatPrestaShopRefusesToStoreFailsTheRowAndNothingIsPosted(): void
    {
        $template = $this->template();
        // A rule that PrestaShop does not validate: its copy cannot be stored.
        self::db()->update('cart_rule', ['reduction_percent' => 250], '`id_cart_rule` = ' . $template);
        $this->discount(Trigger::WELCOME, $template);
        $this->discount(Trigger::ABANDONED_CART, $template);
        $id = $this->automationRow(WelcomeTrigger::EVENT_TYPE, 'shopper1@example.test');
        $other = $this->queue()->enqueue(WelcomeTrigger::EVENT_TYPE, $this->idShop, ['email' => 'shopper2@example.test', 'fields' => ['welcome_coupon_code' => 'ABCDEFGH23']], 'shopper2@example.test', 'en');
        $idCart = $this->fixture->cart($this->fixture->customer('shopper3@example.test'), 20);
        $this->sweep();

        $this->flush();

        $row = self::stored($id);
        self::assertSame([EventQueue::STATUS_PENDING, 1], [$row['status'], (int) $row['attempts']], 'the row is retried');
        self::assertStringContainsString('PrestaShopException', (string) $row['last_error']);
        self::assertSame('sent', self::stored($other)['status'], 'the next row of the batch still goes');
        $cart = self::stored((int) $this->cartRow($idCart)['event_id']);
        self::assertSame([EventQueue::STATUS_PENDING, 1, CartTracker::OPEN], [$cart['status'], (int) $cart['attempts'], $this->cartRow($idCart)['status']]);
        self::assertCount(1, $this->reminders());
        self::assertSame([], self::issuedIds());
    }
}
