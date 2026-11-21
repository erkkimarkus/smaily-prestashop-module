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

namespace PrestaShop\Module\SmailyForPrestaShop\Discount;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The cart rules that this module issued as discount codes (CodeIssuer::MARKER): the clean-up of
 * the ones nobody used. A cart rule that the merchant made is never read or removed here.
 *
 * A code is used once an order carries it (`order_cart_rule`). A used code stays: its orders name
 * it. The janitor removes an unused code once its end date is more than KEEP_SECONDS past, as the
 * WooCommerce connector does.
 */
final class IssuedCodes
{
    /** How long past its end date an unused code is kept. */
    public const KEEP_SECONDS = 30 * 86400;

    /** The most codes one clean-up call removes. */
    public const BATCH_SIZE = 200;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock the current Unix time; time() when null
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    /**
     * Removes up to $limit unused codes whose end date, in the shop's time, is more than
     * KEEP_SECONDS past, oldest first.
     *
     * @return int the number of codes removed
     */
    public function deleteExpiredUnused(int $limit = self::BATCH_SIZE): int
    {
        return $this->delete($this->ids(sprintf(
            'cr.`date_to` > \'0000-00-00 00:00:00\' AND cr.`date_to` < \'%s\' AND %s ORDER BY cr.`id_cart_rule` LIMIT %d',
            pSQL(date('Y-m-d H:i:s', ($this->clock)() - self::KEEP_SECONDS)),
            self::unused(),
            $limit
        )));
    }

    /**
     * The ids of the issued rules that $condition selects.
     *
     * @return list<int>
     */
    private function ids(string $condition): array
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            'SELECT cr.`id_cart_rule` FROM `%scart_rule` cr WHERE %s AND %s',
            _DB_PREFIX_,
            CodeIssuer::issued('cr'),
            $condition
        ), true, false);

        return array_map(static fn (array $row): int => (int) $row['id_cart_rule'], is_array($rows) ? $rows : []);
    }

    /**
     * Deletes the rules as PrestaShop does, with their restrictions.
     *
     * @param list<int> $ids
     */
    private function delete(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $deleted += (int) (new \CartRule($id))->delete();
        }

        return $deleted;
    }

    /**
     * The SQL condition that no order carries the cart rule read as `cr`.
     */
    private static function unused(): string
    {
        return 'NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'order_cart_rule` ocr WHERE ocr.`id_cart_rule` = cr.`id_cart_rule`)';
    }
}
