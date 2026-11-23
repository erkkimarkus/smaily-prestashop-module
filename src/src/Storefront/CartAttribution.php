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
 * Keeps the attribution of a shopper's cart in `smaily_connect_cart_attribution`, one row per
 * cart, for the order that a request without the shopper's cookies creates: a payment provider's
 * server call, an employee, a command (OrderAttribution reads it there). As the Shopify app keeps
 * the same values in the cart's attributes.
 *
 * - Written in the shopper's own storefront request, when a page of a shop with a connection is
 *   built for a visitor who has a cart (`actionFrontControllerSetMedia`). The page itself gets
 *   nothing of it.
 * - Only what Attribution::read() answers for that request, by the rule of the order's own row: a
 *   well-formed recommendation id with its context (the landing cookies, not gated on consent), an
 *   engine visitor token as it is, and a store-created token only with marketing consent. A
 *   visitor without any of these gets no row.
 * - A row whose values did not change is written again once in REFRESH_SECONDS, so its time
 *   follows the shopper's visits without a write for every page.
 * - The order takes the row and deletes it (take()). A cart that gets no order loses its row
 *   KEEP_SECONDS after the shopper's last page with it (deleteStale(), the janitor).
 */
final class CartAttribution
{
    public const TABLE = 'smaily_connect_cart_attribution';

    public const REFRESH_SECONDS = 3600;

    public const KEEP_SECONDS = 86400;

    private ActiveConnection $connections;

    private Attribution $attribution;

    /** @var callable(): ?Cookies */
    private $cookies;

    /** @var callable(): int */
    private $now;

    /** @var array<int, array{rec_id: ?string, context: ?string, visitor_token: ?string}|null> the rows that this request took, by cart */
    private array $taken = [];

    /**
     * @param callable(): ?Cookies $cookies the cookies of the storefront request, or null outside one
     * @param callable(): int $now the current Unix time
     */
    public function __construct(ActiveConnection $connections, Attribution $attribution, callable $cookies, callable $now)
    {
        $this->connections = $connections;
        $this->attribution = $attribution;
        $this->cookies = $cookies;
        $this->now = $now;
    }

    /**
     * Keeps what the request's cookies say with the visitor's cart.
     */
    public function capture(\Context $context): void
    {
        $cart = $context->cart;
        if (!$cart instanceof \Cart || (int) $cart->id <= 0 || !$context->shop instanceof \Shop) {
            return;
        }
        $connection = $this->connections->stored((int) $context->shop->id);
        $cookies = $connection === null ? null : ($this->cookies)();
        if ($connection === null || $cookies === null) {
            return;
        }
        $read = $this->attribution->read($cookies, $connection);
        if ($read['rec_id'] === null && $read['visitor_token'] === null) {
            return;
        }
        $values = [$read['rec_id'], $read['rec_id'] === null ? null : $read['context'], $read['visitor_token']];

        $now = ($this->now)();
        $stored = Sql::row(sprintf('SELECT `rec_id`, `context`, `visitor_token`, `updated_at` FROM %s WHERE `id_cart` = %d', self::table(), (int) $cart->id));
        if ($stored !== null
            && [$stored['rec_id'], $stored['context'], $stored['visitor_token']] === $values
            && (string) $stored['updated_at'] > gmdate('Y-m-d H:i:s', $now - self::REFRESH_SECONDS)
        ) {
            return;
        }
        $quoted = array_map(static fn (?string $value): string => $value === null ? 'NULL' : '\'' . pSQL($value) . '\'', $values);
        Sql::write(sprintf(
            'INSERT INTO %s (`id_cart`, `rec_id`, `context`, `visitor_token`, `updated_at`) VALUES (%d, %s, %s, %s, \'%s\')'
            . ' ON DUPLICATE KEY UPDATE `rec_id` = VALUES(`rec_id`), `context` = VALUES(`context`), `visitor_token` = VALUES(`visitor_token`), `updated_at` = VALUES(`updated_at`)',
            self::table(),
            (int) $cart->id,
            $quoted[0],
            $quoted[1],
            $quoted[2],
            pSQL(gmdate('Y-m-d H:i:s', $now))
        ));
    }

    /**
     * The values kept with a cart, or null when it has none. The row is deleted: the order holds
     * them from here on. A cart that PrestaShop splits into several orders answers each of them
     * the same in one request.
     *
     * @return array{rec_id: ?string, context: ?string, visitor_token: ?string}|null
     */
    public function take(int $idCart): ?array
    {
        if ($idCart <= 0) {
            return null;
        }
        if (!array_key_exists($idCart, $this->taken)) {
            $row = Sql::row(sprintf('SELECT `rec_id`, `context`, `visitor_token` FROM %s WHERE `id_cart` = %d', self::table(), $idCart));
            if ($row !== null) {
                Sql::write(sprintf('DELETE FROM %s WHERE `id_cart` = %d', self::table(), $idCart));
            }
            $this->taken[$idCart] = $row === null ? null : ['rec_id' => $row['rec_id'], 'context' => $row['context'], 'visitor_token' => $row['visitor_token']];
        }

        return $this->taken[$idCart];
    }

    /**
     * Deletes up to $limit rows of carts whose shopper last opened a page with them more than
     * KEEP_SECONDS ago, by primary key.
     *
     * @return int the number of rows deleted
     */
    public function deleteStale(int $limit): int
    {
        $condition = sprintf('`updated_at` < \'%s\'', pSQL(gmdate('Y-m-d H:i:s', ($this->now)() - self::KEEP_SECONDS)));
        $rows = Sql::rows(sprintf('SELECT `id_cart` FROM %s WHERE %s LIMIT %d', self::table(), $condition, $limit));
        if ($rows === []) {
            return 0;
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id_cart'], $rows);
        Sql::write(sprintf('DELETE FROM %s WHERE `id_cart` IN (%s) AND %s', self::table(), implode(', ', $ids), $condition));

        return Sql::affectedRows();
    }

    private static function table(): string
    {
        return '`' . _DB_PREFIX_ . self::TABLE . '`';
    }
}
