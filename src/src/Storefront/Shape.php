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
 * The shapes in which the attribution values are accepted (contract "Cookie names", §5), as the
 * Magento connector's RecId and AttributionShape. An off-shape value counts as absent: it is
 * dropped, never cut short.
 *
 * - A recommendation id is a UUID, 8-4-4-4-12 hexadecimal digits in either case: the engine
 *   refuses a whole order over a malformed one, so only a well-formed id is stored or sent.
 * - A visitor token is an engine-issued `vt_` token, at most 64 characters in all (the width of
 *   the attribution table's column), or a store-created `vs_` token of exactly 22 alphanumerics.
 * - A context and a session id are 1 to 64 characters of `[A-Za-z0-9._-]`.
 *
 * The D modifier keeps `$` from accepting a trailing newline.
 */
final class Shape
{
    private const REC_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    private const ENGINE_TOKEN = '/^vt_[A-Za-z0-9]{1,61}$/D';

    private const STORE_TOKEN = '/^vs_[A-Za-z0-9]{22}$/D';

    private const CONTEXT = '/^[A-Za-z0-9._-]{1,64}$/D';

    public static function isRecId(string $value): bool
    {
        return preg_match(self::REC_ID, $value) === 1;
    }

    /**
     * An engine-issued `vt_` token: the only form in which a link's visitor token is taken. A link
     * of the engine never carries a `vs_` token, so one in a link is a value its sender chose.
     */
    public static function isEngineVisitorToken(string $value): bool
    {
        return preg_match(self::ENGINE_TOKEN, $value) === 1;
    }

    /**
     * A store-created `vs_` token: it reaches the engine only with the shopper's marketing consent
     * (Attribution), while an engine `vt_` token from an email link is attribution.
     */
    public static function isStoreVisitorToken(string $value): bool
    {
        return preg_match(self::STORE_TOKEN, $value) === 1;
    }

    public static function isVisitorToken(string $value): bool
    {
        return self::isEngineVisitorToken($value) || self::isStoreVisitorToken($value);
    }

    public static function isContext(string $value): bool
    {
        return preg_match(self::CONTEXT, $value) === 1;
    }

    public static function isSessionId(string $value): bool
    {
        return preg_match(self::CONTEXT, $value) === 1;
    }
}
