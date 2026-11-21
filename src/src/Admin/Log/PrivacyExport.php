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
 * "Export" of the Log's "Privacy request" form: body `{"email": "<address>"}`, answer
 * `{"filename": …, "export": {…}}`, which the page saves as a file. The export holds what the
 * module's tables and each Campaign Intelligence connection hold of that one address
 * (Privacy\PrivacyRequest), and nothing of anybody else.
 *
 * The form is for a store without PrestaShop's GDPR module, whose own screens run the same export
 * with the customer's other data: where that module is enabled the endpoint answers 404. The
 * address travels in the request body, never in a page address.
 */
final class PrivacyExport extends AbstractJsonAction
{
    /** PrestaShop's GDPR module, which runs the export and the erasure where it is enabled. */
    public const GDPR_MODULE = 'psgdpr';

    protected function handle(Request $request, int $idShop)
    {
        $email = self::email($this->body($request));
        if (!self::formAvailable()) {
            return $this->error('not_available', $this->trans('Privacy requests are handled by the store\'s GDPR module.', BaseController::DOMAIN), 404);
        }
        if ($email === null) {
            return $this->error('invalid_email', $this->trans('Enter a valid email address.', BaseController::DOMAIN), 400);
        }

        return [
            'filename' => 'smaily-connect-privacy-export-' . gmdate('Ymd-His') . '.json',
            'export' => ['exported_at' => gmdate('Y-m-d\TH:i:s\Z'), 'email' => $email]
                + Services::instance()->get(PrivacyRequest::class)->export($email),
            'message' => $this->trans('The export was saved as a file.', BaseController::DOMAIN),
        ];
    }

    /**
     * Whether the Log shows the form: only while PrestaShop's GDPR module is not installed and
     * enabled in the shop of the request.
     */
    public static function formAvailable(): bool
    {
        return !\Module::isEnabled(self::GDPR_MODULE);
    }

    /**
     * The address of a request body, or null when it holds no valid one.
     *
     * @param array<string, mixed> $body
     */
    public static function email(array $body): ?string
    {
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';

        return $email !== '' && \Validate::isEmail($email) ? $email : null;
    }
}
