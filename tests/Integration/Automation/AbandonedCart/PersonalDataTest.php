<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;

require_once __DIR__ . '/AbandonedCartTestCase.php';

/**
 * No log line, cart reason, queue `last_error` or failure names a contact or a product: the outcomes
 * of the reminder carry reason codes only.
 */
final class PersonalDataTest extends AbandonedCartTestCase
{
    public function testNoOutcomeNamesAContactOrAProduct(): void
    {
        $logs = (int) self::db()->getValue('SELECT MAX(`id_log`) FROM `' . _DB_PREFIX_ . 'log`', false);
        $this->mode(ContactSyncMode::SUBSCRIBERS);
        $this->mock->setContacts(['shopper1@example.test' => '0', 'shopper2@example.test' => '1']);
        $carts = [
            $this->fixture->cart($this->fixture->customer('shopper1@example.test'), 20),
            $this->fixture->cart($this->fixture->customer('shopper2@example.test'), 20),
            $this->fixture->cart($this->fixture->customer('shopper3@example.test'), 20),
            $this->fixture->cart($this->fixture->customer('shopper1@example.test', true), 20),
        ];
        $ordered = $this->fixture->customer('shopper4@example.test');
        $carts[] = $this->fixture->cart($ordered, 30);
        $this->sweep();
        $this->fixture->order($this->fixture->cart($ordered, 0));
        $this->flush();

        // A failed read, then a refused request.
        $this->mock->failContactReads(true);
        $carts[] = $this->fixture->cart($this->fixture->customer('shopper5@example.test'), 20);
        $this->sweep();
        $this->flush();
        $this->mock->acceptCredentials('api-user', 'another-fake-password');
        self::db()->update('smaily_connect_event_queue', ['next_retry_at' => gmdate('Y-m-d H:i:s', time() - 1)], '`status` = \'pending\'');
        $this->flush();

        $texts = array_merge(
            array_column($this->events(), 'last_error'),
            array_column(self::db()->executeS('SELECT `skip_reason` FROM `' . _DB_PREFIX_ . 'smaily_connect_cart` WHERE `id_cart` IN (' . implode(', ', $carts) . ')') ?: [], 'skip_reason'),
            array_column(self::db()->executeS('SELECT `message` FROM `' . _DB_PREFIX_ . 'log` WHERE `id_log` > ' . $logs) ?: [], 'message')
        );
        $texts = array_values(array_filter($texts, static fn ($text): bool => $text !== null && $text !== ''));
        self::assertNotEmpty($texts);
        $products = array_column(self::db()->executeS('SELECT DISTINCT pl.`name` FROM `' . _DB_PREFIX_ . 'cart_product` cp JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = cp.`id_product` WHERE cp.`id_cart` IN (' . implode(', ', $carts) . ')') ?: [], 'name');
        self::assertNotEmpty($products);
        foreach ($texts as $text) {
            foreach (array_merge(['shopper', 'example.test', 'Testfirst', 'Testlast', 'not-a-real-password'], $products) as $quoted) {
                self::assertStringNotContainsString($quoted, (string) $text);
            }
        }
        self::assertContains('ordered', $texts);
        self::assertContains('no_marketing_consent', $texts);
        self::assertContains('unsubscribed_in_smaily', $texts);
    }
}
