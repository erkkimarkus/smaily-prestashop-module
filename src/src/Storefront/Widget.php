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

use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

/**
 * The module's widget (PrestaShop's WidgetInterface on the main class): the place of the
 * recommendations block. The merchant puts it on a display hook in Design > Positions, or into a
 * template with `{widget name='smailyforprestashop'}`; the module registers it on no hook.
 *
 * It renders RecommendationsBlock::container(), and nothing while the stored schema version differs
 * from the code's. A failure renders nothing and never breaks the page.
 */
final class Widget
{
    /**
     * @param mixed $hookName the display hook that renders the widget; null in a template
     */
    public static function render($hookName = null): string
    {
        if (!Schema::isCurrent()) {
            return '';
        }
        try {
            return Services::instance()->get(RecommendationsBlock::class)->container(\Context::getContext(), is_string($hookName) ? $hookName : '');
        } catch (\Throwable $error) {
            Log::failure('the recommendations widget', $error);

            return '';
        }
    }

    /**
     * The widget's template variables: none, the container is the same for every visitor.
     *
     * @return array<string, mixed>
     */
    public static function variables(): array
    {
        return [];
    }
}
