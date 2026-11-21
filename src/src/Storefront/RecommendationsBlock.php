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

/**
 * The recommendations block "Recommended for you", in its two halves.
 *
 * - container(): what the module's widget puts into the page, on a display hook or through
 *   `{widget name='smailyforprestashop'}`: an empty element, the same for every visitor, so a
 *   cached page is right for everyone. Nothing while the shop has no connection that the engine
 *   takes. The merchant places it; the module places it nowhere by itself. On the home page of a
 *   theme that is built from components the element is the theme's `container`, as that theme's
 *   own product blocks are there: its home page gives a module the full width of the window.
 * - answer(): what the front controller `recommendations` answers to the storefront script after
 *   the page has loaded: the shopper's products as HTML, drawn by the theme's own product
 *   miniature, or an empty body. An empty body leaves the container empty: no heading, no frame.
 *
 * Who the products are for comes from the server's own state, never from the request:
 *
 * - a logged-in customer by the customer id, unless the store holds the customer's opt-out of
 *   personalization (Privacy\ProfilingConsent): that customer is never asked about, not by the
 *   visitor token either, as in the Magento connector;
 * - any other visitor by the visitor token of the request's cookie that may be sent
 *   (Attribution::read());
 * - nobody else.
 *
 * Guards of answer(), in order, as the Magento connector's Controller\Recommendations\Index: 404
 * while the shop has no connection that the engine takes; 429 beyond LIMIT_PER_MINUTE requests of
 * one network address in a minute, because every miss of the cache costs an engine call; an empty
 * 200 to a request that another site makes (`Sec-Fetch-Site: cross-site` or `same-site`), because
 * the answer belongs to the shopper whose cookies came with it.
 */
final class RecommendationsBlock
{
    public const LIMIT_PER_MINUTE = 120;

    private const CONTAINER_TEMPLATE = 'module:smailyforprestashop/views/templates/front/recommendations-container.tpl';

    private const PRODUCTS_TEMPLATE = 'module:smailyforprestashop/views/templates/front/recommendations.tpl';

    /** A partial of themes that are built from components, as PrestaShop 9's default theme. */
    private const THEME_TITLE_TEMPLATE = 'components/section-title.tpl';

    /** The hooks of the home page start with this name. */
    private const HOME_HOOKS = 'displayHome';

    private ActiveConnection $connections;

    private RateLimiter $limiter;

    private Attribution $attribution;

    private Recommendations $recommendations;

    private RecommendedProducts $products;

    /** @var callable(\Customer): bool */
    private $objected;

    /**
     * @param callable(\Customer): bool $objected whether the store holds the customer's opt-out
     *                                            of personalization
     */
    public function __construct(ActiveConnection $connections, RateLimiter $limiter, Attribution $attribution, Recommendations $recommendations, RecommendedProducts $products, callable $objected)
    {
        $this->connections = $connections;
        $this->limiter = $limiter;
        $this->attribution = $attribution;
        $this->recommendations = $recommendations;
        $this->products = $products;
        $this->objected = $objected;
    }

    /**
     * @param string $hookName the display hook that renders the widget; '' in a template
     */
    public function container(\Context $context, string $hookName = ''): string
    {
        if ($this->connections->sending((int) $context->shop->id) === null) {
            return '';
        }
        $context->smarty->assign(
            'smaily_connect_contained',
            stripos($hookName, self::HOME_HOOKS) === 0 && $context->smarty->templateExists(self::THEME_TITLE_TEMPLATE)
        );

        return (string) $context->smarty->fetch(self::CONTAINER_TEMPLATE);
    }

    /**
     * @param string $fetchSite the request's `Sec-Fetch-Site` header
     *
     * @return array{int, string} the HTTP status and the HTML
     */
    public function answer(\Context $context, string $fetchSite, Cookies $cookies): array
    {
        $idShop = (int) $context->shop->id;
        $connection = $this->connections->sending($idShop);
        if ($connection === null) {
            return [404, ''];
        }
        if (!$this->limiter->allow('recommendations', self::LIMIT_PER_MINUTE)) {
            return [429, ''];
        }
        if ($fetchSite === 'cross-site' || $fetchSite === 'same-site') {
            return [200, ''];
        }

        $customer = $context->customer;
        if ($customer instanceof \Customer && $customer->isLogged()) {
            if (($this->objected)($customer)) {
                return [200, ''];
            }
            $by = Recommendations::BY_CUSTOMER;
            $identifier = (string) (int) $customer->id;
        } else {
            $by = Recommendations::BY_VISITOR_TOKEN;
            $identifier = (string) $this->attribution->read($cookies, $connection)['visitor_token'];
        }
        if ($identifier === '') {
            return [200, ''];
        }

        $products = $this->products->forSlots(
            $this->recommendations->slots($connection, $idShop, $by, $identifier),
            new ClientConfig($connection),
            $context
        );
        if ($products === []) {
            return [200, ''];
        }

        $context->smarty->assign([
            'smaily_connect_products' => $products,
            'smaily_connect_theme_title' => $context->smarty->templateExists(self::THEME_TITLE_TEMPLATE),
        ]);

        return [200, (string) $context->smarty->fetch(self::PRODUCTS_TEMPLATE)];
    }
}
