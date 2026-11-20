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

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;

/**
 * Reads the facts that DashboardView words, from stored state only: the configuration that the
 * request loaded, the account table, the event queue and the job table. It writes nothing and calls
 * no outside service. While the upgrade is not finished it reads no table of the module, which may
 * not exist yet.
 */
final class DashboardFacts
{
    /** Recent activity shows this many rows. */
    public const ACTIVITY_ROWS = 10;

    private const DAY = 86400;

    private DashboardQueries $queries;

    private DashboardSettings $settings;

    private StoreLayout $layout;

    private AccountResolver $resolver;

    private AccountStore $accounts;

    private TickSettings $ticks;

    public function __construct(
        DashboardQueries $queries,
        DashboardSettings $settings,
        StoreLayout $layout,
        AccountResolver $resolver,
        AccountStore $accounts,
        TickSettings $ticks
    ) {
        $this->queries = $queries;
        $this->settings = $settings;
        $this->layout = $layout;
        $this->resolver = $resolver;
        $this->accounts = $accounts;
        $this->ticks = $ticks;
    }

    /**
     * @param string|null $upgrade the migration's status while it is not finished
     * @param int $idShop the shop of PrestaShop's shop selector, 0 for "All shops" or a
     *                    store without multishop
     *
     * @return array<string, mixed> the facts that DashboardView::build() takes
     */
    public function collect(?string $upgrade, int $idShop, bool $multishop, bool $canConfigure, bool $canOpenSettings, int $now): array
    {
        $facts = [
            'upgrade' => $upgrade,
            'all_shops' => $multishop && $idShop === 0,
            'separate_stores' => $this->layout->separateStores(),
            'account' => null,
            'any_account' => false,
            'contacts_enabled' => $this->settings->contactsEnabled($idShop),
            'contacts_confirmed' => $this->settings->contactsConfirmed($idShop),
            'tick' => $this->ticks->state(),
            'now' => $now,
            'contact_syncs' => null,
            'queued_today' => null,
            'failed_24h' => null,
            'activity' => [],
            'can_configure' => $canConfigure,
            'can_open_settings' => $canOpenSettings,
        ];
        if ($upgrade !== null) {
            return $facts;
        }

        $account = $this->resolver->for($idShop, '');

        return [
            'account' => $account === null ? null : ['subdomain' => $account->subdomain(), 'connected' => $account->hasCredentials()],
            'any_account' => ($account !== null && $account->hasCredentials()) || $this->anyAccount(),
            'contact_syncs' => $this->queries->contactSyncs($now),
            'queued_today' => $this->queries->queuedToday($now),
            'failed_24h' => $this->queries->failedSince($now - self::DAY),
            'activity' => $this->activity(),
        ] + $facts;
    }

    private function anyAccount(): bool
    {
        foreach ($this->queries->accountScopes() as $scope) {
            $account = $this->accounts->find((int) $scope['id_shop'], (string) $scope['language']);
            if ($account !== null && $account->hasCredentials()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The newest queue rows and import jobs together, newest change first.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(): array
    {
        $rows = [];
        foreach ($this->queries->latestRows(self::ACTIVITY_ROWS) as $row) {
            $rows[] = [
                'kind' => 'queue',
                'type' => (string) $row['event_type'],
                'entity' => EntityLabel::forDisplay($row['entity_id']),
                'status' => (string) $row['status'],
                'updated_at' => self::unixTime((string) $row['updated_at']),
            ];
        }
        foreach ($this->queries->latestJobs(self::ACTIVITY_ROWS) as $job) {
            $counts = json_decode((string) $job['counts'], true);
            $rows[] = [
                'kind' => 'job',
                'type' => (string) $job['type'],
                'total' => (int) ($counts['total'] ?? 0),
                'status' => (string) $job['status'],
                'updated_at' => self::unixTime((string) $job['updated_at']),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['updated_at'] <=> $a['updated_at']);

        return array_slice($rows, 0, self::ACTIVITY_ROWS);
    }

    /**
     * A stored DATETIME, which is UTC, as a Unix time.
     */
    private static function unixTime(string $datetime): int
    {
        return (int) strtotime($datetime . ' UTC');
    }
}
