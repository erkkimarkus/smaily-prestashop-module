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

namespace PrestaShop\Module\SmailyForPrestaShop\Scheduler;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * One piece of background work that the tick runs when it is due: its name (the key of its last run
 * in the tick state), how often it runs, how long one run may take, and the work itself.
 *
 * An area registers its jobs in its own provider, under SchedulerProvider::JOBS:
 * `$services->listen(SchedulerProvider::JOBS, fn (): Job => new Job('name', 60, 20, function (Deadline $deadline) use ($services): void { ... }));`
 * The tick runs the jobs in the order the providers registered them.
 */
final class Job
{
    /**
     * A job counts as due this many seconds before its interval has passed, so that a cron line
     * that fires a little early does not skip a whole interval.
     */
    public const GRACE_SECONDS = 5;

    private string $name;

    private int $interval;

    private int $budget;

    /** @var callable(Deadline): void */
    private $work;

    private bool $runsBeforeUpgrade;

    /**
     * @param int $interval seconds between two runs
     * @param int $budget seconds one run may take
     * @param callable(Deadline): void $work
     * @param bool $runsBeforeUpgrade whether the job also runs while the stored schema version
     *                                differs from the code's (the upgrade has not finished); no
     *                                other job does then
     */
    public function __construct(string $name, int $interval, int $budget, callable $work, bool $runsBeforeUpgrade = false)
    {
        $this->name = $name;
        $this->interval = $interval;
        $this->budget = $budget;
        $this->work = $work;
        $this->runsBeforeUpgrade = $runsBeforeUpgrade;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function budget(): int
    {
        return $this->budget;
    }

    public function runsBeforeUpgrade(): bool
    {
        return $this->runsBeforeUpgrade;
    }

    public function isDue(TickState $state, int $now): bool
    {
        $ranAt = $state->jobRanAt($this->name);

        return $ranAt === null || $now - $ranAt >= $this->interval - self::GRACE_SECONDS;
    }

    public function run(Deadline $deadline): void
    {
        ($this->work)($deadline);
    }
}
