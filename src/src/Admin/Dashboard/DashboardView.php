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
 * BackgroundTasks::STOPPED_SECONDS; failed rows in the last 24 hours; else all is well.
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
 * - contact_syncs: `count` and `more` (the count stopped at its bound), queued_today, failed_24h:
 *   null while the upgrade keeps the queue unread;
 * - activity: the latest rows, newest first: `kind` (`queue` or `job`), `type`, `status`,
 *   `updated_at` (Unix time), and `entity` (already an EntityLabel) or `total` (a job's contacts);
 * - can_configure: the employee may change the settings (the cron line is theirs to see);
 *   can_open_settings: the employee may open Settings (the invitation is theirs to act on);
 * - cron_line: the crontab line with the tick URL's token, or null while no token is stored.
 */
final class DashboardView
{
    public const VERDICT_UPGRADE = 'upgrade';

    public const VERDICT_INCOMPLETE = 'incomplete';

    public const VERDICT_DISCONNECTED = 'disconnected';

    public const VERDICT_STOPPED = 'stopped';

    public const VERDICT_DEGRADED = 'degraded';

    public const VERDICT_OK = 'ok';

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

        return [
            'invitation' => $facts['upgrade'] === null && !$facts['any_account'] && $facts['can_open_settings'],
            'verdict' => $this->verdict($verdict, (int) $failed),
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
                'intelligence' => self::card(false, $this->trans('Not connected'), 'off', $this->trans('Off')),
                'tracking' => self::card(false, $this->trans('Disabled'), 'off', $this->trans('Off')),
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
        if ($facts['failed_24h'] > 0) {
            return self::VERDICT_DEGRADED;
        }

        return self::VERDICT_OK;
    }

    /**
     * @return array<string, mixed>
     */
    private function verdict(string $state, int $failed): array
    {
        switch ($state) {
            case self::VERDICT_UPGRADE:
                return self::verdictOf($state, 'warning', $this->trans('Needs attention'), $this->trans('Smaily Connect stays paused until the upgrade finishes.'), null);
            case self::VERDICT_INCOMPLETE:
                return self::verdictOf($state, 'warning', $this->trans('Setup incomplete'), $this->trans('Setup is not finished yet — complete the initial setup to start syncing.'), $this->link($this->trans('Resume setup'), 'smailyforprestashop_setup'));
            case self::VERDICT_DISCONNECTED:
                return self::verdictOf($state, 'danger', $this->trans('Not connected'), $this->trans('The Smaily credentials are missing or Smaily has not accepted them — check them in Settings > Connection and test the connection.'), $this->link($this->trans('Open Connection settings'), 'smailyforprestashop_settings', ['tab' => 'connection']));
            case self::VERDICT_STOPPED:
                return self::verdictOf($state, 'warning', $this->trans('Needs attention'), $this->trans('Background tasks are not running — nothing goes to Smaily until they run again.'), ['label' => $this->trans('Add the cron line'), 'route' => null, 'params' => [], 'anchor' => 'smaily-background', 'danger' => false]);
            case self::VERDICT_DEGRADED:
                $text = $failed === 1
                    ? $this->trans('Smaily Connect is running, but %count% delivery failed in the last 24 hours.', ['%count%' => $failed])
                    : $this->trans('Smaily Connect is running, but %count% deliveries failed in the last 24 hours.', ['%count%' => $failed]);

                return self::verdictOf($state, 'warning', $this->trans('Needs attention'), $text, $this->link($this->trans('Review failures'), self::LOG, self::LOG_FAILED, true));
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
        $needsCron = $hint !== null;

        return $card + [
            'hint' => $hint,
            'cron' => $needsCron && $facts['can_configure'] ? $facts['cron_line'] : null,
            'cron_missing' => $needsCron && $facts['can_configure'] && $facts['cron_line'] === null,
            'ask_admin' => $needsCron && !$facts['can_configure']
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

        return [
            'contact_syncs' => [
                'value' => $syncs === null ? '—' : $syncs['count'] . ($syncs['more'] ? '+' : ''),
                'caption' => $this->trans('delivered, last 30 days'),
            ],
            // Magento shows Catalog items only with a Campaign Intelligence connection, which this
            // module does not make yet.
            'catalog_items' => null,
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
        if ($row['kind'] === 'job') {
            $total = (int) $row['total'];
            $entity = $total === 1
                ? $this->trans('%count% contact', ['%count%' => $total])
                : $this->trans('%count% contacts', ['%count%' => $total]);
        } else {
            $entity = (string) $row['entity'];
        }

        return [
            'source' => $this->trans('Smaily'),
            'type' => (string) $row['type'],
            'entity' => $entity,
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
