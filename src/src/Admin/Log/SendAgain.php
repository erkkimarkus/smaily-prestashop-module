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
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Log\Resend;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Symfony\Component\HttpFoundation\Request;

/**
 * "Send again" of one failed row of the Log: body `{"id": "<log id>"}`, answer `{"message": …}`.
 * A new pending row carries the same event (Log\Resend); the failed row stays as history.
 *
 * The resend guard has the last word, also over a stale page: a row it refuses answers its
 * sentence with HTTP 409. Each request that the guard lets through queues a row, as in Magento: a
 * second "Send again" of a row whose first copy is not delivered yet queues a second copy.
 */
final class SendAgain extends AbstractJsonAction
{
    protected function handle(Request $request, int $idShop)
    {
        $row = LogController::rows()->find((string) ($this->body($request)['id'] ?? ''));
        if ($row === null) {
            return $this->error('not_found', $this->trans('This log row no longer exists — it has likely been pruned by retention.', BaseController::DOMAIN), 404);
        }
        $reason = (new ResendGuard())->refusalOf($row);
        if ($reason !== null) {
            return $this->error('refused', $this->trans(ResendGuard::message($reason), BaseController::DOMAIN), 409);
        }
        $employee = \Context::getContext()->employee;
        try {
            $services = Services::instance();
            (new Resend($services->get(QueueProvider::EVENT_QUEUE), $services->get(CartTracker::class), $services->get(IngestQueue::class)))->resend($row, $employee === null ? '' : trim($employee->firstname . ' ' . $employee->lastname));
        } catch (\RuntimeException $error) {
            Log::failure('the Log\'s Send again', $error);

            return $this->error('not_queued', $this->trans('The event could not be queued again — please try again.', BaseController::DOMAIN), 500);
        }

        return ['message' => $this->trans('Event queued to be sent again.', BaseController::DOMAIN)];
    }
}
