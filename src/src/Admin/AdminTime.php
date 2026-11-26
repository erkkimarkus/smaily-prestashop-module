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
 * A time on the Dashboard, in the Log and on the import cards, as the employee's language writes
 * a date and a time (PrestaShop's `date_format_full` of the language, which Tools::displayDate()
 * uses too), in the shop's time zone: the siblings show the admin's locale (Magento
 * IntlDateFormatter::MEDIUM).
 */
final class AdminTime
{
    /** The format where a language has none. */
    public const FALLBACK = 'Y-m-d H:i:s';

    private string $format;

    public function __construct(string $format = self::FALLBACK)
    {
        $this->format = $format === '' ? self::FALLBACK : $format;
    }

    /**
     * The format of the language of the request's context, the employee's on an admin page.
     */
    public static function forEmployee(): self
    {
        $language = \Context::getContext()->language;

        return new self(is_object($language) ? (string) $language->date_format_full : '');
    }

    /**
     * A Unix time.
     */
    public function of(int $time): string
    {
        return date($this->format, $time);
    }

    /**
     * A stored DATETIME, which is UTC.
     */
    public function ofUtc(string $utc): string
    {
        return $this->of((int) strtotime($utc . ' UTC'));
    }
}
