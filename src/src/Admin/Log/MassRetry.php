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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Log;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogFilter;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogRows;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;
use PrestaShop\Module\SmailyForPrestaShop\Log\Retry;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Log's mass "Retry" (Log\Retry): body `{"ids": ["<log id>", …]}` for the ticked rows, or
 * `{"all": true, "filter": {…}}` for every row that the list's filter selects. The answer is
 * `{"message": …, "retried": n, "skipped": n}`: the failed rows returned to pending, and those
 * left alone because sending again would not be safe.
 */
final class MassRetry extends AbstractJsonAction
{
    protected function handle(Request $request, int $idShop)
    {
        $body = $this->body($request);
        if (($body['all'] ?? false) === true) {
            $filter = new LogFilter(is_array($body['filter'] ?? null) ? $body['filter'] : []);
            $ids = null;
        } else {
            $filter = new LogFilter();
            $ids = [];
            foreach (is_array($body['ids'] ?? null) ? $body['ids'] : [] as $logId) {
                if (is_string($logId) && preg_match('/^' . LogRows::SMAILY . '-([1-9][0-9]{0,18})$/', $logId, $matches) === 1) {
                    $ids[] = (int) $matches[1];
                }
            }
        }

        [$retried, $skipped] = (new Retry(LogController::rows(), new ResendGuard(), Services::instance()->get(QueueProvider::EVENT_QUEUE)))->retry($filter, $ids);

        return [
            'message' => $skipped > 0
                ? $this->trans('%retried% event(s) queued for retry, %skipped% skipped because sending again would not be safe.', BaseController::DOMAIN, ['%retried%' => $retried, '%skipped%' => $skipped])
                : $this->trans('%retried% event(s) queued for retry.', BaseController::DOMAIN, ['%retried%' => $retried]),
            'retried' => $retried,
            'skipped' => $skipped,
        ];
    }
}
