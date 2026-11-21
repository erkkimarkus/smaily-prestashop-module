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

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Discount\IssuedCodes;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Door;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\QueueJanitor;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TrafficTick;

/**
 * Registers the services and hook listeners of the tick and its jobs.
 *
 * The jobs are listeners of JOBS, a name that no PrestaShop hook has: each returns one Job, and the
 * tick runs them in the order the providers registered them. An area adds a job in its own
 * provider: `$services->listen(SchedulerProvider::JOBS, fn (): Job => new Job(...));`
 */
final class SchedulerProvider implements ProviderInterface
{
    public const JOBS = 'smailyConnectTickJobs';

    /**
     * The page hooks on which the fallback tick decides whether to run. Its listeners run also
     * before the upgrade has finished, so that a page request can finish a pending migration.
     */
    public const TRAFFIC_HOOKS = ['actionFrontControllerSetMedia', 'actionAdminControllerSetMedia'];

    public function register(Services $services): void
    {
        $services->set(TickSettings::class, fn (): TickSettings => new TickSettings());
        $services->set(Tick::class, fn (Services $services): Tick => new Tick(
            new Lock(),
            $services->get(TickSettings::class),
            array_map(static fn (callable $job): Job => $job([]), $services->listeners(self::JOBS)),
            [Schema::class, 'isCurrent'],
            static fn (): float => microtime(true),
            [Tick::class, 'logFailure']
        ));
        $services->set(Door::class, fn (Services $services): Door => new Door(
            $services->get(TickSettings::class),
            static fn (): Tick => $services->get(Tick::class)
        ));
        $services->set(TrafficTick::class, fn (Services $services): TrafficTick => new TrafficTick(
            $services->get(TickSettings::class),
            static fn (): Tick => $services->get(Tick::class),
            'time',
            TrafficTick::finishFunction(),
            'register_shutdown_function'
        ));
        $services->set(QueueJanitor::class, fn (Services $services): QueueJanitor => new QueueJanitor(
            $services->get(QueueProvider::EVENT_QUEUE),
            new JobStore(),
            $services->get(IssuedCodes::class)
        ));

        foreach (self::TRAFFIC_HOOKS as $hook) {
            $services->listen($hook, static function () use ($services): void {
                $services->get(TrafficTick::class)->onRequest();
            }, true);
        }

        $services->listen(self::JOBS, static fn (): Job => new Job('queue_flush', 60, 20, static function (Deadline $deadline) use ($services): void {
            $flusher = $services->get(QueueProvider::FLUSHER);
            do {
                $claimed = $flusher->flush();
            } while ($claimed > 0 && !$deadline->passed());
        }));
        $services->listen(self::JOBS, static fn (): Job => new Job('queue_janitor', 86400, 10, static function (Deadline $deadline) use ($services): void {
            $services->get(QueueJanitor::class)->run($deadline);
        }));
    }
}
