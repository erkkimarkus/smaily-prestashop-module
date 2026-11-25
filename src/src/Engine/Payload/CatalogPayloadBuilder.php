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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Payload;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\ConnectionShops;

/**
 * A product as the catalog rows of one connection (contract §3) and as the items of its nightly
 * product list (§3c): one row per combination, and one for a product without combinations
 * (ARCHITECTURE 8.2, 8.3).
 *
 * The rows are built from the store as it is now, at the connection's canonical shop for the
 * product: the connection's own shop, or for the installation-wide connection the default shop
 * where it sells the product, else the lowest shop that does.
 *
 * - `price` is what a visitor who is not signed in pays for one unit, with tax, after reductions
 *   (Product::priceCalculation(), the calculation behind Product::getPriceStatic(), for the
 *   visitor group and the shop's default country and currency: it depends on nothing of the
 *   request that happens to build the row). `compare_price` is the same price without reductions,
 *   sent only when it is higher. No price of a customer group is sent.
 * - `in_stock` is each row's own: the product is active, visible and can be ordered in the shop,
 *   and the combination has stock or may be ordered without. A product that is switched off or
 *   hidden keeps its rows, each with `in_stock` false (the engine never deletes a row).
 * - `name`, `description` and `product_url` are plain text in a shop of one language and
 *   `{language: text}` in a shop of several: one row per product, never one per language.
 * - `websites` names the shops that sell the product, by shop id, only for a connection that
 *   serves two or more shops.
 * - The merchant's `reference` is never the key and is not sent.
 *
 * A combination that was deleted is sent once more as a row with `in_stock` false, because a
 * product removal (§3b) names a whole product and the engine keeps a row that stops arriving.
 *
 * A read that fails throws (Database\Sql), and so does a product that PrestaShop does not load:
 * the caller's row then takes the retry ladder, and nothing is sent of a product that was not
 * read.
 */
class CatalogPayloadBuilder
{
    /** The category of a product that has none: a placeholder, marked `tags.category_defaulted`. */
    public const PLACEHOLDER_CATEGORY = 'uncategorized';

    /** The contract's length of a description, per language. */
    private const DESCRIPTION_LENGTH = 500;

    /** @var array<int, array{0: int, 1: int}> the `id_shop` and `id_shop_group` of each shop's stock rows */
    private array $stockScopes = [];

    /** @var array<int, array<int, string>> the active languages of each shop, ISO code by id */
    private array $languages = [];

    /** @var array<string, string|null> the path of a category in a shop */
    private array $categoryPaths = [];

    private ?string $imageType = null;

    /**
     * The product's rows for a connection.
     *
     * @param list<int> $removed combinations of the product that were deleted
     *
     * @return list<array<string, mixed>>|null null when no shop of the connection holds the
     *                                         product; an empty list for a product that is
     *                                         switched off and has no name yet (a draft that was
     *                                         never in the catalog)
     */
    public function rows(Connection $connection, int $idProduct, array $removed = []): ?array
    {
        $facts = $this->facts($connection, [$idProduct])[$idProduct] ?? null;
        if ($facts === null) {
            return null;
        }
        // PrestaShop keeps prices per process: a row built in the request that changed the
        // product would carry the price from before the change.
        \Product::flushPriceCache();
        \SpecificPrice::flushCache();

        $shop = $facts['shop'];
        $texts = $this->texts($idProduct, $shop);
        if (!$facts['live'] && array_filter(array_column($texts, 'name')) === []) {
            return [];
        }

        $categoryPath = $this->categoryPath((int) $facts['row']['id_category_default'], $shop);
        $labels = $this->combinationLabels($idProduct, array_keys($texts));
        $prefix = $connection->prefix();
        $base = [
            'category_path' => $categoryPath ?? self::PLACEHOLDER_CATEGORY,
            'currency' => StoreCurrency::isoCode($shop),
            'image_url' => $this->imageUrl($idProduct, $shop, $texts),
            'tags' => array_filter([
                'category_path' => $categoryPath ?? self::PLACEHOLDER_CATEGORY,
                'product_id' => Keys::productId($prefix, $idProduct),
                'brand' => $this->brand((int) $facts['row']['id_manufacturer']),
                'category_defaulted' => $categoryPath === null ? 'true' : null,
            ], static fn ($value): bool => $value !== null && $value !== ''),
            'product_type' => (string) $facts['row']['product_type'] !== '' ? (string) $facts['row']['product_type'] : 'standard',
            'is_virtual' => (bool) (int) $facts['row']['is_virtual'],
        ];

        $rows = [];
        foreach ($facts['combinations'] === [] ? [0] : $facts['combinations'] as $idAttribute) {
            $rows[] = $this->row($connection, $idProduct, $idAttribute, $idAttribute, $facts, $texts, $labels[$idAttribute] ?? [], $base, self::inStock($facts, $idAttribute));
        }
        foreach (array_diff(array_map('intval', $removed), $facts['combinations'], [0]) as $idAttribute) {
            // The combination is gone: its row takes the product's own price and link.
            $rows[] = $this->row($connection, $idProduct, $idAttribute, 0, $facts, $texts, [], $base, false);
        }

        return $rows;
    }

    /**
     * The items of the nightly product list (§3c) for these products: the `sku` and `in_stock` of
     * every row that rows() sends for a product that is switched on in a shop of the connection.
     * A product that is switched off everywhere is left out, so the engine stops recommending it.
     *
     * @param list<int> $idProducts
     *
     * @return list<array{sku: string, in_stock: bool}>
     */
    public function manifestItems(Connection $connection, array $idProducts): array
    {
        $items = [];
        foreach ($this->facts($connection, $idProducts) as $idProduct => $facts) {
            if (!$facts['active']) {
                continue;
            }
            foreach ($facts['combinations'] === [] ? [0] : $facts['combinations'] as $idAttribute) {
                $items[] = ['sku' => Keys::sku($connection->prefix(), $idProduct, $idAttribute), 'in_stock' => self::inStock($facts, $idAttribute)];
            }
        }

        return $items;
    }

    /**
     * What the store holds of each product for the connection: its row in the canonical shop, the
     * shops that sell it, its combinations and its stock there. A product that no shop of the
     * connection holds is not in the answer.
     *
     * @param list<int> $idProducts
     *
     * @return array<int, array{shop: int, row: array<string, string|null>, selling: list<int>, live: bool, active: bool, combinations: list<int>, stock: array<int, array{quantity: int, out_of_stock: int}>}>
     */
    private function facts(Connection $connection, array $idProducts): array
    {
        $ids = implode(', ', array_map('intval', $idProducts));
        if ($ids === '') {
            return [];
        }
        $shops = ConnectionShops::of($connection);
        $inShops = implode(', ', $shops);

        $byProduct = [];
        $rows = Sql::rows(
            'SELECT p.`id_product`, p.`product_type`, p.`is_virtual`, p.`id_manufacturer`, ps.`id_shop`, ps.`active`, ps.`visibility`,'
            . ' ps.`available_for_order`, ps.`id_category_default`'
            . ' FROM `' . _DB_PREFIX_ . 'product` p'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product`'
            . ' WHERE p.`id_product` IN (' . $ids . ') AND ps.`id_shop` IN (' . $inShops . ')'
            . ' ORDER BY p.`id_product`, ps.`id_shop`'
        );
        foreach ($rows as $row) {
            $byProduct[(int) $row['id_product']][(int) $row['id_shop']] = $row;
        }
        if ($byProduct === []) {
            return [];
        }

        $default = $connection->idShop() > 0 ? $connection->idShop() : (int) \Configuration::get('PS_SHOP_DEFAULT');
        $facts = [];
        foreach ($byProduct as $idProduct => $shopRows) {
            $selling = array_keys(array_filter($shopRows, static fn (array $row): bool => (int) $row['active'] === 1 && $row['visibility'] !== 'none'));
            $candidates = $selling !== [] ? $selling : array_keys($shopRows);
            $shop = in_array($default, $candidates, true) ? $default : min($candidates);
            $facts[$idProduct] = [
                'shop' => $shop,
                'row' => $shopRows[$shop],
                'selling' => $selling,
                'live' => $selling !== [],
                'active' => array_filter($shopRows, static fn (array $row): bool => (int) $row['active'] === 1) !== [],
                'combinations' => [],
                'stock' => [],
            ];
        }

        $combinations = Sql::rows(
            'SELECT `id_product`, `id_product_attribute`, `id_shop` FROM `' . _DB_PREFIX_ . 'product_attribute_shop`'
            . ' WHERE `id_product` IN (' . $ids . ') AND `id_shop` IN (' . $inShops . ') ORDER BY `id_product_attribute`'
        );
        foreach ($combinations as $row) {
            $idProduct = (int) $row['id_product'];
            if (isset($facts[$idProduct]) && (int) $row['id_shop'] === $facts[$idProduct]['shop']) {
                $facts[$idProduct]['combinations'][] = (int) $row['id_product_attribute'];
            }
        }

        $stock = Sql::rows(
            'SELECT `id_product`, `id_product_attribute`, `id_shop`, `id_shop_group`, `quantity`, `out_of_stock`'
            . ' FROM `' . _DB_PREFIX_ . 'stock_available` WHERE `id_product` IN (' . $ids . ')'
        );
        foreach ($stock as $row) {
            $idProduct = (int) $row['id_product'];
            if (isset($facts[$idProduct]) && [(int) $row['id_shop'], (int) $row['id_shop_group']] === $this->stockScope($facts[$idProduct]['shop'])) {
                $facts[$idProduct]['stock'][(int) $row['id_product_attribute']] = ['quantity' => (int) $row['quantity'], 'out_of_stock' => (int) $row['out_of_stock']];
            }
        }

        return $facts;
    }

    /**
     * Whether a row can be bought now: the product sells in the shop and may be ordered, and the
     * combination has stock, or the shop takes an order without it (PrestaShop's own rule,
     * Product::isAvailableWhenOutOfStock(), read for the row's shop).
     *
     * @param array{shop: int, row: array<string, string|null>, live: bool, stock: array<int, array{quantity: int, out_of_stock: int}>} $facts
     */
    private static function inStock(array $facts, int $idAttribute): bool
    {
        if (!$facts['live'] || (int) $facts['row']['available_for_order'] !== 1) {
            return false;
        }
        if (!(bool) \Configuration::get('PS_STOCK_MANAGEMENT', null, null, $facts['shop'])) {
            return true;
        }
        if (($facts['stock'][$idAttribute]['quantity'] ?? 0) > 0) {
            return true;
        }
        // The product's own setting: 0 refuses an order without stock, 1 takes it, 2 follows the shop.
        $whenOut = $facts['stock'][0]['out_of_stock'] ?? 2;

        return $whenOut === 2 ? (bool) \Configuration::get('PS_ORDER_OUT_OF_STOCK', null, null, $facts['shop']) : $whenOut === 1;
    }

    /**
     * One row.
     *
     * @param int $idAttribute the combination the row names; 0 for a product without one
     * @param int $pricedAs the combination the price and the link are read for; 0 for the product's own
     * @param array<string, mixed> $facts
     * @param array<int, array{iso: string, name: string, description: string, product: \Product}> $texts
     * @param array<int, string> $labels the combination's attributes in each language
     * @param array<string, mixed> $base
     *
     * @return array<string, mixed>
     */
    private function row(Connection $connection, int $idProduct, int $idAttribute, int $pricedAs, array $facts, array $texts, array $labels, array $base, bool $inStock): array
    {
        $shop = $facts['shop'];
        $price = $this->price($idProduct, $pricedAs, $shop, true);
        $regular = $this->price($idProduct, $pricedAs, $shop, false);

        $names = [];
        $descriptions = [];
        foreach ($texts as $idLang => $text) {
            if ($text['name'] !== '') {
                $names[$text['iso']] = $text['name'] . (($labels[$idLang] ?? '') !== '' ? ' - ' . $labels[$idLang] : '');
            }
            if ($text['description'] !== '') {
                $descriptions[$text['iso']] = $text['description'];
            }
        }
        $several = count($texts) > 1;

        $row = [
            'sku' => Keys::sku($connection->prefix(), $idProduct, $idAttribute),
            // A product without a name in any language goes with '': the engine refuses that row.
            'name' => $several && $names !== [] ? $names : (string) reset($names),
            'category_path' => $base['category_path'],
            'price' => $price,
        ];
        if ($regular > $price) {
            $row['compare_price'] = $regular;
        }
        $row['currency'] = $base['currency'];
        $row['in_stock'] = $inStock;
        if ($descriptions !== []) {
            $row['description'] = $several ? $descriptions : (string) reset($descriptions);
        }
        if ($base['image_url'] !== null) {
            $row['image_url'] = $base['image_url'];
        }
        $row['product_url'] = $this->urls($texts, $shop, $pricedAs);
        $row['external_id'] = Keys::externalId($idProduct, $idAttribute);
        $row['tags'] = $base['tags'];
        if (ConnectionShops::website($connection, $shop) !== null) {
            $websites = [];
            foreach ($facts['selling'] as $selling) {
                $websites[(string) $selling] = ['product_url' => $this->urls($selling === $shop ? $texts : $this->texts($idProduct, $selling), $selling, $pricedAs)];
            }
            // An object also when no shop sells the product: `{}` is "sold on no website".
            $row['websites'] = (object) $websites;
        }
        $row['product_type'] = $base['product_type'];
        $row['is_virtual'] = $base['is_virtual'];

        return $row;
    }

    /**
     * The product in each active language of a shop: its name, its short description as plain
     * text, and the loaded product, which names its links.
     *
     * @return array<int, array{iso: string, name: string, description: string, product: \Product}> by language id
     */
    private function texts(int $idProduct, int $idShop): array
    {
        $texts = [];
        foreach ($this->languagesOf($idShop) as $idLang => $iso) {
            $product = new \Product($idProduct, false, $idLang, $idShop);
            if (!\Validate::isLoadedObject($product)) {
                throw new \RuntimeException('PrestaShop did not load a product that the shop holds.');
            }
            $texts[$idLang] = [
                'iso' => $iso,
                'name' => trim((string) $product->name),
                'description' => self::plainText((string) $product->description_short),
                'product' => $product,
            ];
        }
        if ($texts === []) {
            throw new \RuntimeException('The shop has no active language.');
        }

        return $texts;
    }

    /**
     * The link of the product, or of one combination of it, in each language of a shop: plain text
     * for one language, else `{language: link}`.
     *
     * @param array<int, array{iso: string, product: \Product}> $texts
     *
     * @return string|array<string, string>
     */
    private function urls(array $texts, int $idShop, int $idAttribute)
    {
        $link = \Context::getContext()->link ?? new \Link();
        $urls = [];
        foreach ($texts as $idLang => $text) {
            $urls[$text['iso']] = (string) $link->getProductLink($text['product'], null, null, null, $idLang, $idShop, $idAttribute > 0 ? $idAttribute : null);
        }

        return count($urls) > 1 ? $urls : (string) reset($urls);
    }

    /**
     * What a visitor who is not signed in pays for one unit in the shop, with tax unless the shop
     * charges none; without reductions when $reduced is false.
     */
    private function price(int $idProduct, int $idAttribute, int $idShop, bool $reduced): float
    {
        $specificPrice = null;
        $price = \Product::priceCalculation(
            $idShop,
            $idProduct,
            $idAttribute > 0 ? $idAttribute : null,
            (int) \Configuration::get('PS_COUNTRY_DEFAULT', null, null, $idShop),
            0,
            '',
            (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop),
            (int) \Configuration::get('PS_UNIDENTIFIED_GROUP'),
            1,
            !\Tax::excludeTaxeOption(),
            6,
            false,
            $reduced,
            true,
            $specificPrice,
            true,
            0,
            true,
            0,
            0,
            0
        );
        if ($price === null) {
            throw new \RuntimeException('PrestaShop gave no price for a product that the shop holds.');
        }

        return round((float) $price, 2);
    }

    /**
     * The slugs of the product's default category from below the shop's home category, joined by
     * `/`, in the shop's default language; null for a product in no category below it.
     */
    private function categoryPath(int $idCategory, int $idShop): ?string
    {
        $key = $idShop . ':' . $idCategory;
        if (!array_key_exists($key, $this->categoryPaths)) {
            $slugs = array_column(Sql::rows(
                'SELECT cl.`link_rewrite` FROM `' . _DB_PREFIX_ . 'category` c'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'category` parent ON parent.`nleft` <= c.`nleft` AND parent.`nright` >= c.`nright`'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = parent.`id_category`'
                . ' AND cl.`id_shop` = ' . $idShop . ' AND cl.`id_lang` = ' . (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)
                . ' WHERE c.`id_category` = ' . $idCategory . ' AND parent.`level_depth` >= 2 AND parent.`is_root_category` = 0'
                . ' ORDER BY parent.`level_depth`'
            ), 'link_rewrite');
            $slugs = array_values(array_filter(array_map('strval', $slugs), static fn (string $slug): bool => $slug !== ''));
            $this->categoryPaths[$key] = $slugs === [] ? null : implode('/', $slugs);
        }

        return $this->categoryPaths[$key];
    }

    /**
     * The attributes of each combination of the product, as "Size: S, Color: White", in each
     * language.
     *
     * @param list<int> $idLangs
     *
     * @return array<int, array<int, string>> by combination, then by language id
     */
    private function combinationLabels(int $idProduct, array $idLangs): array
    {
        $rows = Sql::rows(
            'SELECT pa.`id_product_attribute`, al.`id_lang`, agl.`public_name`, al.`name`'
            . ' FROM `' . _DB_PREFIX_ . 'product_attribute` pa'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac ON pac.`id_product_attribute` = pa.`id_product_attribute`'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'attribute_group` ag ON ag.`id_attribute_group` = a.`id_attribute_group`'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute`'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON agl.`id_attribute_group` = a.`id_attribute_group` AND agl.`id_lang` = al.`id_lang`'
            . ' WHERE pa.`id_product` = ' . $idProduct . ' AND al.`id_lang` IN (' . implode(', ', array_map('intval', $idLangs)) . ')'
            . ' ORDER BY ag.`position`, a.`position`'
        );
        $parts = [];
        foreach ($rows as $row) {
            $parts[(int) $row['id_product_attribute']][(int) $row['id_lang']][] = trim((string) $row['public_name']) . ': ' . trim((string) $row['name']);
        }

        return array_map(static fn (array $byLanguage): array => array_map(static fn (array $attributes): string => implode(', ', $attributes), $byLanguage), $parts);
    }

    private function brand(int $idManufacturer): ?string
    {
        if ($idManufacturer <= 0) {
            return null;
        }
        $name = Sql::value('SELECT `name` FROM `' . _DB_PREFIX_ . 'manufacturer` WHERE `id_manufacturer` = ' . $idManufacturer);

        return $name === null ? null : trim($name);
    }

    /**
     * The link of the product's cover image in the shop's largest product image size; null for a
     * product without an image.
     *
     * @param array<int, array{product: \Product}> $texts
     */
    private function imageUrl(int $idProduct, int $idShop, array $texts): ?string
    {
        $idImage = Sql::value(
            'SELECT `id_image` FROM `' . _DB_PREFIX_ . 'image_shop` WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop . ' AND `cover` = 1'
        );
        if ($idImage === null) {
            return null;
        }
        if ($this->imageType === null) {
            $this->imageType = (string) Sql::value(
                'SELECT `name` FROM `' . _DB_PREFIX_ . 'image_type` WHERE `products` = 1 ORDER BY `width` DESC, `height` DESC'
            );
        }
        if ($this->imageType === '') {
            return null;
        }
        $link = \Context::getContext()->link ?? new \Link();
        $url = (string) $link->getImageLink((string) reset($texts)['product']->link_rewrite, $idImage, $this->imageType);

        return $url === '' ? null : $url;
    }

    /**
     * @return array<int, string> the ISO code of each active language of the shop, by language id
     */
    private function languagesOf(int $idShop): array
    {
        if (!isset($this->languages[$idShop])) {
            $this->languages[$idShop] = [];
            foreach (\Language::getLanguages(true, $idShop) as $language) {
                $this->languages[$idShop][(int) $language['id_lang']] = strtolower((string) $language['iso_code']);
            }
        }

        return $this->languages[$idShop];
    }

    /**
     * Where the shop's stock rows are stored: by shop, or by shop group where the group shares its
     * stock (as StockAvailable::addSqlShopRestriction() reads them).
     *
     * @return array{0: int, 1: int} `id_shop` and `id_shop_group`
     */
    private function stockScope(int $idShop): array
    {
        if (!isset($this->stockScopes[$idShop])) {
            $group = (new \Shop($idShop))->getGroup();
            $this->stockScopes[$idShop] = (bool) $group->share_stock ? [0, (int) $group->id] : [$idShop, 0];
        }

        return $this->stockScopes[$idShop];
    }

    private static function plainText(string $html): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_substr($text, 0, self::DESCRIPTION_LENGTH);
    }
}
