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

namespace PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyConfigMapper;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyFiles;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyReader;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;

/**
 * Registers the services and hook listeners of the legacy migration and its admin notices.
 *
 * `$services->get(Upgrade300::class)->runPending()` finishes a pending migration; it is safe to call
 * at any time and does nothing once the schema is current. The tick's job `migration` calls it on
 * every tick while the schema is not current, once per tick, and does nothing once it is.
 */
final class MigrationProvider implements ProviderInterface
{
    public function register(Services $services): void
    {
        $services->set(MigrationNotices::class, fn (): MigrationNotices => new MigrationNotices());
        $services->set(Upgrade300::class, fn (Services $services): Upgrade300 => new Upgrade300(
            new LegacyReader(),
            new LegacyConfigMapper(),
            $services->get(AccountStore::class),
            $services->get(MigrationNotices::class),
            new LegacyOverride(_PS_OVERRIDE_DIR_),
            new LegacyFiles(_PS_MODULE_DIR_ . 'smailyforprestashop')
        ));

        $services->listen(SchedulerProvider::JOBS, static fn (): Job => new Job('migration', 0, 10, static function () use ($services): void {
            if (!Schema::isCurrent()) {
                $services->get(Upgrade300::class)->runPending();
            }
        }, true));
    }
}
