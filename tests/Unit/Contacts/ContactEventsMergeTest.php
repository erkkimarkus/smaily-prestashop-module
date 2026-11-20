<?php
declare(strict_types=1);

namespace Tests\Unit\Contacts;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents;

/**
 * Two events of one address in one request become one row that keeps what either saw.
 */
final class ContactEventsMergeTest extends TestCase
{
    public function testAConsentChangeWinsOverALaterPlainProfileChange(): void
    {
        $merged = ContactEvents::merge(
            $this->event(['subscribed' => false, 'consent_changed' => true]),
            $this->event(['subscribed' => false, 'consent_changed' => false])
        );

        self::assertSame([false, true], [$merged['subscribed'], $merged['consent_changed']]);
    }

    public function testTheLaterEventGivesTheConsentAndTheProfile(): void
    {
        $merged = ContactEvents::merge(
            $this->event(['subscribed' => false, 'customer' => ['firstname' => 'Before']]),
            $this->event(['subscribed' => true, 'consent_changed' => true, 'customer' => ['firstname' => 'After']])
        );

        self::assertSame([true, true, ['firstname' => 'After']], [$merged['subscribed'], $merged['consent_changed'], $merged['customer']]);
    }

    public function testACheckoutOfEitherEventStays(): void
    {
        self::assertTrue(ContactEvents::merge($this->event(['checkout' => true]), $this->event([]))['checkout']);
        self::assertTrue(ContactEvents::merge($this->event([]), $this->event(['checkout' => true]))['checkout']);
        self::assertFalse(ContactEvents::merge($this->event([]), ['checkout' => null] + $this->event([]))['checkout']);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function event(array $values): array
    {
        return $values + [
            'email' => 'shopper1@example.test',
            'source' => 'customer',
            'subscribed' => true,
            'consent_changed' => false,
            'checkout' => false,
            'guest' => false,
            'id_lang' => 1,
            'customer' => [],
        ];
    }
}
