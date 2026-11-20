<?php
declare(strict_types=1);

namespace Tests\Unit\Contacts;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;

/**
 * Acceptance 3 of Story 2.2: a contact row carries `store` (the shop's domain, as 2.x sent it),
 * `language`, and only the selected fields, with the field names 2.x sent; an empty value is left
 * out, because Smaily keeps a field that is absent and clears one that is empty.
 */
final class SubscriberPayloadBuilderTest extends TestCase
{
    private const CUSTOMER = [
        'firstname' => 'Testfirst',
        'lastname' => 'Testlast',
        'birthday' => '1990-01-31',
        'website' => 'https://site.example.test',
        'optin' => '1',
    ];

    private function builder(): SubscriberPayloadBuilder
    {
        return new SubscriberPayloadBuilder(static fn (int $idShop): string => [1 => 'shop1.example.test', 2 => 'shop2.example.test'][$idShop] ?? '');
    }

    public function testCarriesTheStoreTheLanguageAndEverySelectedField(): void
    {
        $contact = $this->builder()->build(
            'shopper1@example.test',
            2,
            'et',
            ['first_name', 'last_name', 'birthday', 'website'],
            self::CUSTOMER,
            null
        );

        self::assertSame([
            'email' => 'shopper1@example.test',
            'store' => 'shop2.example.test',
            'language' => 'et',
            'first_name' => 'Testfirst',
            'last_name' => 'Testlast',
            'birthday' => '1990-01-31',
            'website' => 'https://site.example.test',
        ], $contact);
    }

    public function testCarriesOnlyTheSelectedFields(): void
    {
        $contact = $this->builder()->build('shopper1@example.test', 1, 'en', ['last_name', 'store', 'phone'], self::CUSTOMER, null);

        self::assertSame(
            ['email' => 'shopper1@example.test', 'store' => 'shop1.example.test', 'language' => 'en', 'last_name' => 'Testlast'],
            $contact
        );
    }

    public function testLeavesOutAnEmptyValue(): void
    {
        $customer = ['firstname' => '  ', 'lastname' => null, 'birthday' => '0000-00-00', 'website' => ''];

        $contact = $this->builder()->build(' Shopper1@Example.TEST ', 3, '', SubscriberPayloadBuilder::FIELDS, $customer, null);

        self::assertSame(['email' => 'shopper1@example.test'], $contact);
    }

    public function testANewsletterBlockGuestCarriesNoCustomerField(): void
    {
        $contact = $this->builder()->build('guest1@example.test', 1, 'en', SubscriberPayloadBuilder::FIELDS, [], null);

        self::assertSame(['email' => 'guest1@example.test', 'store' => 'shop1.example.test', 'language' => 'en'], $contact);
    }

    public function testCarriesTheSubscriptionStatusOnlyWhenOneIsGiven(): void
    {
        $builder = $this->builder();

        self::assertSame(1, $builder->build('shopper1@example.test', 1, 'en', [], [], true)['is_unsubscribed']);
        self::assertSame(0, $builder->build('shopper1@example.test', 1, 'en', [], [], false)['is_unsubscribed']);
        self::assertArrayNotHasKey('is_unsubscribed', $builder->build('shopper1@example.test', 1, 'en', [], [], null));
    }
}
