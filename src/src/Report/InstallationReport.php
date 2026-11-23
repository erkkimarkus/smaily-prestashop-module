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

namespace PrestaShop\Module\SmailyForPrestaShop\Report;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;

/**
 * Decides when an installation report snapshot goes (contract installation-report-v1 "When an
 * installation sends a report"), as the Magento connector's InstallationReport. ReportBuilder
 * makes the body and ReportSender sends it.
 *
 * Only while a Smaily account is connected: with none, nothing is scheduled or sent, and what was
 * scheduled is forgotten.
 *
 * Snapshots go from the tick's job `installation_report` (every tick, at most one report a run),
 * never from a page request that somebody waits on:
 *   - `setup_saved` DEBOUNCE_SECONDS after a save of the setup; more saves in that window add no
 *     second report;
 *   - `daily` 24 hours after the last snapshot attempt; the first at a random moment within 24
 *     hours, so stores spread across the day.
 * A 429, a 5xx, a timeout or a network error is tried again after 60, 120 and 240 seconds plus
 * 0–30 seconds, or after a longer Retry-After; a Retry-After over an hour ends the try. Any other
 * answer is not tried again. A failed report is never queued: the next one carries the current
 * state.
 *
 * The schedule is the stored state (Config\ReportSettings::STATE): `setup_saved_at`, `daily_at`
 * and `retry` ({trigger, attempt, at}), so a retry waits across ticks. Nothing it does is shown to
 * the merchant; a report that is given up leaves one line in the shop's log with the HTTP status
 * and the service's error code, never a message, a body or a credential.
 */
final class InstallationReport
{
    public const DEBOUNCE_SECONDS = 300;

    public const DAY_SECONDS = 86400;

    /** The waits before the three retries. */
    public const RETRY_DELAYS = [60, 120, 240];

    public const RETRY_JITTER_SECONDS = 30;

    public const MAX_RETRY_AFTER_SECONDS = 3600;

    private ReportBuilder $builder;

    private ReportSender $sender;

    private ReportSettings $settings;

    /** @var callable(): int */
    private $now;

    /** @var callable(int, int): int */
    private $random;

    /** @var callable(string): void */
    private $log;

    /**
     * @param callable(): int $now the current Unix time
     * @param callable(int, int): int $random a random whole number between two bounds, both included
     * @param callable(string): void $log writes one line to the shop's log
     */
    public function __construct(ReportBuilder $builder, ReportSender $sender, ReportSettings $settings, callable $now, callable $random, callable $log)
    {
        $this->builder = $builder;
        $this->sender = $sender;
        $this->settings = $settings;
        $this->now = $now;
        $this->random = $random;
        $this->log = $log;
    }

    /**
     * A save of the setup: one `setup_saved` report DEBOUNCE_SECONDS from now, unless one already
     * waits. Never throws: a save never fails on its report.
     */
    public function setupSaved(): void
    {
        try {
            if ($this->builder->mainCredentials() === null) {
                return;
            }
            $state = $this->settings->state();
            if (($state['setup_saved_at'] ?? null) !== null) {
                return;
            }
            $state['setup_saved_at'] = ($this->now)() + self::DEBOUNCE_SECONDS;
            $this->settings->saveState($state);
        } catch (\Throwable $error) {
            ($this->log)(sprintf('the installation report was not scheduled (%s)', get_class($error)));
        }
    }

    /**
     * The tick's run: sends the snapshot that is due (a `setup_saved` one first, then a retry,
     * then the daily one) and sets what comes next.
     */
    public function run(): void
    {
        $state = $this->settings->state();
        $now = ($this->now)();
        $dailyArmed = ($state['daily_at'] ?? null) !== null;
        $retry = is_array($state['retry'] ?? null) ? $state['retry'] : null;
        $setupDue = ($state['setup_saved_at'] ?? null) !== null && (int) $state['setup_saved_at'] <= $now;
        $retryDue = $retry !== null && (int) ($retry['at'] ?? 0) <= $now;
        if ($dailyArmed && !$setupDue && !$retryDue && (int) $state['daily_at'] > $now) {
            // Nothing is due: most ticks end here, having read one stored value.
            return;
        }

        if ($this->builder->mainCredentials() === null) {
            // Nothing is reported without a connected account; a later connection starts the
            // schedule anew.
            if ($state !== []) {
                $this->settings->saveState([]);
            }

            return;
        }

        if (!$dailyArmed) {
            $state['daily_at'] = $now + ($this->random)(0, self::DAY_SECONDS);
        }
        $attempt = 0;
        if ($setupDue) {
            $trigger = ReportBuilder::TRIGGER_SETUP_SAVED;
            $state['setup_saved_at'] = null;
        } elseif ($retryDue) {
            $trigger = (string) ($retry['trigger'] ?? ReportBuilder::TRIGGER_DAILY);
            $attempt = (int) ($retry['attempt'] ?? 1);
        } elseif ((int) $state['daily_at'] <= $now) {
            $trigger = ReportBuilder::TRIGGER_DAILY;
        } else {
            $this->settings->saveState($state);

            return;
        }

        // The attempt is recorded before it is made, so a run that dies inside it never sends the
        // same report on every tick. The daily report is due 24 hours after this attempt, except
        // the first, armed in this run at its random moment.
        $state['retry'] = null;
        if ($dailyArmed || $trigger === ReportBuilder::TRIGGER_DAILY) {
            $state['daily_at'] = $now + self::DAY_SECONDS;
        }
        $this->settings->saveState($state);

        $report = $this->builder->build(ReportBuilder::EVENT_SNAPSHOT, $trigger);
        if ($report === null) {
            return;
        }
        $result = $this->sender->send($report);
        if ($result->isAccepted()) {
            return;
        }

        if ($result->mayRetry() && $attempt < count(self::RETRY_DELAYS) && $result->retryAfter() <= self::MAX_RETRY_AFTER_SECONDS) {
            $wait = max(self::RETRY_DELAYS[$attempt] + ($this->random)(0, self::RETRY_JITTER_SECONDS), $result->retryAfter());
            $state['retry'] = ['trigger' => $trigger, 'attempt' => $attempt + 1, 'at' => $now + $wait];
            $this->settings->saveState($state);

            return;
        }

        ($this->log)(sprintf('the installation report (%s) was not taken in: %s', $trigger, $result->summary()));
    }
}
