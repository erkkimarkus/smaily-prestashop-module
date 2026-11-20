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

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;

/**
 * Runs the background jobs that are due, whichever door started it: a cron URL or a visitor's
 * request after its response.
 *
 * One tick runs at a time: a tick that cannot take the lock returns at once. The tick runs the jobs
 * in order; each job gets its own budget, cut to what is left of the tick's, and a job that starts
 * no more fits into the tick waits for the next one. A job that throws fails alone. While the
 * stored schema version differs from the code's, only the jobs made to run before the upgrade
 * finishes run.
 *
 * The tick state records, before each job starts, that the job ran (so a job that kills the request
 * waits for its interval like any other), and when the tick ran and from which source.
 */
final class Tick
{
    public const SOURCE_TRAFFIC = 'traffic';

    private Lock $lock;

    private TickSettings $settings;

    /** @var list<Job> */
    private array $jobs;

    /** @var callable(): bool */
    private $schemaIsCurrent;

    /** @var callable(): float */
    private $clock;

    /** @var callable(string, \Throwable): void */
    private $reportFailure;

    /**
     * @param list<Job> $jobs in the order they run
     * @param callable(): bool $schemaIsCurrent
     * @param callable(): float $clock the current Unix time with fractions
     * @param callable(string, \Throwable): void $reportFailure called with a job's name and its error
     */
    public function __construct(Lock $lock, TickSettings $settings, array $jobs, callable $schemaIsCurrent, callable $clock, callable $reportFailure)
    {
        $this->lock = $lock;
        $this->settings = $settings;
        $this->jobs = $jobs;
        $this->schemaIsCurrent = $schemaIsCurrent;
        $this->clock = $clock;
        $this->reportFailure = $reportFailure;
    }

    /**
     * @param string $source what started the tick, as the tick state records it
     * @param int $budget seconds the whole tick may take
     * @param int $staleAfter when above 0, the tick does nothing unless the last tick is at least
     *                        this many seconds old
     */
    public function run(string $source, int $budget, int $staleAfter = 0): TickReport
    {
        $start = ($this->clock)();
        $end = $start + $budget;
        if (!$this->lock->acquire()) {
            return new TickReport(TickReport::BUSY);
        }

        try {
            $state = $this->settings->storedState();
            $lastTick = $state->lastTickAt();
            if ($staleAfter > 0 && $lastTick !== null && (int) $start - $lastTick < $staleAfter) {
                return new TickReport(TickReport::FRESH);
            }

            $state = $state->withTick($source, (int) $start);
            $this->settings->saveState($state);

            $current = ($this->schemaIsCurrent)();
            $ran = [];
            foreach ($this->jobs as $job) {
                if (!$current && !$job->runsBeforeUpgrade()) {
                    continue;
                }
                $jobStart = ($this->clock)();
                if ($jobStart >= $end) {
                    break;
                }
                if (!$job->isDue($state, (int) $jobStart)) {
                    continue;
                }

                $state = $state->withJobRun($job->name(), (int) $jobStart);
                $this->settings->saveState($state);
                try {
                    $job->run(new Deadline(min($jobStart + $job->budget(), $end), $this->clock));
                } catch (\Throwable $error) {
                    ($this->reportFailure)($job->name(), $error);
                }
                $ran[] = $job->name();
            }

            return new TickReport($current ? TickReport::RAN : TickReport::INERT, $ran);
        } finally {
            $this->lock->release();
        }
    }

    /**
     * The message names the error's class and place only: an exception message can carry a
     * secret or an address.
     */
    public static function logFailure(string $job, \Throwable $error): void
    {
        \PrestaShopLogger::addLog(
            sprintf(
                'Smaily Connect: the background job %s failed with %s at %s:%d',
                $job,
                get_class($error),
                basename($error->getFile()),
                $error->getLine()
            ),
            3
        );
    }
}
