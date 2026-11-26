<?php
declare(strict_types=1);

namespace Tests\Integration\Log;

use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;

require_once __DIR__ . '/LogTestCase.php';

/**
 * The resend guard reads the queue as stored: only a failed row may be sent again, and not a
 * withdrawn reminder, an erased contact's row or an automation that a later delivery superseded
 * (Magento's refusals, owner, 2026-11-21), nor a contact sync that a newer delivered change of the
 * contact overtook (owner, 2026-11-26). A row whose event waits in the queue again may go again.
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

    /**
     * As in Magento, where each "Send again" queues a row: a row whose event waits in the queue
     * again, as a pending or a processing copy, is not refused.
     */
    public function testARowWhoseEventWaitsInTheQueueAgainMayStillGoAgain(): void
    {
        $failed = self::event(self::FAILED);
        $welcome = self::event(['event_type' => 'automation.welcome'] + self::FAILED);
        self::assertSame([], $this->refusals($failed, $welcome));

        $copy = self::event(['status' => 'pending']);
        $welcomeCopy = self::event(['event_type' => 'automation.welcome', 'status' => 'pending']);
        self::assertSame([], $this->refusals($failed, $welcome));

        self::db()->update('smaily_connect_event_queue', ['status' => 'processing'], '`id` IN (' . $copy . ', ' . $welcomeCopy . ')');
        self::assertSame([], $this->refusals($failed, $welcome));
    }

    /**
     * A failed contact sync is refused once a newer change of the same contact in the same shop
     * reached Smaily: the retry of a subscription after a delivered unsubscribe would undo it. A
     * change that is newer than every delivered one, an unsubscribe after a delivered subscription
     * included, may go again.
     */
    public function testAContactSyncThatANewerDeliveredChangeOvertookIsRefused(): void
    {
        $subscribe = self::event(['payload' => self::change(true, true)] + self::FAILED);
        $otherShop = self::event(['id_shop' => 2, 'payload' => self::change(true, true)] + self::FAILED);
        $otherContact = self::event(['entity_id' => 'shopper2@example.test', 'payload' => self::change(true, true)] + self::FAILED);
        $noEntity = self::event(['entity_id' => null, 'payload' => self::change(true, true)] + self::FAILED);
        self::event(['entity_id' => 'Shopper1@Example.TEST', 'payload' => self::change(false, true)]);
        $unsubscribeAfter = self::event(['payload' => self::change(false, true)] + self::FAILED);
        self::event(['payload' => self::change(true, true), 'status' => 'pending']);
        self::event(['payload' => self::change(true, true), 'status' => 'skipped', 'last_error' => 'not_in_audience']);
        self::event(['payload' => self::change(true, true)] + self::FAILED);

        self::assertSame(
            [$subscribe => ResendGuard::REASON_NEWER_CHANGE],
            $this->refusals($subscribe, $otherShop, $otherContact, $noEntity, $unsubscribeAfter),
            'a later row that waits, was skipped or failed overtakes nothing'
        );
        self::assertSame(
            'A newer change of this contact was already sent to Smaily; sending this older one again would undo it.',
            ResendGuard::message(ResendGuard::REASON_NEWER_CHANGE)
        );
    }

    /**
     * A newer row without a consent change sends no subscription status, so it overtakes only an
     * older row without one: an older unsubscribe is never lost to a newer profile save.
     */
    public function testANewerProfileSaveOvertakesOnlyAnOlderRowWithoutAConsentChange(): void
    {
        $unsubscribe = self::event(['payload' => self::change(false, true)] + self::FAILED);
        $profile = self::event(['payload' => self::change(false, false)] + self::FAILED);
        self::event(['payload' => self::change(false, false)]);

        self::assertSame([$profile => ResendGuard::REASON_NEWER_CHANGE], $this->refusals($unsubscribe, $profile));
    }

    /**
     * A copy that the Log's "Send again" queued keeps the place of the change it repeats: delivered,
     * it overtakes no change recorded after the original, and a failed copy is overtaken by a change
     * recorded after the original.
     */
    public function testACopyOfSendAgainKeepsThePlaceOfTheChangeItRepeats(): void
    {
        $subscribe = self::event(['payload' => self::change(true, true)] + self::FAILED);
        $unsubscribe = self::event(['payload' => self::change(false, true)] + self::FAILED);
        $copy = self::event(['payload' => self::change(true, true, ['of' => $subscribe, 'origin' => $subscribe])] + self::FAILED);
        $copyOfCopy = self::event(['payload' => self::change(true, true, ['of' => $copy, 'origin' => $subscribe])]);

        self::assertSame([], $this->refusals($unsubscribe), 'the delivered copy repeats the older subscription');

        self::db()->update('smaily_connect_event_queue', ['status' => 'sent'], '`id` = ' . $unsubscribe);
        self::db()->update('smaily_connect_event_queue', ['status' => 'failed'], '`id` = ' . $copyOfCopy);
        self::assertSame(
            [$subscribe => ResendGuard::REASON_NEWER_CHANGE, $copy => ResendGuard::REASON_NEWER_CHANGE, $copyOfCopy => ResendGuard::REASON_NEWER_CHANGE],
            $this->refusals($subscribe, $copy, $copyOfCopy)
        );
    }

    /**
     * A contact sync's payload with its consent, and the record of a "Send again" when given.
     *
     * @param array<string, int>|null $resend
     */
    private static function change(bool $subscribed, bool $changed, ?array $resend = null): string
    {
        $payload = ['email' => 'shopper1@example.test', 'source' => 'customer', 'subscribed' => $subscribed, 'consent_changed' => $changed];
        if ($resend !== null) {
            $payload['_resend'] = $resend + ['by' => 'Testfirst Testlast', 'at' => '2026-11-20T10:00:00Z'];
        }

        return (string) json_encode($payload);
    }

    /**
     * @return array<int, string>
     */
    private function refusals(int ...$ids): array
    {
        return (new ResendGuard())->refusals(self::rows()->eventRows($ids));
    }
}
