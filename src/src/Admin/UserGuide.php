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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The merchant guide: the one place its address is set. Every admin link to the guide is built
 * by link(); a template gets the link from its controller.
 *
 * The guide is the bilingual page docs/site/index.html of the repository, published at
 * https://smaily.com/connect-presta/, where the sibling connectors' guides are. A section below is
 * an id on that page: tests/Unit/Admin/UserGuideTest.php and tests/js/user-guide-site.test.js
 * check that it exists.
 */
final class UserGuide
{
    public const URL = 'https://smaily.com/connect-presta/';

    /** Section "Recommendations on your store's pages": where the recommendations block is placed. */
    public const SECTION_STOREFRONT_RECOMMENDATIONS = 'set-storefront-recs';

    /**
     * The address of a section of the guide.
     *
     * @param string $section one of the SECTION_ constants
     */
    public static function link(string $section): string
    {
        return self::URL . '#' . $section;
    }
}
