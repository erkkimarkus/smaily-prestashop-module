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

namespace PrestaShop\Module\SmailyForPrestaShop\Privacy;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The listeners of the two hooks of PrestaShop's GDPR module (psgdpr): `actionExportGDPRData` and
 * `actionDeleteGDPRCustomer`. psgdpr hands each the customer as an array: a customer account with
 * `id` and `email`, or a request for an address alone with `email`. A request for a phone number
 * names nobody the module knows.
 *
 * - The export answers a JSON list of rows, each a flat object of column name to value, as psgdpr
 *   prints them in its screen, its PDF and its CSV file. The words are the employee's or the
 *   customer's language.
 * - The erasure answers `true` as JSON. psgdpr goes on to delete the customer account itself.
 */
final class GdprHooks
{
    private PrivacyRequest $request;

    /** @var callable(string): string */
    private $translate;

    /**
     * @param callable(string): string $translate the translation of an EN string of the export
     */
    public function __construct(PrivacyRequest $request, callable $translate)
    {
        $this->request = $request;
        $this->translate = $translate;
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function export(array $params): string
    {
        $email = self::email($params);
        $rows = $email === '' ? [] : ExportRows::of($this->request->export($email, self::customer($params)), $this->translate);

        return (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string|int, mixed> $params
     * @param int $idShop the shop of the request
     */
    public function erase(array $params, int $idShop): string
    {
        $email = self::email($params);
        if ($email !== '') {
            $this->request->erase($email, self::customer($params), $idShop);
        }

        return 'true';
    }

    /**
     * @param array<string|int, mixed> $params
     */
    private static function email(array $params): string
    {
        return is_string($params['email'] ?? null) ? trim($params['email']) : '';
    }

    /**
     * @param array<string|int, mixed> $params
     */
    private static function customer(array $params): int
    {
        return is_scalar($params['id'] ?? null) ? (int) $params['id'] : 0;
    }
}
