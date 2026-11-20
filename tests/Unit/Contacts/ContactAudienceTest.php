<?php
declare(strict_types=1);

namespace Tests\Unit\Contacts;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactAudience;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;

/**
 * One case per cell of the table in ARCHITECTURE 6.2 — what each consent mode syncs. The columns are
 * the modes; each test is a row of the table.
 */
final class ContactAudienceTest extends TestCase
{
    private const SUBSCRIBERS = ContactSyncMode::SUBSCRIBERS;

    private const ALL = ContactSyncMode::ALL_CUSTOMERS;

    private const CHECKOUT = ContactSyncMode::CHECKOUT_OPTIN;

    /**
     * Row "Live sync": a customer or guest-checkout customer, after a store event.
     *
     * @return array<string, array{string, bool, bool, bool, bool}>
     */
    public function liveSync(): array
    {
        // mode, subscribed now, consent changed by the event, at checkout, synced
        return [
            'subscribers: opt-in' => [self::SUBSCRIBERS, true, true, false, true],
            'subscribers: opt-in at checkout' => [self::SUBSCRIBERS, true, true, true, true],
            'subscribers: opt-out' => [self::SUBSCRIBERS, false, true, false, true],
            'subscribers: profile change of a subscriber' => [self::SUBSCRIBERS, true, false, false, true],
            'subscribers: profile change without consent' => [self::SUBSCRIBERS, false, false, false, false],
            'subscribers: order without consent' => [self::SUBSCRIBERS, false, false, true, false],
            'all customers: register without consent' => [self::ALL, false, false, false, true],
            'all customers: profile change' => [self::ALL, true, false, false, true],
            'all customers: opt-out' => [self::ALL, false, true, false, true],
            'all customers: order' => [self::ALL, false, false, true, true],
            'checkout opt-in: checkout with the box ticked' => [self::CHECKOUT, true, true, true, true],
            'checkout opt-in: checkout without the box' => [self::CHECKOUT, false, false, true, false],
            'checkout opt-in: opt-in outside the checkout' => [self::CHECKOUT, true, true, false, false],
            'checkout opt-in: opt-out' => [self::CHECKOUT, false, true, false, false],
            'checkout opt-in: profile change of a subscriber' => [self::CHECKOUT, true, false, false, false],
        ];
    }

    /**
     * @dataProvider liveSync
     */
    public function testLiveSyncOfACustomer(string $mode, bool $subscribed, bool $changed, bool $checkout, bool $synced): void
    {
        foreach ([true, false] as $guests) {
            $audience = new ContactAudience(new ContactSyncMode($mode, $guests));

            self::assertSame($synced, $audience->syncs(ContactAudience::SOURCE_CUSTOMER, $subscribed, $changed, $checkout));
        }
    }

    /**
     * Row "Newsletter-block guests": a newsletter-block subscriber without an account.
     *
     * @return array<string, array{string, bool, bool, bool, bool}>
     */
    public function newsletterGuests(): array
    {
        // mode, include guests, subscribed now, consent changed, synced
        return [
            'subscribers, guests on: subscribe' => [self::SUBSCRIBERS, true, true, true, true],
            'subscribers, guests on: unsubscribe' => [self::SUBSCRIBERS, true, false, true, true],
            'subscribers, guests off: subscribe' => [self::SUBSCRIBERS, false, true, true, false],
            'all customers, guests on: subscribe' => [self::ALL, true, true, true, true],
            'all customers, guests on: unsubscribe' => [self::ALL, true, false, true, false],
            'all customers, guests off: subscribe' => [self::ALL, false, true, true, false],
            'checkout opt-in, guests on: subscribe' => [self::CHECKOUT, true, true, true, false],
            'checkout opt-in, guests off: subscribe' => [self::CHECKOUT, false, true, true, false],
        ];
    }

    /**
     * @dataProvider newsletterGuests
     */
    public function testLiveSyncOfANewsletterBlockGuest(string $mode, bool $guests, bool $subscribed, bool $changed, bool $synced): void
    {
        $audience = new ContactAudience(new ContactSyncMode($mode, $guests));

        self::assertSame($synced, $audience->syncs(ContactAudience::SOURCE_NEWSLETTER_BLOCK, $subscribed, $changed, false));
    }

    /**
     * Row "First import and daily refresh".
     *
     * @return array<string, array{string, bool, bool, bool, bool}>
     */
    public function imports(): array
    {
        // mode, include guests, imports a subscribed customer, imports a customer without consent, imports newsletter-block guests
        return [
            'subscribers, guests on' => [self::SUBSCRIBERS, true, true, false, true],
            'subscribers, guests off' => [self::SUBSCRIBERS, false, true, false, false],
            'all customers, guests on' => [self::ALL, true, true, true, true],
            'all customers, guests off' => [self::ALL, false, true, true, false],
            'checkout opt-in, guests on' => [self::CHECKOUT, true, false, false, false],
        ];
    }

    /**
     * @dataProvider imports
     */
    public function testFirstImportAndDailyRefresh(string $mode, bool $guests, bool $subscribed, bool $notSubscribed, bool $newsletterGuests): void
    {
        $audience = new ContactAudience(new ContactSyncMode($mode, $guests));

        self::assertSame($subscribed, $audience->importsCustomer(true));
        self::assertSame($notSubscribed, $audience->importsCustomer(false));
        self::assertSame($newsletterGuests, $audience->importsNewsletterGuests());
    }

    /**
     * A guest-checkout customer (`is_guest = 1`) beside a registered one (owner, 2026-11-20): in all
     * customers a guest goes only when "include guests" is on, whether or not the newsletter box was
     * ticked, as WooCommerce's legitimate-interest preset decides; in subscribers only a guest who
     * ticked the box goes only when "include guests" is on, as WooCommerce's consent preset decides;
     * in checkout opt-in only a guest is as a registered customer. The live event is a checkout that
     * ticked the box or not.
     *
     * @return array<string, array{string, bool, bool, bool, bool, bool}>
     */
    public function guestCheckoutCustomers(): array
    {
        // mode, include guests, guest checkout, box ticked, synced live, imported
        return [
            'all customers, guests on: registered' => [self::ALL, true, false, false, true, true],
            'all customers, guests off: registered' => [self::ALL, false, false, false, true, true],
            'all customers, guests on: guest' => [self::ALL, true, true, false, true, true],
            'all customers, guests on: guest who ticked the box' => [self::ALL, true, true, true, true, true],
            'all customers, guests off: guest' => [self::ALL, false, true, false, false, false],
            'all customers, guests off: guest who ticked the box' => [self::ALL, false, true, true, false, false],
            'subscribers, guests on: registered who ticked the box' => [self::SUBSCRIBERS, true, false, true, true, true],
            'subscribers, guests off: registered who ticked the box' => [self::SUBSCRIBERS, false, false, true, true, true],
            'subscribers, guests on: guest who ticked the box' => [self::SUBSCRIBERS, true, true, true, true, true],
            'subscribers, guests off: guest who ticked the box' => [self::SUBSCRIBERS, false, true, true, false, false],
            'subscribers, guests off: guest' => [self::SUBSCRIBERS, false, true, false, false, false],
            'checkout opt-in, guests off: guest who ticked the box' => [self::CHECKOUT, false, true, true, true, false],
        ];
    }

    /**
     * @dataProvider guestCheckoutCustomers
     */
    public function testAGuestCheckoutCustomer(string $mode, bool $guests, bool $guest, bool $ticked, bool $synced, bool $imported): void
    {
        $audience = new ContactAudience(new ContactSyncMode($mode, $guests));

        self::assertSame($synced, $audience->syncs(ContactAudience::SOURCE_CUSTOMER, $ticked, $ticked, true, $guest));
        self::assertSame($imported, $audience->importsCustomer($ticked, $guest));
    }

    /**
     * Row "Reconcile from Smaily": both directions in subscribers mode, none elsewhere.
     *
     * @return array<string, array{string, bool}>
     */
    public function reconciles(): array
    {
        return [
            'subscribers' => [self::SUBSCRIBERS, true],
            'all customers' => [self::ALL, false],
            'checkout opt-in' => [self::CHECKOUT, false],
        ];
    }

    /**
     * @dataProvider reconciles
     */
    public function testReconcileFromSmaily(string $mode, bool $reconciles): void
    {
        self::assertSame($reconciles, (new ContactSyncMode($mode, true))->reconciles());
    }

    /**
     * Row "is_unsubscribed on upsert" (acceptance 2: only on a consent change): true is
     * `is_unsubscribed = 1`, false is `0`, null leaves the field out.
     *
     * @return array<string, array{string, bool, bool, bool|null}>
     */
    public function statuses(): array
    {
        // mode, subscribed now, consent changed, is_unsubscribed
        return [
            'subscribers: opt-in' => [self::SUBSCRIBERS, true, true, false],
            'subscribers: opt-out' => [self::SUBSCRIBERS, false, true, true],
            'subscribers: no change' => [self::SUBSCRIBERS, true, false, null],
            'all customers: opt-in' => [self::ALL, true, true, null],
            'all customers: opt-out' => [self::ALL, false, true, null],
            'all customers: no change' => [self::ALL, false, false, null],
            'checkout opt-in: the tick' => [self::CHECKOUT, true, true, false],
            'checkout opt-in: no change' => [self::CHECKOUT, true, false, null],
        ];
    }

    /**
     * @dataProvider statuses
     */
    public function testSubscriptionStatusOnUpsert(string $mode, bool $subscribed, bool $changed, ?bool $isUnsubscribed): void
    {
        $audience = new ContactAudience(new ContactSyncMode($mode, true));

        self::assertSame($isUnsubscribed, $audience->unsubscribedStatus($subscribed, $changed));
    }

    /**
     * Row "Abandoned-cart reminder goes out when".
     *
     * @return array<string, array{string, bool, bool, bool, bool, string, bool}>
     */
    public function reminders(): array
    {
        $subscribed = ContactAudience::SMAILY_SUBSCRIBED;
        $unsubscribed = ContactAudience::SMAILY_UNSUBSCRIBED;
        $unknown = ContactAudience::SMAILY_UNKNOWN;

        // mode, include guests, newsletter = 1, active newsletter-block row, box ticked, Smaily status, reminded
        return [
            'subscribers: newsletter = 1' => [self::SUBSCRIBERS, false, true, false, false, $unknown, true],
            'subscribers: block row, guests on' => [self::SUBSCRIBERS, true, false, true, false, $unknown, true],
            'subscribers: block row, guests off' => [self::SUBSCRIBERS, false, false, true, false, $unknown, true],
            'subscribers: box ticked' => [self::SUBSCRIBERS, false, false, false, true, $unknown, true],
            'subscribers: subscribed in Smaily' => [self::SUBSCRIBERS, false, false, false, false, $subscribed, true],
            'subscribers: no consent' => [self::SUBSCRIBERS, true, false, false, false, $unknown, false],
            'subscribers: consent, unsubscribed in Smaily' => [self::SUBSCRIBERS, true, true, true, true, $unsubscribed, false],
            'all customers: unknown to Smaily' => [self::ALL, false, false, false, false, $unknown, true],
            'all customers: unsubscribed in Smaily' => [self::ALL, false, true, false, false, $unsubscribed, false],
            'checkout opt-in: box ticked' => [self::CHECKOUT, false, false, false, true, $unknown, true],
            'checkout opt-in: subscribed in Smaily' => [self::CHECKOUT, false, false, false, false, $subscribed, true],
            'checkout opt-in: newsletter = 1 only' => [self::CHECKOUT, true, true, true, false, $unknown, false],
            'checkout opt-in: box ticked, unsubscribed in Smaily' => [self::CHECKOUT, false, false, false, true, $unsubscribed, false],
            'checkout opt-in: subscribed in Smaily, guests off' => [self::CHECKOUT, false, false, false, false, $subscribed, true],
            'all customers: guests off' => [self::ALL, false, false, false, false, $unknown, true],
            'all customers: subscribed in Smaily' => [self::ALL, false, false, false, false, $subscribed, true],
        ];
    }

    /**
     * @dataProvider reminders
     */
    public function testAbandonedCartReminder(
        string $mode,
        bool $guests,
        bool $newsletter,
        bool $blockRow,
        bool $boxTicked,
        string $smailyStatus,
        bool $reminded
    ): void {
        $audience = new ContactAudience(new ContactSyncMode($mode, $guests));

        self::assertSame($reminded, $audience->remindsCart($newsletter, $blockRow, $boxTicked, $smailyStatus));
    }
}
