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

namespace PrestaShop\Module\SmailyForPrestaShop\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;

/**
 * Stores the attribution cookies of the request that places an order in
 * `smaily_connect_order_attribution`, one row per order (`actionValidateOrder`). The order's row
 * to the engine reads them from there (contract §5: `smaily_rec_id`, `smaily_rec_ctx`,
 * `smaily_visitor_token`).
 *
 * - Only for an order that a shopper's own storefront request places, in a shop with a connection.
 *   An order that an employee creates in the back office, a command or a payment provider's
 *   server call carries no shopper's cookies, and gets no row.
 * - Only what Attribution::read() answers: a well-formed recommendation id, its context, and the
 *   visitor token that may be sent. The context is stored only beside a recommendation id: the two
 *   describe one landing.
 * - An order without a recommendation id and without a visitor token gets no row.
 */
final class OrderAttribution
{
    public const TABLE = 'smaily_connect_order_attribution';

    private ActiveConnection $connections;

    private Attribution $attribution;

    /** @var callable(): ?Cookies */
    private $cookies;

    /**
     * @param callable(): ?Cookies $cookies the cookies of the storefront request, or null outside one
     */
    public function __construct(ActiveConnection $connections, Attribution $attribution, callable $cookies)
    {
        $this->connections = $connections;
        $this->attribution = $attribution;
        $this->cookies = $cookies;
    }

    /**
     * @param array<string, mixed> $params the parameters of `actionValidateOrder`
     */
    public function orderValidated(array $params): void
    {
        $order = $params['order'] ?? null;
        $customer = $params['customer'] ?? null;
        if (!$order instanceof \Order || (int) $order->id <= 0) {
            return;
        }
        $connection = $this->connections->stored((int) $order->id_shop);
        $cookies = $connection === null ? null : ($this->cookies)();
        if ($connection === null || $cookies === null) {
            return;
        }

        $read = $this->attribution->read($cookies, $connection, $customer instanceof \Customer ? $customer : null);
        if ($read['rec_id'] === null && $read['visitor_token'] === null) {
            return;
        }
        $values = [
            'rec_id' => $read['rec_id'],
            'context' => $read['rec_id'] === null ? null : $read['context'],
            'visitor_token' => $read['visitor_token'],
        ];
        $quoted = array_map(static fn (?string $value): string => $value === null ? 'NULL' : '\'' . pSQL($value) . '\'', $values);

        Sql::write(sprintf(
            'INSERT INTO `%s%s` (`id_order`, `rec_id`, `context`, `visitor_token`, `created_at`) VALUES (%d, %s, %s, %s, \'%s\')'
            . ' ON DUPLICATE KEY UPDATE `rec_id` = VALUES(`rec_id`), `context` = VALUES(`context`), `visitor_token` = VALUES(`visitor_token`)',
            _DB_PREFIX_,
            self::TABLE,
            (int) $order->id,
            $quoted['rec_id'],
            $quoted['context'],
            $quoted['visitor_token'],
            pSQL(gmdate('Y-m-d H:i:s'))
        ));
    }
}
