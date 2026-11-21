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
use PrestaShop\Module\SmailyForPrestaShop\Privacy\PrivacyRequest;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Symfony\Component\HttpFoundation\Request;

/**
 * "Erase" of the Log's "Privacy request" form: body `{"email": "<address>"}`, answer
 * `{"message": …}` with what was erased (Privacy\PrivacyRequest). The page asks before it calls.
 *
 * The erasure covers the module's own data in every shop and queues the deletion at each Campaign
 * Intelligence connection. It leaves PrestaShop's customer and orders and the Smaily contact: the
 * merchant handles those in PrestaShop and in Smaily. As the export, the endpoint answers 404
 * where PrestaShop's GDPR module is enabled.
 */
final class PrivacyErase extends AbstractJsonAction
{
    protected function handle(Request $request, int $idShop)
    {
        $email = PrivacyExport::email($this->body($request));
        if (!PrivacyExport::formAvailable()) {
            return $this->error('not_available', $this->trans('Privacy requests are handled by the store\'s GDPR module.', BaseController::DOMAIN), 404);
        }
        if ($email === null) {
            return $this->error('invalid_email', $this->trans('Enter a valid email address.', BaseController::DOMAIN), 400);
        }

        $erased = Services::instance()->get(PrivacyRequest::class)->erase($email, 0, (int) \Context::getContext()->shop->id);

        return [
            'erased' => $erased,
            'message' => $this->trans(
                'The data of this address was erased. Queued rows deleted: %deleted%; Log rows cleared: %cleared%; cart reminders closed: %carts%; discount codes removed: %codes%. The deletion in Campaign Intelligence is queued; connections: %connections%.',
                BaseController::DOMAIN,
                [
                    '%deleted%' => $erased['queue_removed'],
                    '%cleared%' => $erased['queue_cleared'],
                    '%carts%' => $erased['carts'],
                    '%codes%' => $erased['codes_removed'],
                    '%connections%' => $erased['engine_queued'],
                ]
            ),
        ];
    }
}
