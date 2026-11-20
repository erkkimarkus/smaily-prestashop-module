<?php
declare(strict_types=1);

namespace Tests\Integration\Log;

use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;

require_once __DIR__ . '/LogTestCase.php';

/**
 * The resend guard reads the queue as stored: only a failed row may be sent again, and not a
 * withdrawn reminder, an erased contact's row, a row that a later delivery superseded, or a row
 * whose event already waits in the queue again.
 */
final class ResendGuardTest extends LogTestCase
{
    private const FAILED = ['status' => 'failed', 'attempts' => 5, 'last_error' => 'Smaily API request failed with HTTP 500'];

    public function testOnlyAFailedRowIsClearedAndEachPermanentRefusalHasItsReason(): void
    {
        $failed = self::event(self::FAILED);
        $sent = self::event(['entity_id' => 'shopper2@example.test']);
        $waiting = self::event(['entity_id' => 'shopper3@example.test', 'status' => 'pending']);
        $skipped = self::event(['entity_id' => 'shopper4@example.test', 'event_type' => 'automation.abandoned_cart', 'status' => 'skipped', 'last_error' => 'no_marketing_consent']);
        $withdrawn = self::event(['entity_id' => 'shopper5@example.test', 'event_type' => 'automation.abandoned_cart', 'status' => 'skipped', 'last_error' => 'ordered']);
        $erased = self::event(['entity_id' => ResendGuard::ERASED_ENTITY, 'payload' => '{"email":"[erased]"}'] + self::FAILED);

        self::assertSame([
            $sent => ResendGuard::REASON_NOT_FAILED,
            $waiting => ResendGuard::REASON_NOT_FAILED,
            $skipped => ResendGuard::REASON_NOT_FAILED,
            $withdrawn => ResendGuard::REASON_WITHDRAWN,
            $erased => ResendGuard::REASON_ERASED,
        ], $this->refusals($failed, $sent, $waiting, $skipped, $withdrawn, $erased));
    }

    public function testALaterDeliveryOfTheSameKindToTheSameContactSupersedesAnAutomation(): void
    {
        $welcome = ['event_type' => 'automation.welcome'] + self::FAILED;
        self::event(['event_type' => 'automation.welcome']);
        $superseded = self::event($welcome);
        $otherShop = self::event(['id_shop' => 2] + $welcome);
        $otherKind = self::event(['event_type' => 'automation.first_order'] + self::FAILED);
        $otherContact = self::event(['entity_id' => 'shopper2@example.test'] + $welcome);
        $noEntity = self::event(['entity_id' => null] + $welcome);
        self::event(['event_type' => 'automation.welcome', 'entity_id' => 'Shopper1@Example.TEST']);
        $afterTheDelivery = self::event($welcome);
        self::event(['event_type' => 'automation.welcome', 'status' => 'skipped', 'last_error' => 'no_workflow']);
        self::event(['event_type' => 'automation.welcome'] + self::FAILED);

        self::assertSame(
            [$superseded => ResendGuard::REASON_SUPERSEDED],
            $this->refusals($superseded, $otherShop, $otherKind, $otherContact, $noEntity, $afterTheDelivery),
            'an earlier delivery, a later skip and a later failure stand in no one\'s way'
        );
    }

    public function testARowWhoseEventWaitsInTheQueueAgainIsNotQueuedOnceMore(): void
    {
        $failed = self::event(self::FAILED);
        $other = self::event(['entity_id' => 'shopper2@example.test'] + self::FAILED);
        self::assertSame([], $this->refusals($failed, $other));

        $copy = self::event(['status' => 'pending']);
        self::assertSame([$failed => ResendGuard::REASON_QUEUED], $this->refusals($failed, $other));

        self::db()->update('smaily_connect_event_queue', ['status' => 'processing'], '`id` = ' . $copy);
        self::assertSame([$failed => ResendGuard::REASON_QUEUED], $this->refusals($failed, $other));

        self::db()->update('smaily_connect_event_queue', ['status' => 'failed'], '`id` = ' . $copy);
        self::assertSame([], $this->refusals($failed, $other), 'after the copy failed too, the row may go again');
    }

    public function testAContactSyncGoesAgainUnlessItWouldPutAStaleSubscriptionIntoSmaily(): void
    {
        $payload = static fn (bool $subscribed, bool $changed): string => (string) json_encode(
            ['email' => 'shopper1@example.test', 'source' => 'customer', 'subscribed' => $subscribed, 'consent_changed' => $changed]
        );
        $subscribe = self::event(['payload' => $payload(true, true)] + self::FAILED);
        $unsubscribe = self::event(['payload' => $payload(false, true)] + self::FAILED);
        $profile = self::event(['payload' => $payload(true, false)] + self::FAILED);

        self::event(['payload' => $payload(true, false)]);
        self::assertSame([], $this->refusals($subscribe, $unsubscribe, $profile), 'a later sync without a consent change supersedes nothing');

        self::event(['payload' => $payload(false, true)]);
        self::assertSame(
            [$subscribe => ResendGuard::REASON_SUPERSEDED],
            $this->refusals($subscribe, $unsubscribe, $profile),
            'after a later consent change only the row that would subscribe is held back'
        );
    }

    /**
     * @return array<int, string>
     */
    private function refusals(int ...$ids): array
    {
        return (new ResendGuard())->refusals(self::rows()->eventRows($ids));
    }
}
