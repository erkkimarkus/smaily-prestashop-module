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

namespace PrestaShop\Module\SmailyForPrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The product feed URL builder's defaults (Settings > RSS), global: the values the builder opens
 * with, in the formats that the 3.0.0 upgrade writes from the 2.x keys (Migration\LegacyConfigMapper).
 * The feed itself (controllers/front/SmailyRssFeed.php) reads only its URL's parameters, so these
 * values change no feed URL that a merchant already uses.
 *
 * A value that is not stored, or that the feed does not take, reads as the feed's own default.
 */
final class RssSettings extends Settings
{
    /** The category id; `0` for all products. */
    public const CATEGORY = self::PREFIX . 'RSS_CATEGORY';

    /** The number of products, 1 to 250. */
    public const LIMIT = self::PREFIX . 'RSS_LIMIT';

    /** One of SORT_BY_VALUES. */
    public const SORT_BY = self::PREFIX . 'RSS_SORT_BY';

    /** `desc` or `asc`. */
    public const SORT_ORDER = self::PREFIX . 'RSS_SORT_ORDER';

    /** The sort fields that the feed takes (SmailyRssFeed::ALLOWED_SORT_BY_VALUES). */
    public const SORT_BY_VALUES = ['date_add', 'date_upd', 'name', 'price', 'id_product'];

    public const SORT_ORDERS = ['desc', 'asc'];

    public const MAX_LIMIT = 250;

    /** The feed's own defaults, for a URL without the parameter. */
    public const DEFAULT_LIMIT = 50;

    public const DEFAULT_SORT_BY = 'date_upd';

    public const DEFAULT_SORT_ORDER = 'desc';

    /**
     * @return array{category: int, limit: int, sort_by: string, sort_order: string}
     */
    public function defaults(): array
    {
        $category = (string) $this->get(self::CATEGORY);
        $limit = (string) $this->get(self::LIMIT);
        $sortBy = (string) $this->get(self::SORT_BY);
        $sortOrder = (string) $this->get(self::SORT_ORDER);

        return [
            'category' => ctype_digit($category) ? (int) $category : 0,
            'limit' => ctype_digit($limit) && (int) $limit >= 1 && (int) $limit <= self::MAX_LIMIT ? (int) $limit : self::DEFAULT_LIMIT,
            'sort_by' => in_array($sortBy, self::SORT_BY_VALUES, true) ? $sortBy : self::DEFAULT_SORT_BY,
            'sort_order' => in_array($sortOrder, self::SORT_ORDERS, true) ? $sortOrder : self::DEFAULT_SORT_ORDER,
        ];
    }

    /**
     * Stores the builder's defaults. Each value must be one that the feed takes; category 0 is all
     * products.
     */
    public function save(int $category, int $limit, string $sortBy, string $sortOrder): bool
    {
        if ($category < 0 || $limit < 1 || $limit > self::MAX_LIMIT
            || !in_array($sortBy, self::SORT_BY_VALUES, true) || !in_array($sortOrder, self::SORT_ORDERS, true)) {
            throw new \InvalidArgumentException('Not a value that the feed takes.');
        }

        return $this->set(self::CATEGORY, (string) $category)
            && $this->set(self::LIMIT, (string) $limit)
            && $this->set(self::SORT_BY, $sortBy)
            && $this->set(self::SORT_ORDER, $sortOrder);
    }
}
