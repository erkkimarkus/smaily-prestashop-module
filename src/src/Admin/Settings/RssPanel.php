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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Config\RssSettings;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The RSS panel (Settings > RSS, Magento's panel/rss.phtml with its config/rss-builder.phtml): the
 * product feed URL builder's defaults, read and saved by this one class, and the feed's address
 * without parameters, which views/js/admin/settings.js completes from the picked values.
 *
 * The feed is installation-wide, as in Magento: the builder saves the global defaults whatever
 * shop PrestaShop's shop selector names. The feed's URL and parameters stay those of 2.x
 * (`category_id`, `limit`, `sort_by`, `sort_order`), which merchants' Smaily templates hold.
 */
final class RssPanel
{
    private RssSettings $settings;

    private TranslatorInterface $translator;

    public function __construct(RssSettings $settings, TranslatorInterface $translator)
    {
        $this->settings = $settings;
        $this->translator = $translator;
    }

    /**
     * @return array{defaults: array{category: int, limit: int, sort_by: string, sort_order: string}, feed: array{limit: int, sort_by: string, sort_order: string, max_limit: int}, base_url: string, sort_by: list<string>, sort_orders: list<string>}
     */
    public function values(): array
    {
        $link = \Context::getContext()->link ?? new \Link();

        return [
            'defaults' => $this->settings->defaults(),
            // The feed's own defaults, which a URL leaves out, and its largest limit.
            'feed' => [
                'limit' => RssSettings::DEFAULT_LIMIT,
                'sort_by' => RssSettings::DEFAULT_SORT_BY,
                'sort_order' => RssSettings::DEFAULT_SORT_ORDER,
                'max_limit' => RssSettings::MAX_LIMIT,
            ],
            'base_url' => self::feedUrl($link),
            'sort_by' => RssSettings::SORT_BY_VALUES,
            'sort_orders' => RssSettings::SORT_ORDERS,
        ];
    }

    /**
     * Checks and saves the posted `category` (empty: all products), `limit`, `sort_by` and
     * `sort_order`. Nothing is written while any field is at fault.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{field: string, message: string}>
     */
    public function save(array $data): array
    {
        $category = trim((string) ($data['category'] ?? ''));
        $limit = trim((string) ($data['limit'] ?? ''));
        $sortBy = (string) ($data['sort_by'] ?? '');
        $sortOrder = (string) ($data['sort_order'] ?? '');

        $errors = [];
        if ($category !== '' && (!ctype_digit($category) || (int) $category < 1)) {
            $errors[] = ['field' => 'category', 'message' => $this->trans('Enter a category ID, or leave it empty for all products.')];
        }
        if (!ctype_digit($limit) || (int) $limit < 1 || (int) $limit > RssSettings::MAX_LIMIT) {
            $errors[] = ['field' => 'limit', 'message' => $this->trans('Enter a number from 1 to %max%.', ['%max%' => RssSettings::MAX_LIMIT])];
        }
        if (!in_array($sortBy, RssSettings::SORT_BY_VALUES, true)) {
            $errors[] = ['field' => 'sort_by', 'message' => $this->trans('Pick a sort field.')];
        }
        if (!in_array($sortOrder, RssSettings::SORT_ORDERS, true)) {
            $errors[] = ['field' => 'sort_order', 'message' => $this->trans('Pick a sort order.')];
        }
        if ($errors !== []) {
            return $errors;
        }

        $this->settings->save((int) $category, (int) $limit, $sortBy, $sortOrder);

        return [];
    }

    /**
     * The feed's address without a language, as the cron line's (BackgroundTasks::tickUrl()): the
     * shop's base address and the dispatcher's module route, which Link::getModuleLink() joins
     * with the language prefix of the employee who opens the page. The feed then answers in the
     * store's default language (on 8.2.8 also in one that the reader's Accept-Language names);
     * an address with a prefix keeps answering in its language (facts file 6.10). With
     * friendly URLs off the route carries `id_lang`, so the default language is asked for.
     */
    private static function feedUrl(\Link $link): string
    {
        return $link->getBaseLink(null, true) . \Dispatcher::getInstance()->createUrl(
            'module',
            (int) \Configuration::get('PS_LANG_DEFAULT'),
            ['module' => 'smailyforprestashop', 'controller' => 'SmailyRssFeed'],
            (bool) \Configuration::get('PS_REWRITING_SETTINGS')
        );
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, BaseController::DOMAIN);
    }
}
