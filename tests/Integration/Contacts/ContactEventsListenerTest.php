<?php
declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Flusher;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use Tests\Integration\IntegrationTestCase;

/**
 * A listener of the contact events gets each event of the request when it ends, merged per shop and
 * address, also where the shop's contact sync is not active; the rows it writes count with the
 * request's rows, and a listener that throws stops neither the contact row nor another listener.
 */
final class ContactEventsListenerTest extends IntegrationTestCase
{
    private const KEYS = [ContactsSettings::ENABLED, ContactsSettings::CONFIRMED];

    private int $shop;

    private int $idLang;

    protected function setUp(): void
    {
        parent::setUp();
        self::db()->delete(EventQueue::TABLE);
        $this->deleteKeys();
        $this->shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $this->idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::db()->delete(EventQueue::TABLE);
            $this->deleteKeys();
        }
        parent::tearDown();
    }

    public function testAListenerGetsTheMergedEventAndItsRowsCount(): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        $events = $this->events();
        $heard = [];
        $events->listen(static function (int $idShop, ?string $language, array $payload) use (&$heard): int {
            $heard[] = [$idShop, $language, $payload['consent_changed'], $payload['subscribed']];

            return 1;
        });

        $events->add($this->shop, $this->idLang, self::payload('listener1@example.test', true, true));
        $events->add($this->shop, $this->idLang, self::payload('listener1@example.test', true, false));

        self::assertSame(2, $events->commit(), 'the contact row and the listener\'s row');
        self::assertSame([[$this->shop, strtolower((string) \Language::getIsoById($this->idLang)), true, true]], $heard);
        self::assertSame(1, $this->rowCount());
    }

    public function testAListenerHearsAShopWhoseContactSyncIsNotActive(): void
    {
        $events = $this->events();
        $heard = 0;
        $events->listen(static function () use (&$heard): int {
            ++$heard;

            return 0;
        });

        $events->add($this->shop, $this->idLang, self::payload('listener1@example.test', true, true));

        self::assertSame(0, $events->commit());
        self::assertSame(1, $heard);
        self::assertSame(0, $this->rowCount(), 'no contact row where the contact sync is not active');
    }

    public function testAListenerThatThrowsStopsNothingElse(): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        $events = $this->events();
        $heard = 0;
        $events->listen(static function (): int {
            throw new \RuntimeException('listener1@example.test');
        });
        $events->listen(static function () use (&$heard): int {
            ++$heard;

            return 0;
        });

        $events->add($this->shop, $this->idLang, self::payload('listener1@example.test', true, true));

        self::assertSame(1, $events->commit());
        self::assertSame(1, $heard);
        self::assertSame(0, (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'log` WHERE `message` LIKE \'%listener1%\'',
            false
        ), 'the log line does not name the contact');
    }

    private function events(): ContactEvents
    {
        return new ContactEvents(
            new EventQueue(),
            new ContactsSettings(),
            new Lock(),
            static function (): Flusher {
                throw new \LogicException('No flush in this test.');
            },
            null,
            static function (): void {
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(string $email, bool $subscribed, bool $consentChanged): array
    {
        return [
            'email' => $email,
            'source' => 'customer',
            'subscribed' => $subscribed,
            'consent_changed' => $consentChanged,
            'checkout' => false,
            'guest' => false,
            'id_lang' => 1,
            'customer' => [],
        ];
    }

    private function rowCount(): int
    {
        return (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`', false);
    }

    private function deleteKeys(): void
    {
        foreach (self::KEYS as $key) {
            \Configuration::deleteByName($key);
        }
    }
}
