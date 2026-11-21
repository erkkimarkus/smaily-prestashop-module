<?php
declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents;
use PrestaShop\Module\SmailyForPrestaShop\Hook\Dispatcher;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Tests\Integration\Fixtures\FailingQueries;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/../MultistoreShop.php';
require_once __DIR__ . '/ContactRows.php';
require_once __DIR__ . '/../Fixtures/FailingQueries.php';

/**
 * The capture hooks in the shop: each store change reaches the event queue as one `contact.sync`
 * row of the record's shop and language, with a consent change only where the stored consent
 * moved. The hooks go through the module's hook methods and Hook\Dispatcher to the runtime's
 * listeners; the rows are written when the request would end (ContactEvents::commit()).
 */
final class ContactCaptureTest extends IntegrationTestCase
{
    use ContactRows;
    use MultistoreShop;

    private const KEYS = [ContactsSettings::ENABLED, ContactsSettings::CONFIRMED];

    private int $shop;

    private int $idLang;

    private string $language;

    protected function setUp(): void
    {
        parent::setUp();
        self::db()->delete(EventQueue::TABLE);
        $this->deleteKeys();
        $this->openMultistore();
        $this->shop = $this->defaultShopId();
        $this->idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $this->language = strtolower((string) \Language::getIsoById($this->idLang));
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        $this->events()->commit();
        self::db()->delete(EventQueue::TABLE);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->events()->commit();
            \Context::getContext()->controller = null;
            $this->removeContactRows();
            self::db()->delete(EventQueue::TABLE);
            $this->deleteKeys();
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    public function testARegistrationWithTheBoxTickedIsAConsentChange(): void
    {
        $customer = $this->customer($this->shop, 'capture1@example.test', true);

        self::module()->hookActionCustomerAccountAdd(['newCustomer' => $customer]);

        self::assertSame(1, $this->events()->commit());
        self::assertSame([[
            'id_shop' => $this->shop,
            'entity_id' => 'capture1@example.test',
            'language' => $this->language,
            'payload' => [
                'email' => 'capture1@example.test',
                'source' => 'customer',
                'subscribed' => true,
                'consent_changed' => true,
                'checkout' => false,
                'guest' => false,
                'id_lang' => $this->idLang,
                'customer' => ['firstname' => 'Testfirst', 'lastname' => 'Testlast', 'birthday' => '0000-00-00', 'website' => ''],
            ],
        ]], $this->rows());
    }

    public function testARegistrationWithoutTheBoxIsNoConsentChange(): void
    {
        self::module()->hookActionCustomerAccountAdd(['newCustomer' => $this->customer($this->shop, 'capture1@example.test', false)]);
        $this->events()->commit();

        self::assertSame([[false, false, false]], $this->flags('subscribed', 'consent_changed', 'checkout'));
    }

    public function testAGuestAtTheOrderStepIsAGuestCheckout(): void
    {
        \Context::getContext()->controller = $this->orderStep();

        self::module()->hookActionCustomerAccountAdd(['newCustomer' => $this->customer($this->shop, 'capture1@example.test', true, ['is_guest' => 1])]);
        $this->events()->commit();

        self::assertSame([[true, true, true, true]], $this->flags('subscribed', 'consent_changed', 'checkout', 'guest'));
    }

    /**
     * Acceptance 2: an account edit fires the customer update hooks and the account-update hook;
     * the request queues one row, and it carries the consent change.
     */
    public function testAnAccountEditThatSwitchesTheConsentOffQueuesOneRowWithTheChange(): void
    {
        $customer = $this->customer($this->shop, 'capture1@example.test', true);
        $customer->newsletter = false;

        self::assertTrue($customer->update());
        self::module()->hookActionCustomerAccountUpdate(['customer' => $customer]);

        self::assertSame(1, $this->events()->commit());
        self::assertSame([[false, true, false]], $this->flags('subscribed', 'consent_changed', 'checkout'));
    }

    public function testASaveThatKeepsTheConsentIsAProfileChange(): void
    {
        $customer = $this->customer($this->shop, 'capture1@example.test', true);
        $customer->firstname = 'Otherfirst';

        self::assertTrue($customer->update());
        $this->events()->commit();

        self::assertSame([[true, false]], $this->flags('subscribed', 'consent_changed'));
        self::assertSame('Otherfirst', $this->rows()[0]['payload']['customer']['firstname']);
    }

    /**
     * The consent stored before a save tells whether the save changed it. A read of it that fails
     * (facts file 6.11) is not "it was off": read so, the save of an unchanged subscriber went out
     * as a new subscription, which subscribes a contact who unsubscribed in Smaily. The save itself
     * goes through, and a save that ends subscribed carries no consent change.
     */
    public function testASaveThatEndsSubscribedWhoseConsentBeforeCouldNotBeReadIsNoConsentChange(): void
    {
        $customer = $this->customer($this->shop, 'capture1@example.test', true);
        $customer->firstname = 'Otherfirst';

        $failed = FailingQueries::during('/SELECT `newsletter` FROM `[^`]+customer`/', static function () use ($customer): void {
            self::assertTrue($customer->update(), 'the shopper\'s save is not broken');
        });
        $this->events()->commit();

        self::assertSame(1, $failed);
        self::assertSame([[true, false]], $this->flags('subscribed', 'consent_changed'));
    }

    /**
     * The other outcome of that failed read (owner, 2026-11-21): a save that ends not subscribed
     * queues the unsubscribe, whether the customer was subscribed before or not, so an unsubscribe
     * made in that save is never lost. The same holds for a save that switches the consent on:
     * with the read failed it queues no subscription.
     *
     * @dataProvider consentBefore
     */
    public function testASaveThatEndsUnsubscribedWhoseConsentBeforeCouldNotBeReadQueuesTheUnsubscribe(bool $before): void
    {
        $customer = $this->customer($this->shop, 'capture1@example.test', $before);
        $customer->newsletter = false;

        $failed = FailingQueries::during('/SELECT `newsletter` FROM `[^`]+customer`/', static function () use ($customer): void {
            self::assertTrue($customer->update(), 'the shopper\'s save is not broken');
        });
        $this->events()->commit();

        self::assertSame(1, $failed);
        self::assertSame([[false, true]], $this->flags('subscribed', 'consent_changed'));
    }

    /**
     * @return array<string, array{bool}>
     */
    public function consentBefore(): array
    {
        return ['subscribed before the save' => [true], 'not subscribed before the save' => [false]];
    }

    public function testASaveThatSwitchesTheConsentOnWhoseConsentBeforeCouldNotBeReadQueuesNoSubscription(): void
    {
        $customer = $this->customer($this->shop, 'capture1@example.test', false);
        $customer->newsletter = true;

        FailingQueries::during('/SELECT `newsletter` FROM `[^`]+customer`/', static function () use ($customer): void {
            self::assertTrue($customer->update());
        });
        $this->events()->commit();

        self::assertSame([[true, false]], $this->flags('subscribed', 'consent_changed'));
    }

    /**
     * A failed read of one save says nothing about the next save of the same customer.
     */
    public function testTheSaveAfterAFailedReadComparesWithTheStoredConsentAgain(): void
    {
        $customer = $this->customer($this->shop, 'capture1@example.test', false);
        $customer->firstname = 'Otherfirst';
        FailingQueries::during('/SELECT `newsletter` FROM `[^`]+customer`/', static function () use ($customer): void {
            $customer->update();
        });
        $this->events()->commit();
        self::db()->delete(EventQueue::TABLE);

        $customer->firstname = 'Thirdfirst';
        self::assertTrue($customer->update());
        $this->events()->commit();

        self::assertSame([[false, false]], $this->flags('subscribed', 'consent_changed'), 'the read answered: the save changed no consent');
    }

    /**
     * The admin grid's newsletter switch saves the customer with the consent switched.
     */
    public function testASaveThatSwitchesTheConsentOnIsAConsentChange(): void
    {
        $customer = $this->customer($this->shop, 'capture1@example.test', false);
        $customer->newsletter = true;

        self::assertTrue($customer->update());
        $this->events()->commit();

        self::assertSame([[true, true]], $this->flags('subscribed', 'consent_changed'));
    }

    /**
     * Acceptance 8: the row is the record's shop's, not the context's.
     */
    public function testACustomerOfAnotherShopQueuesInItsOwnShop(): void
    {
        $other = $this->addShop((int) \Shop::getGroupFromShop($this->shop));
        \Shop::setContext(\Shop::CONTEXT_SHOP, $this->shop);
        $customer = $this->customer($other, 'capture1@example.test', false);
        $customer->newsletter = true;

        self::assertTrue($customer->update());
        $this->events()->commit();

        self::assertSame([$other], array_column($this->rows(), 'id_shop'));
    }

    public function testAShopWhoseContactSyncIsNotActiveQueuesNothing(): void
    {
        $other = $this->addShop((int) \Shop::getGroupFromShop($this->shop));
        \Configuration::updateValue(ContactsSettings::ENABLED, '0', false, (int) \Shop::getGroupFromShop($other), $other);

        self::module()->hookActionCustomerAccountAdd(['newCustomer' => $this->customer($other, 'capture1@example.test', true)]);

        self::assertSame(0, $this->events()->commit());
    }

    public function testAnOrderIsACheckoutInTheOrdersShopAndLanguage(): void
    {
        $other = $this->addShop((int) \Shop::getGroupFromShop($this->shop));
        $customer = $this->customer($this->shop, 'capture1@example.test', true, ['is_guest' => 1]);
        $order = new \Order();
        $order->id_shop = $other;
        $order->id_lang = $this->idLang;

        self::module()->hookActionValidateOrder(['order' => $order, 'customer' => $customer]);
        $this->events()->commit();

        self::assertSame([$other], array_column($this->rows(), 'id_shop'));
        self::assertSame([[true, false, true, true]], $this->flags('subscribed', 'consent_changed', 'checkout', 'guest'));
    }

    public function testANewsletterBlockSubscribeOfAnAddressWithoutAnAccount(): void
    {
        $this->addNewsletterRow($this->shop, 'capture1@example.test', true, $this->idLang);

        self::module()->hookActionNewsletterRegistrationAfter(['email' => 'capture1@example.test', 'action' => '0']);
        $this->events()->commit();

        $rows = $this->rows();
        self::assertSame([$this->shop, $this->language], [$rows[0]['id_shop'], $rows[0]['language']]);
        self::assertSame([
            'email' => 'capture1@example.test',
            'source' => 'newsletter_block',
            'subscribed' => true,
            'consent_changed' => true,
            'id_lang' => $this->idLang,
        ], $rows[0]['payload']);
    }

    /**
     * With the verification email on, the submit stores an inactive row and does not fire the
     * hook; the verification link activates it and fires the hook with `action` as an integer.
     */
    public function testABlockSubscribeWaitingForTheVerificationQueuesNothingUntilTheLink(): void
    {
        $row = $this->addNewsletterRow($this->shop, 'capture1@example.test', false, $this->idLang);
        self::module()->hookActionNewsletterRegistrationAfter(['email' => 'capture1@example.test', 'action' => 0]);
        self::assertSame(0, $this->events()->commit());

        self::db()->update('emailsubscription', ['active' => 1], 'id = ' . $row);
        self::module()->hookActionNewsletterRegistrationAfter(['email' => 'capture1@example.test', 'action' => 0]);

        self::assertSame(1, $this->events()->commit());
    }

    public function testANewsletterBlockSubscribeOfACustomerQueuesTheCustomer(): void
    {
        $this->addCustomer($this->shop, 'capture1@example.test', true, $this->idLang, ['is_guest' => 1]);

        self::module()->hookActionNewsletterRegistrationAfter(['email' => 'capture1@example.test', 'action' => '0']);
        $this->events()->commit();

        self::assertSame([['customer', true, true, true]], $this->flags('source', 'subscribed', 'consent_changed', 'guest'));
    }

    /**
     * Acceptance 6: a guest's unsubscribe deletes the row, and the row it had reaches the queue.
     */
    public function testANewsletterBlockUnsubscribeOfAGuestQueuesTheUnsubscribe(): void
    {
        $row = $this->addNewsletterRow($this->shop, 'capture1@example.test', true, $this->idLang);

        Dispatcher::dispatch('actionNewsletterRegistrationBefore', ['email' => 'capture1@example.test', 'action' => '1']);
        self::db()->delete('emailsubscription', 'id = ' . $row);

        self::assertSame(1, $this->events()->commit());
        self::assertSame([['newsletter_block', false, true]], $this->flags('source', 'subscribed', 'consent_changed'));
    }

    public function testANewsletterBlockUnsubscribeOfACustomerQueuesTheUnsubscribe(): void
    {
        $id = $this->addCustomer($this->shop, 'capture1@example.test', true, $this->idLang);

        Dispatcher::dispatch('actionNewsletterRegistrationBefore', ['email' => 'capture1@example.test', 'action' => '1']);
        self::db()->update('customer', ['newsletter' => 0], 'id_customer = ' . $id);

        self::assertSame(1, $this->events()->commit());
        self::assertSame([['customer', false, true]], $this->flags('source', 'subscribed', 'consent_changed'));
    }

    /**
     * Acceptance 1: a block submit that fails (here: the block found nothing to remove, or another
     * module refused it) queues nothing.
     */
    public function testANewsletterBlockUnsubscribeThatChangesNothingQueuesNothing(): void
    {
        $this->addNewsletterRow($this->shop, 'capture1@example.test', true, $this->idLang);
        $this->addCustomer($this->shop, 'capture2@example.test', false, $this->idLang);

        Dispatcher::dispatch('actionNewsletterRegistrationBefore', ['email' => 'capture1@example.test', 'action' => '1']);
        Dispatcher::dispatch('actionNewsletterRegistrationBefore', ['email' => 'capture2@example.test', 'action' => '1']);
        Dispatcher::dispatch('actionNewsletterRegistrationBefore', ['email' => 'capture3@example.test', 'action' => '1']);

        self::assertSame(0, $this->events()->commit());
    }

    public function testANewsletterBlockSubscribeSubmitIsNotAnUnsubscribe(): void
    {
        $row = $this->addNewsletterRow($this->shop, 'capture1@example.test', true, $this->idLang);

        Dispatcher::dispatch('actionNewsletterRegistrationBefore', ['email' => 'capture1@example.test', 'action' => '0']);
        self::db()->delete('emailsubscription', 'id = ' . $row);

        self::assertSame(0, $this->events()->commit());
    }

    private function events(): ContactEvents
    {
        return Services::instance()->get(ContactEvents::class);
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function customer(int $idShop, string $email, bool $newsletter, array $columns = []): \Customer
    {
        $customer = new \Customer($this->addCustomer($idShop, $email, $newsletter, $this->idLang, $columns + [
            'passwd' => pSQL(password_hash('not-a-real-password', PASSWORD_BCRYPT)),
            'birthday' => '0000-00-00',
            'secure_key' => md5(uniqid('', true)),
        ]));
        self::assertTrue(\Validate::isLoadedObject($customer));

        return $customer;
    }

    private function orderStep(): \FrontController
    {
        $controller = (new \ReflectionClass(\OrderController::class))->newInstanceWithoutConstructor();
        $controller->php_self = 'order';

        return $controller;
    }

    /**
     * @return list<array{id_shop: int, entity_id: ?string, language: ?string, payload: array<string, mixed>}>
     */
    private function rows(): array
    {
        $rows = self::db()->executeS(
            'SELECT `id_shop`, `entity_id`, `language`, `payload` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `event_type` = \'contact.sync\' ORDER BY `id`',
            true,
            false
        ) ?: [];

        return array_map(static fn (array $row): array => [
            'id_shop' => (int) $row['id_shop'],
            'entity_id' => $row['entity_id'],
            'language' => $row['language'],
            'payload' => json_decode((string) $row['payload'], true),
        ], $rows);
    }

    /**
     * @return list<list<mixed>> the named payload fields of each row
     */
    private function flags(string ...$fields): array
    {
        return array_map(static fn (array $row): array => array_map(static fn (string $field) => $row['payload'][$field] ?? null, $fields), $this->rows());
    }

    private function deleteKeys(): void
    {
        foreach (self::KEYS as $key) {
            \Configuration::deleteByName($key);
        }
    }
}
