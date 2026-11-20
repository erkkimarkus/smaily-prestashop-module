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

use PrestaShop\Module\SmailyForPrestaShop\Config\UpdateSettings;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Update\LatestRelease;
use PrestaShop\Module\SmailyForPrestaShop\Update\UpdateNotice;

/**
 * Registers the daily check for a newer release and the admin's notice of it.
 *
 * The job asks GitHub once a day. A failed check (no answer, an HTTP error, an unusable release)
 * throws to the tick, which logs one line with the error class; the stored state stays, and the
 * next check comes a day later, as the tick records a job's run before the job starts.
 */
final class UpdateProvider implements ProviderInterface
{
    public const JOB = 'update_check';

    public function register(Services $services): void
    {
        $services->set(UpdateSettings::class, fn (): UpdateSettings => new UpdateSettings());
        $services->set(UpdateNotice::class, fn (Services $services): UpdateNotice => new UpdateNotice(
            $services->get(UpdateSettings::class),
            self::moduleVersion()
        ));
        $services->set(LatestRelease::class, fn (Services $services): LatestRelease => new LatestRelease(
            $services->get(Transport::class),
            self::moduleVersion()
        ));

        $services->listen(SchedulerProvider::JOBS, static fn (): Job => new Job(self::JOB, 86400, 10, static function () use ($services): void {
            $services->get(UpdateNotice::class)->record($services->get(LatestRelease::class)->fetch(), time());
        }));
    }

    /**
     * The version of the module code that runs; '' when the module cannot be loaded.
     */
    public static function moduleVersion(): string
    {
        $module = \Module::getInstanceByName('smailyforprestashop');

        return $module instanceof \Module ? (string) $module->version : '';
    }
}
