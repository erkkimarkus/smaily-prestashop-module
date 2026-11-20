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

namespace PrestaShop\Module\SmailyForPrestaShop\Install;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

/**
 * Uninstall and Reset remove all module data: every key in KEYS at every scope, every table of
 * Schema and the legacy `smaily_cart`, and the 2.x override file when 2.x shipped it.
 *
 * Before anything is removed, the listeners registered for BEFORE_REMOVAL run (the engine
 * disconnect and the deactivation report belong there). A listener that fails is logged by its error
 * class and stops nothing.
 */
final class Uninstaller
{
    /**
     * Register with `$services->listen(Uninstaller::BEFORE_REMOVAL, $listener)` in a provider; the
     * listener gets an empty array and runs while every key and table still exists.
     */
    public const BEFORE_REMOVAL = 'smailyConnectBeforeUninstall';

    /**
     * Every configuration key of the module, the legacy ones included. Explicit: the legacy prefix
     * SMAILY_ also matches the 3.0.0 prefix, so no key is ever deleted by pattern.
     */
    public const KEYS = [
        'SMAILY_CONNECT_SCHEMA_VERSION',
        'SMAILY_CONNECT_SETUP_STATE',
        'SMAILY_CONNECT_MIGRATION',
        'SMAILY_CONNECT_NOTICES',
        'SMAILY_CONNECT_SEPARATE_STORES',
        'SMAILY_CONNECT_LANGUAGE_MODE',
        'SMAILY_CONNECT_FALLBACK_LANGUAGE',
        'SMAILY_CONNECT_CONTACTS_ENABLED',
        'SMAILY_CONNECT_CONTACTS_MODE',
        'SMAILY_CONNECT_CONTACTS_GUESTS',
        'SMAILY_CONNECT_CONTACTS_FIELDS',
        'SMAILY_CONNECT_CONTACTS_CONFIRMED',
        'SMAILY_CONNECT_CHECKOUT_CHECKBOX',
        'SMAILY_CONNECT_WELCOME_ENABLED',
        'SMAILY_CONNECT_FIRST_ORDER_ENABLED',
        'SMAILY_CONNECT_CART_ENABLED',
        'SMAILY_CONNECT_CART_DELAY_MINUTES',
        'SMAILY_CONNECT_CART_FIELDS',
        'SMAILY_CONNECT_RSS_CATEGORY',
        'SMAILY_CONNECT_RSS_LIMIT',
        'SMAILY_CONNECT_RSS_SORT_BY',
        'SMAILY_CONNECT_RSS_SORT_ORDER',
        'SMAILY_CONNECT_TICK_TOKEN',
        'SMAILY_CONNECT_TICK_STATE',
        'SMAILY_CONNECT_FALLBACK_TICK',
        'SMAILY_CONNECT_CONSENT_CONFIRMED',
        'SMAILY_CONNECT_HEALTH',
        'SMAILY_CONNECT_REPORT_STATE',
        // Legacy keys: the two cron tokens stay after the migration; the others stay while it is pending.
        'SMAILY_SUBDOMAIN',
        'SMAILY_USERNAME',
        'SMAILY_PASSWORD',
        'SMAILY_ENABLE_CUSTOMER_SYNC',
        'SMAILY_ENABLE_CRON',
        'SMAILY_CUSTOMER_CRON_TOKEN',
        'SMAILY_SYNCRONIZE_ADDITIONAL',
        'SMAILY_OPTIN_ENABLED',
        'SMAILY_OPTIN_AUTORESPONDER',
        'SMAILY_ENABLE_ABANDONED_CART',
        'SMAILY_CART_CRON_TOKEN',
        'SMAILY_CART_AUTORESPONDER',
        'SMAILY_ABANDONED_CART_TIME',
        'SMAILY_CART_SYNCRONIZE_ADDITIONAL',
        'SMAILY_RSS_CATEGORY_ID',
        'SMAILY_RSS_LIMIT',
        'SMAILY_RSS_SORT_BY',
        'SMAILY_RSS_SORT_ORDER',
    ];

    /** The 2.x and 1.6.x table of reminded carts. */
    public const LEGACY_TABLE = 'smaily_cart';

    private Services $services;

    private LegacyOverride $override;

    public function __construct(Services $services, LegacyOverride $override)
    {
        $this->services = $services;
        $this->override = $override;
    }

    public function uninstall(): bool
    {
        foreach ($this->services->listeners(self::BEFORE_REMOVAL) as $listener) {
            try {
                $listener([]);
            } catch (\Throwable $error) {
                \PrestaShopLogger::addLog(
                    sprintf('Smaily Connect: an uninstall listener failed with %s', get_class($error)),
                    2
                );
            }
        }

        $removed = true;
        try {
            $this->override->removeIfShipped();
        } catch (\Throwable $error) {
            $removed = false;
        }
        foreach (self::KEYS as $key) {
            $removed = \Configuration::deleteByName($key) && $removed;
        }

        return Schema::drop()
            && \Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::LEGACY_TABLE . '`')
            && $removed;
    }
}
