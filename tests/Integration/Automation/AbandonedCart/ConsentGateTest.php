<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;

require_once __DIR__ . '/AbandonedCartTestCase.php';

/**
 * Acceptance 4 and proof 4: who gets a reminder, by consent mode, for a registered customer, a
 * guest-checkout customer and an address recorded at checkout without a customer, with and without
 * the store's consent, for each answer of Smaily's status read. Every case runs the sweep and the
 * queue flush against the Smaily mock; "include guests" is off throughout, as it does not gate the
 * reminder.
 */
final class ConsentGateTest extends AbandonedCartTestCase
{
    private const SENT = 'sent';

    private const RETRY = 'retry';

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public function cases(): array
    {
        $cases = [];
        $modes = [ContactSyncMode::ALL_CUSTOMERS, ContactSyncMode::SUBSCRIBERS, ContactSyncMode::CHECKOUT_OPTIN];
        $shoppers = ['registered' => ['none', 'earlier', 'ticked'], 'guest' => ['none', 'earlier', 'ticked'], 'captured' => ['none', 'ticked']];
        foreach ($modes as $mode) {
            foreach ($shoppers as $shopper => $consents) {
                foreach ($consents as $consent) {
                    foreach (['subscribed', 'unsubscribed', 'unknown', 'fails'] as $smaily) {
                        $cases[implode(', ', [$mode, $shopper, $consent, $smaily])] = [$mode, $shopper, $consent, $smaily, self::expected($mode, $consent, $smaily)];
                    }
                }
            }
        }

        return $cases;
    }

    /**
     * @dataProvider cases
     */
    public function testTheReminderFollowsTheConsentMode(string $mode, string $shopper, string $consent, string $smaily, string $expected): void
    {
        $this->mode($mode, false);
        if ($smaily === 'fails') {
            $this->mock->failContactReads(true);
        } elseif ($smaily !== 'unknown') {
            $this->mock->setContacts([self::ADDRESS => $smaily === 'subscribed' ? '0' : '1']);
        }
        $idCart = $this->cart($shopper, $consent);

        self::assertSame(1, $this->sweep());
        $this->flush();

        $row = $this->cartRow($idCart);
        $event = $this->events()[0];
        $reads = array_values(array_filter($this->mock->requests(), static fn (array $request): bool => $request['method'] === 'GET'));
        self::assertSame([['/api/contact.php', ['email' => self::ADDRESS], 'api-user']], array_map(
            static fn (array $request): array => [$request['path'], $request['query'], $request['username']],
            $reads
        ), 'one status read through the sending account');
        if ($expected === self::SENT) {
            self::assertCount(1, $this->reminders());
            self::assertSame([CartTracker::MAILED, 'sent'], [$row['status'], $event['status']]);
        } elseif ($expected === self::RETRY) {
            self::assertSame([], $this->reminders());
            self::assertSame([CartTracker::OPEN, 'pending', '1'], [$row['status'], $event['status'], (string) $event['attempts']]);
            self::assertNotNull($event['next_retry_at']);
        } else {
            self::assertSame([], $this->reminders());
            self::assertSame([CartTracker::SKIPPED, $expected], [$row['status'], $row['skip_reason']]);
            self::assertSame(['skipped', $expected], [$event['status'], $event['last_error']]);
        }
    }

    /**
     * Subscribers only: an active newsletter-block row of the address is the store's consent.
     */
    public function testANewsletterBlockRowIsConsentInSubscribersOnly(): void
    {
        if (!self::db()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'emailsubscription\'')) {
            self::markTestSkipped('ps_emailsubscription is not installed.');
        }
        $this->mode(ContactSyncMode::SUBSCRIBERS, false);
        self::db()->insert('emailsubscription', [
            'id_shop' => $this->idShop,
            'id_shop_group' => (int) \Shop::getGroupFromShop($this->idShop),
            'email' => self::ADDRESS,
            'newsletter_date_add' => date('Y-m-d H:i:s'),
            'ip_registration_newsletter' => '127.0.0.1',
            'active' => 1,
            'id_lang' => (int) \Configuration::get('PS_LANG_DEFAULT'),
        ]);
        try {
            $this->fixture->cart($this->fixture->customer(self::ADDRESS, true), 20);
            $this->sweep();
            $this->flush();
        } finally {
            self::db()->delete('emailsubscription', '`email` = \'' . pSQL(self::ADDRESS) . '\'');
        }

        self::assertCount(1, $this->reminders());
    }

    /**
     * A read that failed sends the reminder at a later attempt, once Smaily answers.
     */
    public function testAFailedReadIsRetriedAndThenSent(): void
    {
        $this->mock->failContactReads(true);
        $idCart = $this->cart('registered', 'none');
        $this->sweep();
        $this->flush();
        self::assertSame([], $this->reminders());

        $this->mock->failContactReads(false);
        self::db()->update('smaily_connect_event_queue', ['next_retry_at' => gmdate('Y-m-d H:i:s', time() - 1)], '`event_type` = \'automation.abandoned_cart\'');
        $this->flush();

        self::assertCount(1, $this->reminders());
        self::assertSame(CartTracker::MAILED, $this->cartRow($idCart)['status']);
    }

    private function cart(string $shopper, string $consent): int
    {
        if ($shopper === 'captured') {
            $idCart = $this->fixture->cart(0, 20);
            (new CartTracker())->capture($idCart, $this->idShop, (int) \Configuration::get('PS_LANG_DEFAULT'), self::ADDRESS, $consent === 'ticked');

            return $idCart;
        }
        $guest = $shopper === 'guest';
        if ($consent === 'none') {
            return $this->fixture->cart($this->fixture->customer(self::ADDRESS, $guest), 20);
        }
        // An earlier subscription predates the cart; a tick in this checkout came after the cart.
        $since = $consent === 'earlier' ? date('Y-m-d H:i:s', time() - 86400 * 30) : date('Y-m-d H:i:s', time() - 600);

        return $this->fixture->cart($this->fixture->customer(self::ADDRESS, $guest, true, $since), 20, 2, 0, 30);
    }

    private static function expected(string $mode, string $consent, string $smaily): string
    {
        if ($smaily === 'fails') {
            return self::RETRY;
        }
        if ($smaily === 'unsubscribed') {
            return 'unsubscribed_in_smaily';
        }
        if ($smaily === 'subscribed' || $mode === ContactSyncMode::ALL_CUSTOMERS) {
            return self::SENT;
        }
        $consented = $mode === ContactSyncMode::SUBSCRIBERS ? $consent !== 'none' : $consent === 'ticked';

        return $consented ? self::SENT : 'no_marketing_consent';
    }
}
