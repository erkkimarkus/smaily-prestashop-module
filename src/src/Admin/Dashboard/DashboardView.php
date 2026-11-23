<?php
/**
 * 2024 Smaily
 *
 * NOTICE OF LICENSE
 *
 * Smaily for PrestaShop is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Smaily for PrestaShop is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Smaily for PrestaShop. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Smaily <info@smaily.com>
 * @copyright 2024 Smaily
 * @license   GPL3
 */
declare(strict_types=1);

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\IntelligencePanel;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogRows;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickState;

/**
 * What the Dashboard says, from the facts that DashboardFacts reads: the verdict, the failures
 * banner, the connection cards, the tiles and Recent activity, in Magento's words where Magento has
 * them (connect-magento: view/adminhtml/templates/dashboard/index.phtml, DashboardData), "website"
 * read as "shop".
 *
 * One verdict names the most important problem, in this order: the upgrade is not finished (the
 * shell's banner above offers "Finish upgrade"); contact sync is on but not confirmed (Magento's
 * "Setup incomplete"); no Smaily account with credentials (Magento's "Not connected"); no tick for
 * BackgroundTasks::STOPPED_SECONDS; Campaign Intelligence refuses the connection; failed rows in
 * the last 24 hours; Campaign Intelligence does not answer; no nightly product list for
 * UNSENT_PRODUCT_LIST_NIGHTS nights; two connections of the installation share one engine store,
 * so neither sends that list (ARCHITECTURE 5.3; only PrestaShop has this case); else all is well.
 *
 * The facts:
 * - upgrade: null, or `pending` / `failed` while the migration is not finished;
 * - all_shops: multishop is on and PrestaShop's shop selector is on "All shops";
 * - separate_stores: the shops are separate stores, so no shop uses the account saved for all shops;
 * - account: the Smaily account of the selected scope, `subdomain` and `connected` (it has
 *   credentials), or null;
 * - any_account: some shop or the whole store has an account with credentials;
 * - contacts_enabled, contacts_confirmed: the selected scope's contact sync switch and confirmation;
 * - tick (TickState), now (Unix time);
 * - engine: what EngineFacts::collect() reads of Campaign Intelligence for the selected setup; null
 *   while the upgrade keeps the tables unread;
 * - contact_syncs: `count` and `more` (the count stopped at its bound), queued_today, failed_24h:
 *   null while the upgrade keeps the queue unread;
 * - activity: the latest rows, newest first: `kind` (`queue` or `job`), `source` (Log\LogRows:
 *   Smaily or Campaign Intelligence), `type`, `status`, `updated_at` (Unix time), and `entity`
 *   (already an EntityLabel) or `total` (what a job counts);
 * - can_configure: the employee may change the settings (the cron line is theirs to see);
 *   can_open_settings: the employee may open Settings (the invitation is theirs to act on).
 */
final class DashboardView
{
    public const VERDICT_UPGRADE = 'upgrade';

    public const VERDICT_INCOMPLETE = 'incomplete';

    public const VERDICT_DISCONNECTED = 'disconnected';

    public const VERDICT_STOPPED = 'stopped';

    public const VERDICT_DEGRADED = 'degraded';

    public const VERDICT_ENGINE_REFUSED = 'engine_refused';

    public const VERDICT_ENGINE_UNREACHABLE = 'engine_unreachable';

    public const VERDICT_NO_PRODUCT_LIST = 'no_product_list';

    public const VERDICT_SHARED_ENGINE_STORE = 'shared_engine_store';

    public const VERDICT_OK = 'ok';

    /** Nights in a row without a nightly product list before the Dashboard says so (as Magento). */
    public const UNSENT_PRODUCT_LIST_NIGHTS = 3;

    private const SETTINGS = 'smailyforprestashop_settings';

    private const LOG = 'smailyforprestashop_log';

    private const LOG_FAILED = ['status' => 'failed'];

    /** The pill of each queue status, as Magento's Log draws it (StatusPill), and of each job status. */
    private const PILLS = [
        'queue' => ['pending' => 'pending', 'processing' => 'pending', 'sent' => 'sent', 'failed' => 'failed'],
        'job' => ['queued' => 'pending', 'running' => 'pending', 'done' => 'sent', 'failed' => 'failed'],
    ];

    /** @var callable(string, array<string, string|int>=): string */
    private $translate;

    /**
     * @param callable(string, array<string, string|int>=): string $translate the admin's translation
     *                                                                        of an EN string
     */
    public function __construct(callable $translate)
    {
        $this->translate = $translate;
    }

    /**
     * @param array<string, mixed> $facts
     *
     * @return array<string, mixed>
     */
    public function build(array $facts): array
    {
        $background = BackgroundTasks::state($facts['tick'], $facts['now']);
        $verdict = $this->verdictState($facts, $background);
        $failed = $facts['failed_24h'];
        $engine = $facts['engine'];

        return [
            'invitation' => $facts['upgrade'] === null && !$facts['any_account'] && $facts['can_open_settings'],
            // The siblings' rule: a store whose Smaily account is connected and that has no
            // Campaign Intelligence connection, to an admin who may open Settings.
            'intelligence_invitation' => $engine !== null && !$engine['per_shop'] && !$engine['connected']
                && ($facts['account']['connected'] ?? false) && $facts['can_open_settings'],
            'verdict' => $this->verdict($verdict, $facts),
            'failed_banner' => $failed > 0 && !in_array($verdict, [self::VERDICT_UPGRADE, self::VERDICT_INCOMPLETE, self::VERDICT_DISCONNECTED], true)
                ? [
                    'title' => $failed === 1
                        ? $this->trans('%count% event failed in the last 24 hours', ['%count%' => $failed])
                        : $this->trans('%count% events failed in the last 24 hours', ['%count%' => $failed]),
                    'action' => $this->link($this->trans('Review failures'), self::LOG, self::LOG_FAILED, true),
                ]
                : null,
            'connections' => [
                'smaily' => $this->smailyCard($facts),
                'intelligence' => $this->intelligenceCard($engine),
                'tracking' => $this->trackingCard($engine),
                'background' => $this->backgroundCard($facts, $background),
            ],
            'tiles' => $this->tiles($facts),
            'activity' => [
                'empty' => $this->trans('No deliveries yet. Once contacts, orders or catalog changes start flowing, the latest queue rows show up here.'),
                'rows' => array_map([$this, 'activityRow'], $facts['activity']),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function verdictState(array $facts, string $background): string
    {
        if ($facts['upgrade'] !== null) {
            return self::VERDICT_UPGRADE;
        }
        if ($facts['contacts_enabled'] && !$facts['contacts_confirmed']) {
            return self::VERDICT_INCOMPLETE;
        }
        if (self::perShop($facts) ? !$facts['any_account'] : !($facts['account']['connected'] ?? false)) {
            return self::VERDICT_DISCONNECTED;
        }
        if ($background === BackgroundTasks::STOPPED) {
            return self::VERDICT_STOPPED;
        }
        $engine = $facts['engine'];
        if ($engine !== null && self::refused($engine)) {
            return self::VERDICT_ENGINE_REFUSED;
        }
        if ($facts['failed_24h'] > 0) {
            return self::VERDICT_DEGRADED;
        }
        if ($engine !== null && $engine['unreachable']) {
            return self::VERDICT_ENGINE_UNREACHABLE;
        }
        if ($engine !== null && $engine['unsent_nights'] >= self::UNSENT_PRODUCT_LIST_NIGHTS) {
            return self::VERDICT_NO_PRODUCT_LIST;
        }
        if ($engine !== null && $engine['shared_store']) {
            return self::VERDICT_SHARED_ENGINE_STORE;
        }

        return self::VERDICT_OK;
    }

    /**
     * @param array<string, mixed> $facts
     *
     * @return array<string, mixed>
     */
    private function verdict(string $state, array $facts): array
    {
        $failed = (int) $facts['failed_24h'];
        switch ($state) {
            case self::VERDICT_UPGRADE:
                return self::verdictOf($state, 'warning', $this->trans('Needs attention'), $this->trans('Smaily Connect stays paused until the upgrade finishes.'), null);
            case self::VERDICT_INCOMPLETE:
                return self::verdictOf($state, 'warning', $this->trans('Setup incomplete'), $this->trans('Setup is not finished yet — complete the initial setup to start syncing.'), $this->link($this->trans('Resume setup'), 'smailyforprestashop_setup'));
            case self::VERDICT_DISCONNECTED:
                return self::verdictOf($state, 'danger', $this->trans('Not connected'), $this->trans('The Smaily credentials are missing or Smaily has not accepted them — check them in Settings > Connection and test the connection.'), $this->link($this->trans('Open Connection settings'), self::SETTINGS, ['tab' => 'connection']));
            case self::VERDICT_STOPPED:
                return self::verdictOf($state, 'warning', $this->trans('Needs attention'), $this->trans('Background tasks are not running — nothing goes to Smaily until they run again.'), ['label' => $this->trans('Add the cron line'), 'route' => null, 'params' => [], 'anchor' => 'smaily-background', 'danger' => false]);
            case self::VERDICT_DEGRADED:
                $text = $failed === 1
                    ? $this->trans('Smaily Connect is running, but %count% delivery failed in the last 24 hours.', ['%count%' => $failed])
                    : $this->trans('Smaily Connect is running, but %count% deliveries failed in the last 24 hours.', ['%count%' => $failed]);

                return self::verdictOf($state, 'warning', $this->trans('Needs attention'), $text, $this->link($this->trans('Review failures'), self::LOG, self::LOG_FAILED, true));
            case self::VERDICT_ENGINE_REFUSED:
                return self::verdictOf($state, 'warning', $this->trans('Needs attention'), $this->refusal($facts['engine']['state']), $this->link($this->trans('Open Intelligence settings'), self::SETTINGS, ['tab' => 'intelligence']));
            case self::VERDICT_ENGINE_UNREACHABLE:
                return self::verdictOf($state, 'warning', $this->trans('Needs attention'), $this->trans('Smaily Connect is running, but Campaign Intelligence is currently unreachable — data is queued locally and will sync when it recovers.'), null);
            case self::VERDICT_NO_PRODUCT_LIST:
                return self::verdictOf($state, 'warning', $this->trans('Needs attention'), $this->trans('Smaily Connect is running, but no nightly product list has gone to Campaign Intelligence for %count% nights.', ['%count%' => $facts['engine']['unsent_nights']]), null);
            case self::VERDICT_SHARED_ENGINE_STORE:
                return self::verdictOf($state, 'warning', $this->trans('Needs attention'), $this->trans('Two shops of this installation share one Campaign Intelligence store, so the nightly product list is not sent for them: a deleted product or a stock change reaches Campaign Intelligence only with the product’s next change.'), null);
            default:
                return self::verdictOf($state, 'success', $this->trans('All systems normal'), $this->trans('Everything is running — deliveries to Smaily are flowing normally.'), $this->link($this->trans('View full log'), self::LOG));
        }
    }

    /**
     * @param array<string, mixed> $facts
     *
     * @return array<string, mixed>
     */
    private function smailyCard(array $facts): array
    {
        if ($facts['upgrade'] !== null) {
            return self::card(false, $this->trans('Waits for the upgrade to finish'), 'off', $this->trans('Paused'));
        }
        $note = $facts['all_shops'] ? $this->trans('Saved for all shops') : null;
        if ($facts['account']['connected'] ?? false) {
            return self::card(true, $this->trans('Connected (%subdomain%)', ['%subdomain%' => $facts['account']['subdomain']]), 'active', $this->trans('Connected'), $note);
        }
        if (self::perShop($facts) && $facts['any_account']) {
            return self::card(false, $this->trans('No Smaily account is saved for all shops, so each shop uses its own.'), 'off', $this->trans('Per shop'), $this->trans('Pick a shop in the shop selector to see its connection.'));
        }

        return self::card(false, $this->trans('Credentials missing or invalid'), 'failed', $this->trans('Not connected'), $note);
    }

    /**
     * The Campaign Intelligence card, Magento's four states (not connected, connected, unreachable,
     * not active) and this module's own: a rejected key, a module too old for the engine, and
     * "All shops" of separate stores, where each shop has its own connection.
     *
     * @param array<string, mixed>|null $engine
     *
     * @return array<string, mixed>
     */
    private function intelligenceCard(?array $engine): array
    {
        if ($engine !== null && $engine['per_shop']) {
            return self::card(false, $this->trans('Each shop has its own Campaign Intelligence connection.'), 'off', $this->trans('Per shop'), $this->trans('Pick a shop in the shop selector to see its connection.'));
        }
        if ($engine === null || !$engine['connected']) {
            return self::card(false, $this->trans('Not connected'), 'off', $this->trans('Off'));
        }
        switch ($engine['state']) {
            case IntelligencePanel::STATE_NOT_ACTIVE:
                return self::card(false, $this->trans('Account not active on the Smaily side'), 'failed', $this->trans('Not active'));
            case IntelligencePanel::STATE_KEY_REFUSED:
                return self::card(false, $this->trans('Campaign Intelligence rejected the stored API key.'), 'failed', $this->trans('Key rejected'));
            case IntelligencePanel::STATE_UPDATE_REQUIRED:
                return self::card(false, $this->trans('Campaign Intelligence needs a newer version of Smaily Connect.'), 'failed', $this->trans('Update needed'));
        }
        if ($engine['unreachable']) {
            return self::card(false, $this->trans('Currently unreachable'), 'pending', $this->trans('Unreachable'));
        }

        return self::card(
            true,
            $engine['name'] === '' ? $this->trans('Connected') : $this->trans('Connected (%name%)', ['%name%' => $engine['name']]),
            'active',
            $this->trans('On')
        );
    }

    /**
     * The Browse tracking card: on once the merchant confirmed that the store's cookie banner
     * covers the tracking (ARCHITECTURE section 9) and Campaign Intelligence takes the connection;
     * else off, with what it waits for.
     *
     * @param array<string, mixed>|null $engine
     *
     * @return array<string, mixed>
     */
    private function trackingCard(?array $engine): array
    {
        $off = fn (?string $note): array => self::card(false, $this->trans('Disabled'), 'off', $this->trans('Off'), $note);
        if ($engine === null) {
            return $off(null);
        }
        if ($engine['per_shop']) {
            return self::card(false, $this->trans('Each shop has its own setting.'), 'off', $this->trans('Per shop'));
        }
        if (!$engine['connected']) {
            return $off($this->trans('Needs a Campaign Intelligence connection.'));
        }
        if (!$engine['consent']) {
            return $off($this->trans('Confirm in Settings > Intelligence that your cookie banner covers Smaily tracking.'));
        }
        if (self::refused($engine)) {
            return $off($this->trans('Paused while Campaign Intelligence does not accept the connection of this store.'));
        }

        return self::card(true, $this->trans('Script live on storefront'), 'active', $this->trans('On'));
    }

    /**
     * Why Campaign Intelligence does not take the connection, in the Intelligence tab's words.
     */
    private function refusal(string $state): string
    {
        switch ($state) {
            case IntelligencePanel::STATE_KEY_REFUSED:
                return $this->trans('Campaign Intelligence rejected the stored API key.') . ' ' . $this->trans('The key was revoked or rotated. Disconnect and reconnect with a fresh setup link.');
            case IntelligencePanel::STATE_UPDATE_REQUIRED:
                return $this->trans('Campaign Intelligence needs a newer version of Smaily Connect.') . ' ' . $this->trans('Update the module, then press Check again.');
            default:
                return $this->trans('Campaign Intelligence has stopped accepting data from this store, so product, customer and order data is no longer being sent and recommendations will go stale. Waiting will not fix it — the account has to be made active again on the Smaily side. Your Smaily email sending is unaffected; everything queued here waits and is sent once the account is active.');
        }
    }

    /**
     * Whether the engine refuses the stored connection (Admin\Setup\IntelligencePanel::state()).
     *
     * @param array<string, mixed> $engine
     */
    private static function refused(array $engine): bool
    {
        return $engine['connected'] && $engine['state'] !== IntelligencePanel::STATE_CONNECTED;
    }

    /**
     * @param array<string, mixed> $facts
     *
     * @return array<string, mixed>
     */
    private function backgroundCard(array $facts, string $state): array
    {
        /** @var TickState $tick */
        $tick = $facts['tick'];
        $last = $tick->lastTickAt();
        if ($last === null) {
            $sub = $this->trans('Never run');
        } else {
            $time = date('Y-m-d H:i', $last);
            $sub = BackgroundTasks::source((string) $tick->lastSource()) === BackgroundTasks::SOURCE_CRON
                ? $this->trans('Last run %time% from the cron URL', ['%time%' => $time])
                : $this->trans('Last run %time% from shop visits', ['%time%' => $time]);
        }

        switch ($state) {
            case BackgroundTasks::RUNNING:
                $card = self::card(true, $sub, 'active', $this->trans('Running'));
                $hint = null;
                break;
            case BackgroundTasks::VISITORS:
                $card = self::card(false, $sub, 'pending', $this->trans('Visitors only'));
                $hint = $this->trans('Tasks run only when visitors come; add the cron line.');
                break;
            default:
                $card = self::card(false, $sub, 'failed', $this->trans('Not running'));
                $hint = $this->trans('Background tasks are not running; add the cron line.');
        }

        return $card + [
            'hint' => $hint,
            'cron_button' => $facts['can_configure'],
            // While the tasks need the cron line the card shows it at once; else on the button.
            'cron_open' => $facts['can_configure'] && $hint !== null,
            'ask_admin' => $hint !== null && !$facts['can_configure']
                ? $this->trans('Ask an administrator who may change the Smaily Connect settings to add the cron line.')
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $facts
     *
     * @return array<string, mixed>
     */
    private function tiles(array $facts): array
    {
        $syncs = $facts['contact_syncs'];
        $failed = $facts['failed_24h'];
        $catalog = $facts['engine']['catalog_items'] ?? null;

        return [
            'contact_syncs' => [
                'value' => $syncs === null ? '—' : $syncs['count'] . ($syncs['more'] ? '+' : ''),
                'caption' => $this->trans('delivered, last 30 days'),
            ],
            // As Magento, only with a Campaign Intelligence connection.
            'catalog_items' => $catalog === null ? null : [
                'value' => $catalog['count'] . ($catalog['more'] ? '+' : ''),
                'caption' => $this->trans('delivered, last 30 days'),
            ],
            'queued_today' => [
                'value' => $facts['queued_today'] === null ? '—' : (string) $facts['queued_today'],
                'caption' => $this->trans('events waiting to send'),
            ],
            'failed' => [
                'value' => $failed === null ? '—' : (string) $failed,
                'caption' => $failed > 0 ? null : $this->trans('last 24 hours'),
                'attention' => $failed > 0,
                'badge' => $failed > 0 ? $this->trans('Attention') : null,
                'link' => $failed > 0 ? ['label' => $this->trans('View failed deliveries'), 'route' => self::LOG, 'params' => self::LOG_FAILED] : null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function activityRow(array $row): array
    {
        return [
            'source' => $row['source'] === LogRows::INTELLIGENCE ? $this->trans('Campaign Intelligence') : $this->trans('Smaily'),
            'type' => (string) $row['type'],
            'entity' => $row['kind'] === 'job'
                ? EntityLabel::ofJob((string) $row['type'], (int) $row['total'], $this->translate)
                : (string) $row['entity'],
            'status' => [
                'variant' => self::PILLS[$row['kind']][$row['status']] ?? 'neutral',
                'label' => $this->statusLabel((string) $row['status']),
            ],
            'updated' => date('Y-m-d H:i:s', (int) $row['updated_at']),
        ];
    }

    private function statusLabel(string $status): string
    {
        switch ($status) {
            case 'pending':
                return $this->trans('Pending');
            case 'processing':
                return $this->trans('Sending');
            case 'sent':
                return $this->trans('Sent');
            case 'failed':
                return $this->trans('Failed');
            case 'skipped':
                return $this->trans('Skipped');
            case 'queued':
                return $this->trans('Queued');
            case 'running':
                return $this->trans('Running');
            case 'done':
                return $this->trans('Done');
            case 'cancelled':
                return $this->trans('Cancelled');
            default:
                return $status;
        }
    }

    /**
     * Whether the selected scope is "All shops" of separate stores, where no shop uses the account
     * saved for all shops: the store counts as connected when any shop is.
     *
     * @param array<string, mixed> $facts
     */
    private static function perShop(array $facts): bool
    {
        return $facts['all_shops'] && $facts['separate_stores'];
    }

    /**
     * @param array<string, mixed>|null $action
     *
     * @return array<string, mixed>
     */
    private static function verdictOf(string $state, string $tone, string $kicker, string $text, ?array $action): array
    {
        return ['state' => $state, 'tone' => $tone, 'kicker' => $kicker, 'text' => $text, 'action' => $action];
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function link(string $label, string $route, array $params = [], bool $danger = false): array
    {
        return ['label' => $label, 'route' => $route, 'params' => $params, 'anchor' => null, 'danger' => $danger];
    }

    /**
     * @return array{on: bool, sub: string, note: string|null, pill: array{variant: string, label: string}}
     */
    private static function card(bool $on, string $sub, string $variant, string $label, ?string $note = null): array
    {
        return ['on' => $on, 'sub' => $sub, 'note' => $note, 'pill' => ['variant' => $variant, 'label' => $label]];
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $id, array $parameters = []): string
    {
        return ($this->translate)($id, $parameters);
    }
}
