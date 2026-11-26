<?php
declare(strict_types=1);

namespace Tests\Unit\Queue\Handler;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use Tests\Unit\Contacts\FakeContactsSettings;
use Tests\Unit\Scope\FakeStoreLayout;

require_once __DIR__ . '/../../Scope/FakeStoreLayout.php';
require_once __DIR__ . '/../../Contacts/FakeContactsSettings.php';

/**
 * The handler of `contact.sync` rows: one Smaily request per account for the rows of a flush batch,
 * one outcome per row, a group refused as invalid data sent again one contact at a time (acceptance
 * 4), and the rows that cannot go closed as skipped with a reason code.
 *
 * Separate stores: shop 1 and shop 2 each have their own account, shop 3 has none, the password of
 * shop 4's account does not decrypt.
 */
final class ContactSyncHandlerTest extends TestCase
{
    private const LANGUAGES = [1 => 'en', 2 => 'et'];

    private const SETTINGS = [
        ContactsSettings::ENABLED => '1',
        ContactsSettings::CONFIRMED => '1',
        ContactsSettings::MODE => ContactSyncMode::SUBSCRIBERS,
        ContactsSettings::GUESTS => '1',
        ContactsSettings::FIELDS => 'store,first_name,last_name',
    ];

    /** @var list<array{host: string, auth: array{string, string}, contacts: list<array<string, mixed>>}> */
    private array $requests = [];

    /** @var callable(list<array<string, mixed>>): Response */
    private $answer;

    /** @var list<int> the ids that the newer-change check answers */
    private array $overtaken = [];

    /** @var list<list<int>> the row ids of each question to the newer-change check */
    private array $askedForOvertaken = [];

    protected function setUp(): void
    {
        $this->answer = static fn (array $contacts): Response => new Response(200, '{"code": 101, "message": "OK"}');
    }

    public function testSendsTheRowsOfEachAccountInOneRequest(): void
    {
        $outcomes = $this->handler()->handle([
            $this->row(1, 1, $this->payload('shopper1@example.test')),
            $this->row(2, 2, $this->payload('shopper2@example.test', ['id_lang' => 2])),
            $this->row(3, 1, $this->payload('shopper3@example.test')),
        ]);

        self::assertSame([1 => true, 3 => true, 2 => true], $outcomes);
        self::assertSame(['shopone', 'shoptwo'], array_column($this->requests, 'host'));
        self::assertSame(['api-user', 'not-a-real-password'], $this->requests[0]['auth']);
        self::assertSame([
            ['email' => 'shopper1@example.test', 'store' => 'shop1.example.test', 'language' => 'en', 'first_name' => 'Testfirst', 'last_name' => 'Testlast'],
            ['email' => 'shopper3@example.test', 'store' => 'shop1.example.test', 'language' => 'en', 'first_name' => 'Testfirst', 'last_name' => 'Testlast'],
        ], $this->requests[0]['contacts']);
        self::assertSame(
            [['email' => 'shopper2@example.test', 'store' => 'shop2.example.test', 'language' => 'et', 'first_name' => 'Testfirst', 'last_name' => 'Testlast']],
            $this->requests[1]['contacts']
        );
    }

    /**
     * Acceptance 4 (Magento PRO-3753): Smaily answers one code for a whole request.
     */
    public function testAGroupThatSmailyRefusesAsInvalidDataGoesAgainOneContactAtATime(): void
    {
        $this->answer = static fn (array $contacts): Response => in_array('not-an-address', array_column($contacts, 'email'), true)
            ? new Response(200, '{"code": 203, "message": "Invalid data"}')
            : new Response(200, '{"code": 101, "message": "OK"}');

        $outcomes = $this->handler()->handle([
            $this->row(1, 1, $this->payload('shopper1@example.test')),
            $this->row(2, 1, $this->payload('not-an-address')),
            $this->row(3, 1, $this->payload('shopper3@example.test')),
        ]);

        self::assertSame([3, 1, 1, 1], array_map('count', array_column($this->requests, 'contacts')));
        self::assertTrue($outcomes[1]);
        self::assertTrue($outcomes[3]);
        self::assertInstanceOf(Failure::class, $outcomes[2]);
        self::assertTrue($outcomes[2]->isPermanent());
        self::assertSame('permanent_envelope_203: Smaily API returned code 203', $outcomes[2]->reason());
    }

    public function testAContactThatSmailyRefusesAloneIsNotSentAgain(): void
    {
        $this->answer = static fn (array $contacts): Response => new Response(200, '{"code": 203, "message": "Invalid data"}');

        $outcomes = $this->handler()->handle([$this->row(1, 1, $this->payload('not-an-address'))]);

        self::assertCount(1, $this->requests);
        self::assertTrue($outcomes[1]->isPermanent());
    }

    public function testAnyOtherFailureIsTheWholeGroups(): void
    {
        $this->answer = static fn (array $contacts): Response => new Response(503, 'Unavailable');

        $outcomes = $this->handler()->handle([
            $this->row(1, 1, $this->payload('shopper1@example.test')),
            $this->row(2, 1, $this->payload('shopper2@example.test')),
        ]);

        self::assertCount(1, $this->requests);
        foreach ([1, 2] as $id) {
            self::assertFalse($outcomes[$id]->isPermanent());
            self::assertSame('Smaily API request failed with HTTP 503', $outcomes[$id]->reason());
        }

        $this->answer = static fn (array $contacts): Response => new Response(401, '');

        $outcome = $this->handler()->handle([$this->row(1, 1, $this->payload('shopper1@example.test'))])[1];

        self::assertTrue($outcome->isPermanent());
        self::assertSame('permanent_http_401: Smaily API credentials were rejected', $outcome->reason());
    }

    /**
     * A row goes with the consent it stores, whatever attempt it is and whatever other row of its
     * contact the batch holds.
     */
    public function testSendsEachRowWithTheConsentItStores(): void
    {
        $outcomes = $this->handler()->handle([
            $this->row(1, 1, $this->payload('shopper1@example.test', ['consent_changed' => true])),
            ['attempts' => '3'] + $this->row(2, 1, $this->payload('shopper2@example.test', ['consent_changed' => true])),
            $this->row(3, 1, $this->payload('shopper2@example.test', ['subscribed' => false, 'consent_changed' => true])),
        ]);

        self::assertSame([1 => true, 2 => true, 3 => true], $outcomes);
        self::assertCount(1, $this->requests);
        self::assertSame(
            [['shopper1@example.test', 0], ['shopper2@example.test', 0], ['shopper2@example.test', 1]],
            array_map(static fn (array $contact): array => [$contact['email'], $contact['is_unsubscribed']], $this->requests[0]['contacts'])
        );
    }

    /**
     * A row that the newer-change check names (Contacts\NewerChange) is closed without a request,
     * whatever attempt it is; the rows around it go as before, and the check is asked once for the
     * batch (owner, 2026-11-26).
     */
    public function testSkipsARowThatANewerDeliveredChangeOvertook(): void
    {
        $this->overtaken = [2];

        $outcomes = $this->handler()->handle([
            $this->row(1, 1, $this->payload('shopper1@example.test', ['consent_changed' => true])),
            ['attempts' => '3'] + $this->row(2, 1, $this->payload('shopper2@example.test', ['consent_changed' => true])),
            $this->row(3, 1, $this->payload('shopper3@example.test', ['subscribed' => false, 'consent_changed' => true])),
        ]);

        self::assertSame([[1, 2, 3]], $this->askedForOvertaken);
        self::assertInstanceOf(Skipped::class, $outcomes[2]);
        self::assertSame(ContactSyncHandler::SKIPPED_NEWER_CHANGE, $outcomes[2]->reason());
        self::assertSame([1 => true, 3 => true], [1 => $outcomes[1], 3 => $outcomes[3]]);
        self::assertCount(1, $this->requests);
        self::assertSame(
            [['shopper1@example.test', 0], ['shopper3@example.test', 1]],
            array_map(static fn (array $contact): array => [$contact['email'], $contact['is_unsubscribed']], $this->requests[0]['contacts']),
            'nothing of the overtaken row reaches Smaily'
        );
    }

    public function testAnOvertakenRowOfAShopWhoseContactSyncIsOffKeepsThatReason(): void
    {
        $this->overtaken = [1];

        $outcomes = $this->handler([1 => [ContactsSettings::ENABLED => '0']])->handle([
            $this->row(1, 1, $this->payload('shopper1@example.test', ['consent_changed' => true])),
        ]);

        self::assertSame(ContactSyncHandler::SKIPPED_SYNC_OFF, $outcomes[1]->reason());
        self::assertSame([], $this->requests);
    }

    public function testSkipsTheRowsOfAShopWhoseContactSyncIsOff(): void
    {
        $handler = $this->handler([
            2 => [ContactsSettings::ENABLED => '0'],
            1 => [ContactsSettings::CONFIRMED => '0'],
        ]);

        $outcomes = $handler->handle([
            $this->row(1, 1, $this->payload('shopper1@example.test')),
            $this->row(2, 2, $this->payload('shopper2@example.test')),
        ]);

        self::assertEquals([1 => new Skipped('contact_sync_off'), 2 => new Skipped('contact_sync_off')], $outcomes);
        self::assertSame([], $this->requests);
    }

    public function testSkipsAContactOutsideTheAudienceOfTheShopsMode(): void
    {
        $outcomes = $this->handler([2 => [ContactsSettings::MODE => ContactSyncMode::CHECKOUT_OPTIN]])->handle([
            $this->row(1, 1, $this->payload('shopper1@example.test', ['subscribed' => false])),
            $this->row(2, 2, $this->payload('shopper2@example.test', ['consent_changed' => true])),
        ]);

        self::assertEquals([1 => new Skipped('not_in_audience'), 2 => new Skipped('not_in_audience')], $outcomes);
        self::assertSame([], $this->requests);
    }

    public function testInAllCustomersSkipsAGuestCheckoutCustomerUnlessGuestsAreIncluded(): void
    {
        $outcomes = $this->handler([
            1 => [ContactsSettings::MODE => ContactSyncMode::ALL_CUSTOMERS, ContactsSettings::GUESTS => '0'],
            2 => [ContactsSettings::MODE => ContactSyncMode::ALL_CUSTOMERS, ContactsSettings::GUESTS => '1'],
        ])->handle([
            $this->row(1, 1, $this->payload('shopper1@example.test', ['guest' => true])),
            $this->row(2, 1, $this->payload('shopper2@example.test')),
            $this->row(3, 2, $this->payload('shopper3@example.test', ['guest' => true])),
        ]);

        self::assertEquals([1 => new Skipped('not_in_audience'), 2 => true, 3 => true], $outcomes);
    }

    public function testSkipsARowWhoseShopHasNoAccountOrNoCredentials(): void
    {
        $outcomes = $this->handler()->handle([
            $this->row(1, 3, $this->payload('shopper1@example.test')),
            $this->row(2, 4, $this->payload('shopper2@example.test')),
        ]);

        self::assertEquals([1 => new Skipped('no_account'), 2 => new Skipped('no_credentials')], $outcomes);
        self::assertSame([], $this->requests, 'a shop of separate stores never uses another shop\'s account');
    }

    /**
     * @return array<string, array{string}>
     */
    public function malformedPayloads(): array
    {
        return [
            'not JSON' => ['{"email": '],
            'no address' => ['{"source": "customer", "subscribed": true, "consent_changed": false}'],
            'an unknown source' => ['{"email": "shopper1@example.test", "source": "order", "subscribed": true, "consent_changed": false}'],
            'no consent' => ['{"email": "shopper1@example.test", "source": "customer", "consent_changed": false}'],
            'consent as a string' => ['{"email": "shopper1@example.test", "source": "customer", "subscribed": "1", "consent_changed": false}'],
            'customer as a string' => ['{"email": "shopper1@example.test", "source": "customer", "subscribed": true, "consent_changed": false, "customer": "x"}'],
            'guest as a string' => ['{"email": "shopper1@example.test", "source": "customer", "subscribed": true, "consent_changed": false, "guest": "1"}'],
        ];
    }

    /**
     * @dataProvider malformedPayloads
     */
    public function testFailsAMalformedPayloadForGood(string $payload): void
    {
        $row = $this->row(1, 1, []);
        $row['payload'] = $payload;

        $outcome = $this->handler()->handle([$row])[1];

        self::assertInstanceOf(Failure::class, $outcome);
        self::assertTrue($outcome->isPermanent());
        self::assertSame('malformed_payload', $outcome->reason());
        self::assertSame([], $this->requests);
    }

    /**
     * Acceptance 2: `is_unsubscribed` only where the store saw the consent change; in all customers
     * only an unsubscribe carries it (owner, 2026-11-26).
     */
    public function testSendsASubscriptionStatusOnlyOnAConsentChange(): void
    {
        $this->handler([2 => [ContactsSettings::MODE => ContactSyncMode::ALL_CUSTOMERS]])->handle([
            $this->row(1, 1, $this->payload('optin@example.test', ['consent_changed' => true])),
            $this->row(2, 1, $this->payload('optout@example.test', ['subscribed' => false, 'consent_changed' => true])),
            $this->row(3, 1, $this->payload('profile@example.test')),
            $this->row(4, 2, $this->payload('allcustomers@example.test', ['subscribed' => false, 'consent_changed' => true])),
            $this->row(5, 2, $this->payload('allcustomers-optin@example.test', ['consent_changed' => true])),
        ]);

        $statuses = [];
        foreach ($this->requests as $request) {
            foreach ($request['contacts'] as $contact) {
                $statuses[$contact['email']] = $contact['is_unsubscribed'] ?? null;
            }
        }
        self::assertSame(
            ['optin@example.test' => 0, 'optout@example.test' => 1, 'profile@example.test' => null, 'allcustomers@example.test' => 1, 'allcustomers-optin@example.test' => null],
            $statuses
        );
    }

    public function testSendsANewsletterBlockGuestWithoutCustomerFields(): void
    {
        $outcomes = $this->handler()->handle([
            $this->row(1, 1, ['email' => 'guest1@example.test', 'source' => 'newsletter_block', 'subscribed' => true, 'consent_changed' => true, 'id_lang' => 1]),
        ]);

        self::assertSame([1 => true], $outcomes);
        self::assertSame(
            [['email' => 'guest1@example.test', 'is_unsubscribed' => 0, 'store' => 'shop1.example.test', 'language' => 'en']],
            $this->requests[0]['contacts']
        );
    }

    /**
     * Acceptance 6: no outcome the Log shows names the contact or quotes the request.
     */
    public function testNoReasonNamesTheContactOrQuotesTheRequest(): void
    {
        $reasons = [];
        $rows = [
            $this->row(1, 1, $this->payload('shopper1@example.test')),
            $this->row(2, 1, $this->payload('not-an-address')),
            $this->row(3, 3, $this->payload('shopper1@example.test')),
            $this->row(4, 4, $this->payload('shopper1@example.test')),
            $this->row(5, 1, $this->payload('shopper1@example.test', ['subscribed' => false])),
            ['payload' => '{"email": "shopper1@example.test", "first": "Testfirst"'] + $this->row(6, 1, []),
        ];
        $answers = [
            static fn (array $contacts): Response => new Response(200, '{"code": 203, "message": "Invalid data: shopper1@example.test Testfirst"}'),
            static fn (array $contacts): Response => new Response(400, '{"message": "Testfirst Testlast shopper1@example.test"}'),
            static fn (array $contacts): Response => new Response(503, 'Testfirst Testlast shopper1@example.test'),
            static function (array $contacts): Response {
                throw NetworkException::of(new \RuntimeException('Could not resolve https://shopone.sendsmaily.net/api/contact.php?email=shopper1%40example.test'));
            },
        ];
        foreach ($answers as $answer) {
            $this->answer = $answer;
            foreach ($this->handler()->handle($rows) as $outcome) {
                if ($outcome instanceof Failure || $outcome instanceof Skipped) {
                    $reasons[] = $outcome->reason();
                }
            }
        }

        self::assertGreaterThan(20, count($reasons));
        foreach ($reasons as $reason) {
            foreach (['shopper1', 'example.test', 'Testfirst', 'Testlast', '{'] as $quoted) {
                self::assertStringNotContainsString($quoted, $reason);
            }
        }
    }

    public function testEachSentRowTellsItsOwnContactAndTheAnswer(): void
    {
        $handler = $this->handler();
        $handler->handle([
            $this->row(1, 1, $this->payload('shopper1@example.test')),
            $this->row(2, 1, $this->payload('shopper2@example.test')),
            $this->row(3, 3, $this->payload('shopper3@example.test')),
        ]);

        self::assertSame([
            1 => [
                'request' => [['email' => 'shopper1@example.test', 'store' => 'shop1.example.test', 'language' => 'en', 'first_name' => 'Testfirst', 'last_name' => 'Testlast']],
                'response' => ['http_status' => 200, 'body' => ['code' => 101]],
            ],
            2 => [
                'request' => [['email' => 'shopper2@example.test', 'store' => 'shop1.example.test', 'language' => 'en', 'first_name' => 'Testfirst', 'last_name' => 'Testlast']],
                'response' => ['http_status' => 200, 'body' => ['code' => 101]],
            ],
        ], $handler->exchanges(), 'a skipped row sent nothing; no row holds another row\'s contact');

        $this->answer = static fn (array $contacts): Response => new Response(503, 'Unavailable');
        $handler->handle([$this->row(4, 1, $this->payload('shopper4@example.test'))]);

        self::assertSame([4], array_keys($handler->exchanges()), 'the exchanges are those of the last call');
        self::assertSame(['http_status' => 503, 'body' => null], $handler->exchanges()[4]['response']);
    }

    /**
     * @param array<int, array<string, string>> $settings settings by shop, over the global ones
     */
    private function handler(array $settings = []): ContactSyncHandler
    {
        $layout = new FakeStoreLayout([0 => [StoreLayout::SEPARATE_STORES => '1']]);
        $accounts = [
            1 => new Account(1, '', 'shopone', 'api-user', 'not-a-real-password'),
            2 => new Account(2, '', 'shoptwo', 'api-user', 'not-a-real-password'),
            4 => new Account(4, '', 'shopfour', 'api-user', ''),
        ];
        $scopes = new ScopeResolver(
            $layout,
            new AccountResolver($layout, static fn (int $idShop, string $language): ?Account => $language === '' ? $accounts[$idShop] ?? null : null),
            static fn (int $idLang) => self::LANGUAGES[$idLang] ?? false
        );

        return new ContactSyncHandler(
            $scopes,
            new FakeContactsSettings([0 => self::SETTINGS] + $settings),
            new SubscriberPayloadBuilder(
                static fn (int $idShop): string => 'shop' . $idShop . '.example.test',
                static fn (int $idGender): ?string => null,
                static fn (int $idGroup, int $idShop): ?string => null
            ),
            $this->transport(),
            function (array $rows): array {
                $this->askedForOvertaken[] = array_keys($rows);

                return $this->overtaken;
            }
        );
    }

    private function transport(): Transport
    {
        $test = $this;

        return new class($test) implements Transport {
            private ContactSyncHandlerTest $test;

            public function __construct(ContactSyncHandlerTest $test)
            {
                $this->test = $test;
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                return $this->test->answer((string) parse_url($url, PHP_URL_HOST), $options['auth_basic'], $options['json']);
            }
        };
    }

    /**
     * Records a request the fake transport received and answers it.
     *
     * @param array{string, string} $auth
     * @param list<array<string, mixed>> $contacts
     */
    public function answer(string $host, array $auth, array $contacts): Response
    {
        $this->requests[] = ['host' => explode('.', $host)[0], 'auth' => $auth, 'contacts' => $contacts];

        return ($this->answer)($contacts);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string|null>
     */
    private function row(int $id, int $idShop, array $payload): array
    {
        return [
            'id' => (string) $id,
            'id_shop' => (string) $idShop,
            'event_type' => ContactSyncHandler::EVENT_TYPE,
            'entity_id' => null,
            'language' => null,
            'payload' => (string) json_encode($payload),
            'status' => 'processing',
            'attempts' => '0',
            'claim_token' => 'token',
        ];
    }

    /**
     * A customer's event in the shape that Story 2.3 enqueues.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(string $email, array $overrides = []): array
    {
        return $overrides + [
            'email' => $email,
            'source' => 'customer',
            'subscribed' => true,
            'consent_changed' => false,
            'checkout' => false,
            'id_lang' => 1,
            'customer' => ['firstname' => 'Testfirst', 'lastname' => 'Testlast', 'birthday' => '0000-00-00', 'website' => ''],
        ];
    }
}
