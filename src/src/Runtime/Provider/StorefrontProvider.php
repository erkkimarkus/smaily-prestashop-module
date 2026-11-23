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

namespace PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\VisitorToken as VisitorTokenSource;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingConsent;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\ActiveConnection;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Attribution;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\CartAttribution;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Cookies;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Landing;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\OrderAttribution;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\PageScripts;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\RateLimiter;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Recommendations;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\RecommendationsBlock;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\RecommendedProducts;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Relay;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Store;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\VisitorToken;

/**
 * Registers the services and hook listeners of storefront tracking, attribution and recommendations.
 *
 * - Every storefront page of a shop with a connection gets the storefront scripts
 *   (`actionFrontControllerSetMedia`, Storefront\PageScripts). Whether they track is the browser's
 *   consent rule (`views/js/front/consent.js`).
 * - A page that is built for a visitor with a cart keeps the attribution cookies of its request
 *   with that cart (`actionFrontControllerSetMedia`, Storefront\CartAttribution).
 * - An order stores the attribution cookies of its request, or what its cart kept when the
 *   request has none (`actionValidateOrder`, Storefront\OrderAttribution).
 * - A login (`actionAuthentication`), a registration and the customer step of a guest checkout
 *   (`actionCustomerAccountAdd`) give the shopper a visitor token where the merchant confirmed
 *   the cookie banner (Storefront\VisitorToken), which also answers the identity merge's seam
 *   (Engine\Capture\VisitorToken).
 * - The job `storefront_cache` deletes the expired files of the storefront's cache once a day.
 *
 * One seam lets another area answer without this one knowing it. PRODUCT_KEY is a list of
 * listeners (`Services::listen()`) that give the engine's `sku` of a product on a connection, for
 * the browse events: a listener takes `connection` (Engine\Connection), `id_product` and
 * `id_product_attribute` (0 for none) and answers the key or null. Without a listener a browse
 * event carries no `sku`.
 */
final class StorefrontProvider implements ProviderInterface
{
    public const PRODUCT_KEY = 'storefront.product_key';

    public const CACHE_JOB = 'storefront_cache';

    public function register(Services $services): void
    {
        // The cookies of a shopper's own storefront request; none in the back office, a command or
        // a cron URL's tick.
        $cookies = static function (): ?Cookies {
            $context = \Context::getContext();

            return $context->controller instanceof \FrontController ? Cookies::ofRequest($context) : null;
        };

        $services->set(Store::class, fn (): Store => new Store(_PS_CACHE_DIR_ . 'smaily_connect', 'time'));
        $services->set(RateLimiter::class, fn (Services $services): RateLimiter => new RateLimiter(
            $services->get(Store::class),
            static fn (): string => (string) \Tools::getRemoteAddr(),
            'time',
            _COOKIE_KEY_
        ));
        $services->set(ActiveConnection::class, fn (Services $services): ActiveConnection => new ActiveConnection([$services->get(Connections::class), 'forShop']));
        $services->set(Attribution::class, fn (Services $services): Attribution => new Attribution([$services->get(EngineSettings::class), 'consentConfirmed']));
        $services->set(VisitorToken::class, fn (Services $services): VisitorToken => new VisitorToken(
            $services->get(ActiveConnection::class),
            $services->get(Attribution::class),
            $cookies
        ));
        // The identity merge asks for the token under its own seam's name.
        $services->set(VisitorTokenSource::class, fn (Services $services): VisitorToken => $services->get(VisitorToken::class));
        $services->set(PageScripts::class, fn (Services $services): PageScripts => new PageScripts(
            $services->get(ActiveConnection::class),
            [$services->get(EngineSettings::class), 'consentConfirmed']
        ));
        $services->set(Landing::class, fn (Services $services): Landing => new Landing(
            $services->get(ActiveConnection::class),
            $services->get(RateLimiter::class)
        ));
        $services->set(CartAttribution::class, fn (Services $services): CartAttribution => new CartAttribution(
            $services->get(ActiveConnection::class),
            $services->get(Attribution::class),
            $cookies,
            'time'
        ));
        $services->set(OrderAttribution::class, fn (Services $services): OrderAttribution => new OrderAttribution(
            $services->get(ActiveConnection::class),
            $services->get(Attribution::class),
            $services->get(CartAttribution::class),
            $cookies
        ));
        $services->set(Relay::class, fn (Services $services): Relay => new Relay(
            $services->get(ActiveConnection::class),
            $services->get(RateLimiter::class),
            $services->get(Attribution::class),
            $services->get(Client::class),
            static function (Connection $connection, int $idProduct, int $idProductAttribute) use ($services): ?string {
                foreach ($services->listeners(self::PRODUCT_KEY) as $listener) {
                    $key = $listener(['connection' => $connection, 'id_product' => $idProduct, 'id_product_attribute' => $idProductAttribute]);
                    if (is_string($key) && $key !== '') {
                        return $key;
                    }
                }

                return null;
            },
            'time'
        ));
        $services->set(Recommendations::class, fn (Services $services): Recommendations => new Recommendations(
            $services->get(Client::class),
            $services->get(Store::class),
            _COOKIE_KEY_
        ));
        $services->set(RecommendationsBlock::class, fn (Services $services): RecommendationsBlock => new RecommendationsBlock(
            $services->get(ActiveConnection::class),
            $services->get(RateLimiter::class),
            $services->get(Attribution::class),
            $services->get(Recommendations::class),
            new RecommendedProducts(),
            static fn (\Customer $customer): bool => !$services->get(ProfilingConsent::class)->isAllowed((string) $customer->email)
        ));

        $services->listen('actionFrontControllerSetMedia', static function () use ($services): void {
            $services->get(PageScripts::class)->add(\Context::getContext());
        });
        // A listener of its own: one that fails does not hold the page's scripts back (Hook\Dispatcher).
        $services->listen('actionFrontControllerSetMedia', static function () use ($services): void {
            $services->get(CartAttribution::class)->capture(\Context::getContext());
        });
        $services->listen('actionValidateOrder', static function (array $params) use ($services): void {
            $services->get(OrderAttribution::class)->orderValidated($params);
        });
        $issueToken = static function (array $params) use ($services): void {
            $customer = $params['customer'] ?? $params['newCustomer'] ?? null;
            $shop = \Context::getContext()->shop;
            if ($customer instanceof \Customer && (int) $customer->id > 0 && $shop instanceof \Shop) {
                $services->get(VisitorToken::class)->token((int) $shop->id, true);
            }
        };
        $services->listen('actionAuthentication', $issueToken);
        $services->listen('actionCustomerAccountAdd', $issueToken);
        $services->listen(SchedulerProvider::JOBS, static fn (): Job => new Job(self::CACHE_JOB, 86400, 5, static function (Deadline $deadline) use ($services): void {
            $services->get(Store::class)->prune($deadline);
        }));
    }
}
