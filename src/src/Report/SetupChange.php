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
 * What a save of the setup or the Settings means for the installation report, as the Magento
 * connector's SetupChange: call before() ahead of the save and after() once it is written.
 *
 * A save that removes a Smaily account the installation held (a shop that returns to the shared
 * account, a language's account that mode `a` no longer uses, an account replaced by another, the
 * shops' own accounts when the store becomes one store) sends a `disconnected` event at once,
 * carrying the connections that remain. It is authenticated with the main account after the save,
 * or, when no account remains, with the main account captured before the save, the last
 * credentials the installation held for it. A save that went through also schedules a
 * `setup_saved` snapshot. Neither ever stops the save.
 */
final class SetupChange
{
    private ReportBuilder $builder;

    private EventReport $eventReport;

    private InstallationReport $installationReport;

    public function __construct(ReportBuilder $builder, EventReport $eventReport, InstallationReport $installationReport)
    {
        $this->builder = $builder;
        $this->eventReport = $eventReport;
        $this->installationReport = $installationReport;
    }

    /**
     * The installation's accounts before the save.
     *
     * @return array{accounts: list<string>, main: ?Credentials}
     */
    public function before(): array
    {
        try {
            return ['accounts' => $this->builder->accountKeys(), 'main' => $this->builder->mainCredentials()];
        } catch (\Throwable $error) {
            return ['accounts' => [], 'main' => null];
        }
    }

    /**
     * @param array{accounts: list<string>, main: ?Credentials} $before what before() answered
     * @param bool $saved whether the save went through
     */
    public function after(array $before, bool $saved = true): void
    {
        try {
            $removed = array_diff($before['accounts'], $this->builder->accountKeys());
        } catch (\Throwable $error) {
            $removed = [];
        }
        if ($removed !== []) {
            $this->eventReport->send(ReportBuilder::EVENT_DISCONNECTED, [], $before['main']);
        }
        if ($saved) {
            $this->installationReport->setupSaved();
        }
    }
}
