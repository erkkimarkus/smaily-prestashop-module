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

/**
 * Sends a `disconnected` or `deactivated` installation report now, once, without a retry (contract
 * installation-report-v1 "Events"), as the Magento connector's EventReport: afterwards the
 * installation no longer holds the credentials, or no longer runs. Whatever happens, the caller
 * goes on with the disconnect or the removal: it never throws. A report that the service did not
 * take in leaves one line in the shop's log; an installation without a connected Smaily account
 * sends nothing and logs nothing.
 *
 * Not final: the unit tests stand in for it.
 */
class EventReport
{
    private ReportBuilder $builder;

    private ReportSender $sender;

    /** @var callable(string): void */
    private $log;

    /**
     * @param callable(string): void $log writes one line to the shop's log
     */
    public function __construct(ReportBuilder $builder, ReportSender $sender, callable $log)
    {
        $this->builder = $builder;
        $this->sender = $sender;
        $this->log = $log;
    }

    /**
     * @param string $event ReportBuilder::EVENT_DISCONNECTED or EVENT_DEACTIVATED
     * @param list<int> $removedConnections the ids of the Campaign Intelligence connections that a
     *                                      `disconnected` event removes
     * @param Credentials|null $previousMain the main account before a save removed accounts
     */
    public function send(string $event, array $removedConnections = [], ?Credentials $previousMain = null): void
    {
        try {
            $report = $this->builder->build($event, '', $removedConnections, $previousMain);
            if ($report === null) {
                return;
            }
            $result = $this->sender->send($report);
            if (!$result->isAccepted()) {
                ($this->log)(sprintf('the installation report (%s) was not taken in: %s', $event, $result->summary()));
            }
        } catch (\Throwable $error) {
            // Best effort: the disconnect or the removal goes on.
            return;
        }
    }
}
