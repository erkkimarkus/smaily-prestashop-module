<?php
declare(strict_types=1);

namespace Tests\Unit\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\DashboardView;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickState;

/**
 * What the Dashboard says for each state of the store, in Magento's words where Magento has them:
 * one verdict with the most important problem first and the link that fixes it, the connection
 * cards, the four tiles and Recent activity.
 */
final class DashboardViewTest extends TestCase
{
    private const NOW = 1763640000;

    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    /**
     * Each verdict: the facts that differ from a healthy store, then the state, its kicker, its
     * sentence and the label and route of its link (null: none).
     *
     * @return array<string, array{array<string, mixed>, string, string, string, ?string, ?string}>
     */
    public function verdicts(): array
    {
        $ok = ['All systems normal', 'Everything is running — deliveries to Smaily are flowing normally.', 'View full log', 'smailyforprestashop_log'];
        $upgrade = ['Needs attention', 'Smaily Connect stays paused until the upgrade finishes.', null, null];
        $incomplete = ['Setup incomplete', 'Setup is not finished yet — complete the initial setup to start syncing.', 'Resume setup', 'smailyforprestashop_setup'];
        $disconnected = ['Not connected', 'The Smaily credentials are missing or Smaily has not accepted them — check them in Settings > Connection and test the connection.', 'Open Connection settings', 'smailyforprestashop_settings'];
        $stopped = ['Needs attention', 'Background tasks are not running — nothing goes to Smaily until they run again.', 'Add the cron line', null];
        $failedOne = ['Needs attention', 'Smaily Connect is running, but 1 delivery failed in the last 24 hours.', 'Review failures', 'smailyforprestashop_log'];
        $failedMany = ['Needs attention', 'Smaily Connect is running, but 3 deliveries failed in the last 24 hours.', 'Review failures', 'smailyforprestashop_log'];
        $noAccount = ['account' => null, 'any_account' => false];
        $notConfirmed = ['contacts_confirmed' => false];
        $noTick = ['tick' => TickState::fromJson(null)];

        return [
            'a healthy store' => array_merge([[], DashboardView::VERDICT_OK], $ok),
            'shop visits run the tasks' => array_merge([['tick' => TickState::fromJson(null)->withTick('traffic', self::NOW - 60)], DashboardView::VERDICT_OK], $ok),
            'the upgrade is pending' => array_merge([['upgrade' => 'pending'], DashboardView::VERDICT_UPGRADE], $upgrade),
            'the upgrade failed' => array_merge([['upgrade' => 'failed'], DashboardView::VERDICT_UPGRADE], $upgrade),
            'the upgrade outranks everything' => array_merge([['upgrade' => 'pending'] + $noAccount + $notConfirmed + $noTick + ['failed_24h' => 3], DashboardView::VERDICT_UPGRADE], $upgrade),
            'contact sync is on but not confirmed' => array_merge([$notConfirmed, DashboardView::VERDICT_INCOMPLETE], $incomplete),
            'setup outranks the connection' => array_merge([$notConfirmed + $noAccount, DashboardView::VERDICT_INCOMPLETE], $incomplete),
            'contact sync is off' => array_merge([['contacts_enabled' => false, 'contacts_confirmed' => false], DashboardView::VERDICT_OK], $ok),
            'no Smaily account' => array_merge([$noAccount, DashboardView::VERDICT_DISCONNECTED], $disconnected),
            'an account whose password does not decrypt' => array_merge([['account' => ['subdomain' => 'demo', 'connected' => false]], DashboardView::VERDICT_DISCONNECTED], $disconnected),
            'the connection outranks stopped tasks' => array_merge([$noAccount + $noTick, DashboardView::VERDICT_DISCONNECTED], $disconnected),
            'all shops of separate stores, each with its own account' => array_merge([['all_shops' => true, 'separate_stores' => true, 'account' => null], DashboardView::VERDICT_OK], $ok),
            'all shops of separate stores, no account anywhere' => array_merge([['all_shops' => true, 'separate_stores' => true] + $noAccount, DashboardView::VERDICT_DISCONNECTED], $disconnected),
            'no tick ever' => array_merge([$noTick, DashboardView::VERDICT_STOPPED], $stopped),
            'no tick for 16 minutes' => array_merge([['tick' => TickState::fromJson(null)->withTick('tick', self::NOW - 960)], DashboardView::VERDICT_STOPPED], $stopped),
            'stopped tasks outrank failures' => array_merge([$noTick + ['failed_24h' => 3], DashboardView::VERDICT_STOPPED], $stopped),
            'one failed delivery' => array_merge([['failed_24h' => 1], DashboardView::VERDICT_DEGRADED], $failedOne),
            'three failed deliveries' => array_merge([['failed_24h' => 3], DashboardView::VERDICT_DEGRADED], $failedMany),
        ];
    }

    /**
     * @dataProvider verdicts
     *
     * @param array<string, mixed> $facts
     */
    public function testOneVerdictNamesTheMostImportantProblem(array $facts, string $state, string $kicker, string $text, ?string $action, ?string $route): void
    {
        $verdict = self::view($facts)['verdict'];

        self::assertSame($state, $verdict['state']);
        self::assertSame($kicker, $verdict['kicker']);
        self::assertSame($text, $verdict['text']);
        self::assertSame($action, $verdict['action']['label'] ?? null);
        self::assertSame($route, $verdict['action']['route'] ?? null);
    }

    public function testEachLinkOpensWhatFixesTheProblem(): void
    {
        self::assertSame(['tab' => 'connection'], self::view(['account' => null, 'any_account' => false])['verdict']['action']['params']);
        self::assertSame(['status' => 'failed'], self::view(['failed_24h' => 2])['verdict']['action']['params']);
        self::assertSame('smaily-background', self::view(['tick' => TickState::fromJson(null)])['verdict']['action']['anchor']);
        self::assertTrue(self::view(['failed_24h' => 2])['verdict']['action']['danger']);
        self::assertFalse(self::view()['verdict']['action']['danger']);
    }

    public function testTheVerdictTakesTheColourOfItsState(): void
    {
        self::assertSame('success', self::view()['verdict']['tone']);
        self::assertSame('warning', self::view(['failed_24h' => 1])['verdict']['tone']);
        self::assertSame('warning', self::view(['contacts_confirmed' => false])['verdict']['tone']);
        self::assertSame('danger', self::view(['account' => null, 'any_account' => false])['verdict']['tone']);
    }

    public function testFailuresShowABannerUnlessSetupOrTheConnectionOutranksThem(): void
    {
        self::assertNull(self::view()['failed_banner']);
        self::assertSame('1 event failed in the last 24 hours', self::view(['failed_24h' => 1])['failed_banner']['title']);
        self::assertSame('3 events failed in the last 24 hours', self::view(['failed_24h' => 3])['failed_banner']['title']);
        self::assertSame('Review failures', self::view(['failed_24h' => 3])['failed_banner']['action']['label']);
        self::assertNotNull(self::view(['failed_24h' => 3, 'tick' => TickState::fromJson(null)])['failed_banner'], 'stopped tasks do not hide the failures');
        self::assertNull(self::view(['failed_24h' => 3, 'account' => null, 'any_account' => false])['failed_banner']);
        self::assertNull(self::view(['failed_24h' => 3, 'contacts_confirmed' => false])['failed_banner']);
        self::assertNull(self::view(['failed_24h' => 3, 'upgrade' => 'pending'])['failed_banner']);
    }

    public function testTheInvitationShowsWhileNoAccountIsConnectedToAnAdminWhoMayConnectOne(): void
    {
        self::assertFalse(self::view()['invitation']);
        self::assertTrue(self::view(['account' => null, 'any_account' => false])['invitation']);
        self::assertFalse(self::view(['account' => null, 'any_account' => false, 'can_open_settings' => false])['invitation']);
        self::assertFalse(self::view(['account' => null])['invitation'], 'another shop has an account');
        self::assertFalse(self::view(['account' => null, 'any_account' => false, 'upgrade' => 'pending'])['invitation'], 'the account is not read during the upgrade');
    }

    /**
     * Each Smaily card: the facts, then whether the card is on, its sub-line, its note, and its
     * pill's variant and label.
     *
     * @return array<string, array{array<string, mixed>, bool, string, ?string, string, string}>
     */
    public function smailyCards(): array
    {
        return [
            'connected' => [[], true, 'Connected (demo)', null, 'active', 'Connected'],
            'no account' => [['account' => null, 'any_account' => false], false, 'Credentials missing or invalid', null, 'failed', 'Not connected'],
            'no password' => [['account' => ['subdomain' => 'demo', 'connected' => false]], false, 'Credentials missing or invalid', null, 'failed', 'Not connected'],
            'all shops, the account saved for all shops' => [['all_shops' => true], true, 'Connected (demo)', 'Saved for all shops', 'active', 'Connected'],
            'all shops of separate stores, each with its own account' => [['all_shops' => true, 'separate_stores' => true, 'account' => null], false, 'No Smaily account is saved for all shops, so each shop uses its own.', 'Pick a shop in the shop selector to see its connection.', 'off', 'Per shop'],
            'all shops of separate stores, no account' => [['all_shops' => true, 'separate_stores' => true, 'account' => null, 'any_account' => false], false, 'Credentials missing or invalid', 'Saved for all shops', 'failed', 'Not connected'],
            'the upgrade is not finished' => [['upgrade' => 'pending', 'account' => null, 'any_account' => false], false, 'Waits for the upgrade to finish', null, 'off', 'Paused'],
        ];
    }

    /**
     * @dataProvider smailyCards
     *
     * @param array<string, mixed> $facts
     */
    public function testTheSmailyCardNamesTheConnectedSubdomain(array $facts, bool $on, string $sub, ?string $note, string $variant, string $label): void
    {
        $card = self::view($facts)['connections']['smaily'];

        self::assertSame($on, $card['on']);
        self::assertSame($sub, $card['sub']);
        self::assertSame($note, $card['note']);
        self::assertSame(['variant' => $variant, 'label' => $label], $card['pill']);
    }

    public function testIntelligenceAndBrowseTrackingAreOffUntilTheirStoryConnectsThem(): void
    {
        $cards = self::view()['connections'];

        self::assertSame(['on' => false, 'sub' => 'Not connected', 'note' => null, 'pill' => ['variant' => 'off', 'label' => 'Off']], $cards['intelligence']);
        self::assertSame(['on' => false, 'sub' => 'Disabled', 'note' => null, 'pill' => ['variant' => 'off', 'label' => 'Off']], $cards['tracking']);
    }

    /**
     * Each Background tasks card: the tick, then whether the card is on, its sub-line, its pill and
     * the line that says what to do (null: none).
     *
     * @return array<string, array{TickState, bool, string, array{variant: string, label: string}, ?string}>
     */
    public function backgroundCards(): array
    {
        $none = TickState::fromJson(null);

        return [
            'a cron URL a minute ago' => [$none->withTick('tick', self::NOW - 60), true, 'Last run 2025-11-20 11:59 from the cron URL', ['variant' => 'active', 'label' => 'Running'], null],
            'shop visits only' => [$none->withTick('traffic', self::NOW - 60), false, 'Last run 2025-11-20 11:59 from shop visits', ['variant' => 'pending', 'label' => 'Visitors only'], 'Tasks run only when visitors come; add the cron line.'],
            'no tick for 16 minutes' => [$none->withTick('customer_cron', self::NOW - 960), false, 'Last run 2025-11-20 11:44 from the cron URL', ['variant' => 'failed', 'label' => 'Not running'], 'Background tasks are not running; add the cron line.'],
            'never' => [$none, false, 'Never run', ['variant' => 'failed', 'label' => 'Not running'], 'Background tasks are not running; add the cron line.'],
        ];
    }

    /**
     * @dataProvider backgroundCards
     *
     * @param array{variant: string, label: string} $pill
     */
    public function testTheBackgroundCardSaysWhenAndFromWhereTheLastTickRan(TickState $tick, bool $on, string $sub, array $pill, ?string $hint): void
    {
        $card = self::view(['tick' => $tick])['connections']['background'];

        self::assertSame($on, $card['on']);
        self::assertSame($sub, $card['sub']);
        self::assertSame($pill, $card['pill']);
        self::assertSame($hint, $card['hint']);
    }

    /**
     * @dataProvider backgroundCards
     */
    public function testTheCronLineIsOfferedInEveryStateToAnAdminWhoMayConfigure(TickState $tick, bool $on): void
    {
        $card = self::view(['tick' => $tick])['connections']['background'];
        self::assertTrue($card['cron_button']);
        self::assertNull($card['ask_admin']);

        $readOnly = self::view(['tick' => $tick, 'can_configure' => false])['connections']['background'];
        self::assertFalse($readOnly['cron_button']);
        self::assertSame(
            $on ? null : 'Ask an administrator who may change the Smaily Connect settings to add the cron line.',
            $readOnly['ask_admin'],
            'only while the tasks need the cron line'
        );
    }

    public function testTheTilesCountWhatMagentoCounts(): void
    {
        $tiles = self::view(['contact_syncs' => ['count' => 12, 'more' => false], 'queued_today' => 2])['tiles'];

        self::assertSame(['value' => '12', 'caption' => 'delivered, last 30 days'], $tiles['contact_syncs']);
        self::assertNull($tiles['catalog_items'], 'hidden without a Campaign Intelligence connection, as Magento does');
        self::assertSame(['value' => '2', 'caption' => 'events waiting to send'], $tiles['queued_today']);
        self::assertSame(['value' => '0', 'caption' => 'last 24 hours', 'attention' => false, 'badge' => null, 'link' => null], $tiles['failed']);
    }

    public function testACountThatStoppedAtItsBoundSaysSo(): void
    {
        self::assertSame('50000+', self::view(['contact_syncs' => ['count' => 50000, 'more' => true]])['tiles']['contact_syncs']['value']);
    }

    public function testTheFailedTileLinksToTheFailedRows(): void
    {
        self::assertSame(
            ['value' => '4', 'caption' => null, 'attention' => true, 'badge' => 'Attention', 'link' => ['label' => 'View failed deliveries', 'route' => 'smailyforprestashop_log', 'params' => ['status' => 'failed']]],
            self::view(['failed_24h' => 4])['tiles']['failed']
        );
    }

    public function testTheTilesShowADashWhileTheUpgradeKeepsTheQueueUnread(): void
    {
        $tiles = self::view(['upgrade' => 'pending', 'contact_syncs' => null, 'queued_today' => null, 'failed_24h' => null])['tiles'];

        self::assertSame('—', $tiles['contact_syncs']['value']);
        self::assertSame('—', $tiles['queued_today']['value']);
        self::assertSame('—', $tiles['failed']['value']);
        self::assertFalse($tiles['failed']['attention']);
    }

    public function testRecentActivityListsQueueRowsAndImportJobs(): void
    {
        $activity = self::view(['activity' => [
            ['kind' => 'queue', 'type' => 'contact.sync', 'entity' => '0a1b2c3d4e5f…', 'status' => 'processing', 'updated_at' => self::NOW - 10],
            ['kind' => 'job', 'type' => 'contacts_import', 'total' => 1, 'status' => 'running', 'updated_at' => self::NOW - 20],
            ['kind' => 'job', 'type' => 'contacts_refresh', 'total' => 250, 'status' => 'done', 'updated_at' => self::NOW - 30],
            ['kind' => 'queue', 'type' => 'automation.welcome', 'entity' => '—', 'status' => 'skipped', 'updated_at' => self::NOW - 40],
        ]])['activity'];

        self::assertSame('No deliveries yet. Once contacts, orders or catalog changes start flowing, the latest queue rows show up here.', $activity['empty']);
        self::assertSame([
            ['source' => 'Smaily', 'type' => 'contact.sync', 'entity' => '0a1b2c3d4e5f…', 'status' => ['variant' => 'pending', 'label' => 'Sending'], 'updated' => '2025-11-20 11:59:50'],
            ['source' => 'Smaily', 'type' => 'contacts_import', 'entity' => '1 contact', 'status' => ['variant' => 'pending', 'label' => 'Running'], 'updated' => '2025-11-20 11:59:40'],
            ['source' => 'Smaily', 'type' => 'contacts_refresh', 'entity' => '250 contacts', 'status' => ['variant' => 'sent', 'label' => 'Done'], 'updated' => '2025-11-20 11:59:30'],
            ['source' => 'Smaily', 'type' => 'automation.welcome', 'entity' => '—', 'status' => ['variant' => 'neutral', 'label' => 'Skipped'], 'updated' => '2025-11-20 11:59:20'],
        ], $activity['rows']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public function statuses(): array
    {
        return [
            'queue pending' => ['queue', 'pending', 'pending Pending'],
            'queue processing' => ['queue', 'processing', 'pending Sending'],
            'queue sent' => ['queue', 'sent', 'sent Sent'],
            'queue failed' => ['queue', 'failed', 'failed Failed'],
            'queue skipped' => ['queue', 'skipped', 'neutral Skipped'],
            'job queued' => ['job', 'queued', 'pending Queued'],
            'job running' => ['job', 'running', 'pending Running'],
            'job done' => ['job', 'done', 'sent Done'],
            'job failed' => ['job', 'failed', 'failed Failed'],
            'job cancelled' => ['job', 'cancelled', 'neutral Cancelled'],
        ];
    }

    /**
     * @dataProvider statuses
     */
    public function testEachStatusHasItsLabelAndPill(string $kind, string $status, string $expected): void
    {
        $row = self::view(['activity' => [['kind' => $kind, 'type' => 't', 'entity' => '—', 'total' => 0, 'status' => $status, 'updated_at' => self::NOW]]])['activity']['rows'][0];

        self::assertSame($expected, $row['status']['variant'] . ' ' . $row['status']['label']);
    }

    /**
     * @param array<string, mixed> $facts the facts that differ from a healthy store
     *
     * @return array<string, mixed>
     */
    private static function view(array $facts = []): array
    {
        $translate = static fn (string $id, array $parameters = []): string => strtr($id, $parameters);

        return (new DashboardView($translate))->build($facts + [
            'upgrade' => null,
            'all_shops' => false,
            'separate_stores' => false,
            'account' => ['subdomain' => 'demo', 'connected' => true],
            'any_account' => true,
            'contacts_enabled' => true,
            'contacts_confirmed' => true,
            'tick' => TickState::fromJson(null)->withTick('tick', self::NOW - 30),
            'now' => self::NOW,
            'contact_syncs' => ['count' => 0, 'more' => false],
            'queued_today' => 0,
            'failed_24h' => 0,
            'activity' => [],
            'can_configure' => true,
            'can_open_settings' => true,
        ]);
    }
}
