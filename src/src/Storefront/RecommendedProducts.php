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
use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;

/**
 * The store's own products for the engine's recommendation slots (contract §15: "render with
 * `external_id`"), presented as PrestaShop presents a product of a list, so the theme's own
 * product miniature draws them with the store's name, image and price of this shop, language,
 * currency and customer group.
 *
 * - A slot names its product by `external_id`: `<id_product>`, or
 *   `<id_product>-<id_product_attribute>` for a combination. Any other value is left out.
 * - A product is left out when it is not active or not visible in the catalog in this shop, when
 *   its combination is not this product's in this shop, when it cannot be ordered, and when it is
 *   out of stock and the shop takes no order for it then. Nothing takes its place.
 * - Each link is the product's own with the slot's recommendation id and the context `storefront`,
 *   so the landing script credits a purchase to the storefront (§15 "Attribution").
 */
final class RecommendedProducts
{
    /** The §15 context of a link in the storefront block. */
    public const CONTEXT = 'storefront';

    private const EXTERNAL_ID = '/^([1-9][0-9]{0,9})(?:-([1-9][0-9]{0,9}))?$/D';

    /**
     * @param list<array{rec_id: string, external_id: string}> $slots
     *
     * @return list<mixed> the presented products, in the slots' order
     */
    public function forSlots(array $slots, ClientConfig $names, \Context $context): array
    {
        $wanted = [];
        foreach ($slots as $slot) {
            if (preg_match(self::EXTERNAL_ID, $slot['external_id'], $match) === 1) {
                $wanted[] = [(int) $match[1], (int) ($match[2] ?? 0), $slot['rec_id']];
            }
        }
        if ($wanted === []) {
            return [];
        }

        $idShop = (int) $context->shop->id;
        $products = self::sellable($idShop, array_column($wanted, 0));
        $combinations = self::combinations($idShop, array_filter(array_column($wanted, 1)));

        $assembler = new \ProductAssembler($context);
        $settings = (new \ProductPresenterFactory($context))->getPresentationSettings();
        $presenter = new ProductListingPresenter(
            new ImageRetriever($context->link),
            $context->link,
            new PriceFormatter(),
            new ProductColorsRetriever(),
            $context->getTranslator()
        );
        $stockManaged = (bool) \Configuration::get('PS_STOCK_MANAGEMENT');

        $presented = [];
        foreach ($wanted as [$idProduct, $idAttribute, $recId]) {
            if (!isset($products[$idProduct]) || ($idAttribute > 0 && ($combinations[$idAttribute] ?? 0) !== $idProduct)) {
                continue;
            }
            $raw = ['id_product' => $idProduct];
            if ($idAttribute > 0) {
                $raw['id_product_attribute'] = $idAttribute;
            }
            $product = $assembler->assembleProduct($raw);
            if (!is_array($product) || empty($product['available_for_order'])
                || ($stockManaged && (int) ($product['quantity'] ?? 0) <= 0 && empty($product['allow_oosp']))) {
                continue;
            }
            $item = $presenter->present($settings, $product, $context->language);
            $item->offsetSet('url', self::landing((string) $item['url'], [
                $names->parameter(ClientConfig::REC_ID) => $recId,
                $names->parameter(ClientConfig::CONTEXT) => self::CONTEXT,
            ]), true);
            $presented[] = $item;
        }

        return $presented;
    }

    /**
     * $url with the landing parameters in its query, before a fragment (a combination's link
     * names its attributes in one).
     *
     * @param array<string, string> $parameters
     */
    public static function landing(string $url, array $parameters): string
    {
        $parts = explode('#', $url, 2);
        $landing = $parts[0] . (strpos($parts[0], '?') === false ? '?' : '&') . http_build_query($parameters);

        return isset($parts[1]) ? $landing . '#' . $parts[1] : $landing;
    }

    /**
     * The products among $ids that are active and visible in the catalog in the shop.
     *
     * @param list<int> $ids
     *
     * @return array<int, true>
     */
    private static function sellable(int $idShop, array $ids): array
    {
        $rows = Sql::rows(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_shop`'
            . ' WHERE `id_shop` = ' . $idShop . ' AND `active` = 1 AND `visibility` IN (\'both\', \'catalog\')'
            . ' AND `id_product` IN (' . implode(',', array_map('intval', $ids)) . ')'
        );

        return array_fill_keys(array_map('intval', array_column($rows, 'id_product')), true);
    }

    /**
     * The product of each combination among $ids in the shop.
     *
     * @param array<int> $ids
     *
     * @return array<int, int> id_product by id_product_attribute
     */
    private static function combinations(int $idShop, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = Sql::rows(
            'SELECT `id_product_attribute`, `id_product` FROM `' . _DB_PREFIX_ . 'product_attribute_shop`'
            . ' WHERE `id_shop` = ' . $idShop . ' AND `id_product_attribute` IN (' . implode(',', array_map('intval', $ids)) . ')'
        );

        return array_map('intval', array_column($rows, 'id_product', 'id_product_attribute'));
    }
}
