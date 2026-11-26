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
        return new SubscriberPayloadBuilder(
            static fn (int $idShop): string => [1 => 'shop1.example.test', 2 => 'shop2.example.test'][$idShop] ?? '',
            // PrestaShop's demo genders: 1 Mr. (male), 2 Mrs. (female), 3 a neutral one.
            static fn (int $idGender): ?string => [1 => '0', 2 => '1', 3 => '2'][$idGender] ?? null,
            // Group 0 is the shop's guest group.
            static fn (int $idGroup, int $idShop): ?string => [0 => 'Guest', 3 => 'Customer', 4 => 'Wholesale'][$idGroup] ?? null
        );
    }

    public function testCarriesGenderCustomerIdAndCustomerGroupWhenSelected(): void
    {
        $customer = self::CUSTOMER + ['id_customer' => 42, 'id_gender' => 2, 'id_default_group' => 4];

        $contact = $this->builder()->build('shopper1@example.test', 1, 'en', ['user_gender', 'customer_id', 'customer_group'], $customer, null);

        self::assertSame([
            'email' => 'shopper1@example.test',
            'store' => 'shop1.example.test',
            'language' => 'en',
            'customer_id' => '42',
            'user_gender' => 'Female',
            'customer_group' => 'Wholesale',
        ], $contact);
    }

    public function testLeavesOutAGenderThatIsNeitherMaleNorFemale(): void
    {
        $male = $this->builder()->build('shopper1@example.test', 1, 'en', ['user_gender'], ['id_gender' => 1], null);
        $neutral = $this->builder()->build('shopper1@example.test', 1, 'en', ['user_gender'], ['id_gender' => 3], null);
        $none = $this->builder()->build('shopper1@example.test', 1, 'en', ['user_gender'], ['id_gender' => 0], null);

        self::assertSame('Male', $male['user_gender']);
        self::assertArrayNotHasKey('user_gender', $neutral);
        self::assertArrayNotHasKey('user_gender', $none);
    }

    /**
     * The Contacts panel and the Overview label every field; Twig refuses a field without a label.
     */
    public function testEveryTemplateThatLabelsTheFieldsLabelsEachOne(): void
    {
        foreach (['panel/contacts.html.twig', 'setup/overview.html.twig'] as $template) {
            $text = (string) file_get_contents(__DIR__ . '/../../../src/views/templates/admin/' . $template);
            preg_match('/\{% set field_labels = \{(.*?)\} %\}/s', $text, $map);
            self::assertNotEmpty($map, $template);
            foreach (SubscriberPayloadBuilder::FIELDS as $field) {
                self::assertMatchesRegularExpression('/\b' . $field . ':/', $map[1], $template . ' labels ' . $field);
            }
        }
    }

    public function testTheDefaultFieldsAreThoseOf2x(): void
    {
        self::assertSame(['first_name', 'last_name', 'birthday', 'website'], SubscriberPayloadBuilder::DEFAULT_FIELDS);
        self::assertSame([], array_diff(SubscriberPayloadBuilder::DEFAULT_FIELDS, SubscriberPayloadBuilder::FIELDS));
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

        $contact = $this->builder()->build(' Shopper1@Example.TEST ', 3, '', array_diff(SubscriberPayloadBuilder::FIELDS, ['subscription_type']), $customer, null);

        self::assertSame(['email' => 'shopper1@example.test'], $contact);
    }

    public function testANewsletterBlockGuestCarriesNoCustomerFieldButTheGuestGroup(): void
    {
        $contact = $this->builder()->build('guest1@example.test', 1, 'en', SubscriberPayloadBuilder::FIELDS, [], null);

        self::assertSame(['email' => 'guest1@example.test', 'store' => 'shop1.example.test', 'language' => 'en', 'customer_group' => 'Guest', 'subscription_type' => 'Subscriber'], $contact);
    }

    /**
     * Magento sends `Subscriber` for every contact, a customer or a newsletter-block subscriber,
     * whatever the consent mode; only when the merchant ticks the field.
     */
    public function testCarriesSubscriptionTypeSubscriberWhenSelected(): void
    {
        $builder = $this->builder();

        self::assertSame('Subscriber', $builder->build('shopper1@example.test', 1, 'en', ['subscription_type'], self::CUSTOMER, null)['subscription_type']);
        self::assertSame('Subscriber', $builder->build('guest1@example.test', 1, 'en', ['subscription_type'], [], true)['subscription_type']);
        self::assertArrayNotHasKey('subscription_type', $builder->build('shopper1@example.test', 1, 'en', SubscriberPayloadBuilder::DEFAULT_FIELDS, self::CUSTOMER, null));
    }

    public function testCarriesTheSubscriptionStatusOnlyWhenOneIsGiven(): void
    {
        $builder = $this->builder();

        self::assertSame(1, $builder->build('shopper1@example.test', 1, 'en', [], [], true)['is_unsubscribed']);
        self::assertSame(0, $builder->build('shopper1@example.test', 1, 'en', [], [], false)['is_unsubscribed']);
        self::assertArrayNotHasKey('is_unsubscribed', $builder->build('shopper1@example.test', 1, 'en', [], [], null));
    }
}
