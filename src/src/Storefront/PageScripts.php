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

use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;

/**
 * Puts the storefront scripts (`views/js/front/`) and what they read on every storefront page of a
 * shop with a Campaign Intelligence connection (`actionFrontControllerSetMedia`); nothing on a
 * shop without one.
 *
 * - `consent.js` and `attribution.js` load with a stored connection: the landing capture calls no
 *   engine. `tracker.js` and `recommendations.js` load only while the engine takes the connection.
 * - What the scripts read is one JavaScript definition, JS_DEF (Media::addJsDef()): the merchant's
 *   consent setting, the three endpoint addresses, the cookie and link parameter names with the
 *   cookies' lifetimes, and the page's context (its type, and the product, the category path or
 *   the search words that the page shows).
 * - Every value describes the shop or the page, none the visitor, so a page that a cache keeps is
 *   right for every visitor. Nothing here is a secret: the connection's key stays on the server.
 *
 * The scripts are static files. Whether they track is decided in the browser (`consent.js`):
 * loading them sets no cookie and sends no event.
 */
final class PageScripts
{
    public const JS_DEF = 'smailyConnectStorefront';

    private const MODULE = 'smailyforprestashop';

    private const MAX_TEXT = 255;

    /** The page's type by the front controller's name (`php_self`). */
    private const PAGE_TYPES = [
        'product' => 'product',
        'category' => 'category',
        'search' => 'search',
        'order' => 'checkout',
        'order-confirmation' => 'success',
    ];

    private ActiveConnection $connections;

    /** @var callable(int): bool */
    private $consentConfirmed;

    /**
     * @param callable(int): bool $consentConfirmed whether the merchant confirmed the cookie banner
     *                                              for a connection's id_shop
     *                                              (Config\EngineSettings::consentConfirmed())
     */
    public function __construct(ActiveConnection $connections, callable $consentConfirmed)
    {
        $this->connections = $connections;
        $this->consentConfirmed = $consentConfirmed;
    }

    public function add(\Context $context): void
    {
        $controller = $context->controller;
        if (!$controller instanceof \FrontController || !$context->shop instanceof \Shop) {
            return;
        }
        $idShop = (int) $context->shop->id;
        $connection = $this->connections->stored($idShop);
        if ($connection === null) {
            return;
        }
        $sending = ActiveConnection::isTaken($connection);

        \Media::addJsDef([self::JS_DEF => $this->definition($context, $controller, $connection, $sending)]);
        foreach ($sending ? ['consent', 'attribution', 'tracker', 'recommendations'] : ['consent', 'attribution'] as $index => $script) {
            $controller->registerJavascript(
                'modules-' . self::MODULE . '-' . $script,
                'modules/' . self::MODULE . '/views/js/front/' . $script . '.js',
                ['position' => 'bottom', 'priority' => 210 + $index]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(\Context $context, \FrontController $controller, Connection $connection, bool $sending): array
    {
        $names = new ClientConfig($connection);
        $link = $context->link;
        $cookies = [ClientConfig::VISITOR, ClientConfig::SESSION, ClientConfig::REC_ID, ClientConfig::CONTEXT];

        return [
            'consentConfirmed' => ($this->consentConfirmed)($connection->idShop()),
            'landUrl' => $link->getModuleLink(self::MODULE, 'land'),
            'relayUrl' => $sending ? $link->getModuleLink(self::MODULE, 'relay') : null,
            'recommendationsUrl' => $sending ? $link->getModuleLink(self::MODULE, 'recommendations') : null,
            'cookiePath' => (string) $context->shop->getBaseURI(),
            'cookies' => array_combine($cookies, array_map([$names, 'cookieName'], $cookies)),
            'days' => array_combine($cookies, array_map([$names, 'days'], $cookies)),
            'parameters' => [
                ClientConfig::VISITOR => $names->parameter(ClientConfig::VISITOR),
                ClientConfig::REC_ID => $names->parameter(ClientConfig::REC_ID),
                ClientConfig::CONTEXT => $names->parameter(ClientConfig::CONTEXT),
            ],
            'page' => self::page($controller, (int) $context->language->id),
        ];
    }

    /**
     * What the page shows, for the browse events: `type`, and where the page has them
     * `productId` with `productAttributeId`, `categoryPath` or `searchQuery`.
     *
     * @return array<string, mixed>
     */
    private static function page(\FrontController $controller, int $idLang): array
    {
        $page = ['type' => self::PAGE_TYPES[(string) $controller->php_self] ?? null];

        if ($controller instanceof \ProductController && $controller->getProduct() instanceof \Product) {
            $idProduct = (int) $controller->getProduct()->id;
            $page['productId'] = $idProduct;
            $page['productAttributeId'] = (int) \Tools::getValue('id_product_attribute') ?: (int) \Product::getDefaultAttribute($idProduct);
        } elseif ($controller instanceof \CategoryController && $controller->getCategory() instanceof \Category) {
            $page['categoryPath'] = self::categoryPath($controller->getCategory(), $idLang);
        } elseif ($page['type'] === 'search') {
            $query = trim((string) \Tools::getValue('s'));
            if ($query !== '' && strlen($query) <= self::MAX_TEXT) {
                $page['searchQuery'] = $query;
            }
        }

        return $page;
    }

    /**
     * The category's path of friendly names from below the shop's home category, e.g.
     * `clothes/men`.
     */
    private static function categoryPath(\Category $category, int $idLang): string
    {
        $names = [];
        foreach ($category->getParentsCategories($idLang) as $parent) {
            if (empty($parent['is_root_category']) && (int) $parent['id_parent'] > 0 && (string) $parent['link_rewrite'] !== '') {
                $names[] = (string) $parent['link_rewrite'];
            }
        }

        return substr(implode('/', array_reverse($names)), 0, self::MAX_TEXT);
    }
}
