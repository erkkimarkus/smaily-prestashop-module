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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\ContextTranslation;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The base of the admin pages' JSON endpoints. A route of config/routes/ names `execute` as its
 * controller method and one of the four page tabs as its `_legacy_controller`.
 *
 * Before handle() runs, the request must carry the employee's right PERMISSION on that tab, the
 * page's CSRF token in the header X-Smaily-Token, and in X-Smaily-Scope the shop selection that the
 * page was drawn for (common.js sends both). PrestaShop's own `_token` of the admin URL is checked
 * by PrestaShop before the controller runs.
 *
 * PrestaShop keeps the selected shop in the employee's one cookie, so another browser tab, or a
 * late answer of another page, changes it under a page that is open. A request whose page was
 * drawn for another selection than the one the request runs in is refused: it would read or save
 * another shop's values than the page shows. The header is only compared; the shop that a request
 * reads and writes is always the one of the request itself (shopId()).
 *
 * Every answer is one envelope: `{"ok": true, "data": {...}}`, or `{"ok": false, "error": {"code":
 * "...", "message": "..."}}` with an HTTP status of 4xx or 5xx. An error's message never carries an
 * exception message, which can hold a secret or an address.
 *
 * The shop is the one of PrestaShop's shop selector in the header: shopId() is its id, or 0 in the
 * "All shops" and group contexts, which write the global values.
 */
abstract class AbstractJsonAction extends FrameworkBundleAdminController
{
    use ContextTranslation;

    /** The right on the route's tab: `read` for an endpoint that changes nothing. */
    protected const PERMISSION = 'update';

    public function execute(Request $request): JsonResponse
    {
        if (!$this->isGranted(static::PERMISSION, (string) $request->attributes->get('_legacy_controller'))) {
            return $this->error('forbidden', $this->trans('You do not have permission to do this.', BaseController::DOMAIN), 403);
        }
        if (!$this->isCsrfTokenValid(BaseController::API_TOKEN_ID, (string) $request->headers->get('X-Smaily-Token'))) {
            return $this->error('invalid_token', $this->trans('The page has expired. Reload it and try again.', BaseController::DOMAIN), 403);
        }
        if ((string) $request->headers->get('X-Smaily-Scope') !== self::pageScope()) {
            return $this->error('scope_changed', $this->trans('The shop selected at the top of the page has changed since this page was opened. Reload the page and try again.', BaseController::DOMAIN), 409);
        }

        try {
            $answer = $this->handle($request, self::shopId());
        } catch (\Throwable $error) {
            Log::failure('the admin endpoint ' . (string) $request->attributes->get('_route'), $error);

            return $this->error('internal_error', $this->trans('Something went wrong. Try again; if it keeps failing, see the PrestaShop logs.', BaseController::DOMAIN), 500);
        }

        return $answer instanceof JsonResponse ? $answer : $this->ok($answer);
    }

    /**
     * @return array<string, mixed>|JsonResponse the data of a successful answer, or an error()
     */
    abstract protected function handle(Request $request, int $idShop);

    /**
     * @param array<string, mixed> $data
     */
    protected function ok(array $data = []): JsonResponse
    {
        return self::noStore(new JsonResponse(['ok' => true, 'data' => (object) $data]));
    }

    /**
     * @param list<array{field: string, message: string}>|null $fields the fields at fault, which
     *                                                                 the page marks; null answers
     *                                                                 no `fields`
     */
    protected function error(string $code, string $message, int $status = 400, ?array $fields = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message] + ($fields === null ? [] : ['fields' => $fields]);

        return self::noStore(new JsonResponse(['ok' => false, 'error' => $error], $status));
    }

    /**
     * The JSON body of the request.
     *
     * @return array<string, mixed>
     */
    protected function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) ? $body : [];
    }

    /**
     * The shop that PrestaShop's shop selector set, or 0 for "All shops", a shop group, and a store
     * without multishop (its one shop reads the global values).
     */
    public static function shopId(): int
    {
        if (!\Shop::isFeatureActive() || \Shop::getContext() !== \Shop::CONTEXT_SHOP) {
            return 0;
        }

        return (int) \Shop::getContextShopID();
    }

    /**
     * The selection of PrestaShop's shop selector, as a page carries it and its requests name it
     * again: `all`, a shop group or a shop. A store without multishop is always `all`.
     */
    public static function pageScope(): string
    {
        if (!\Shop::isFeatureActive()) {
            return 'all';
        }
        switch (\Shop::getContext()) {
            case \Shop::CONTEXT_SHOP:
                return 'shop-' . (int) \Shop::getContextShopID();
            case \Shop::CONTEXT_GROUP:
                return 'group-' . (int) \Shop::getContextShopGroupID();
            default:
                return 'all';
        }
    }

    private static function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
