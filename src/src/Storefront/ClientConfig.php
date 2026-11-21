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
 * The names of the four cookies and the three link parameters, and the cookies' lifetimes, of one
 * connection: what the setup exchange's `config` names, else the contract's defaults ("URL
 * namespace and cookie names"). A name that is not a plain token and a lifetime that is not 1 to
 * 3650 days count as not named.
 */
final class ClientConfig
{
    /** The visitor token: the `smaily_vt` value of an email link, or a store-created token. */
    public const VISITOR = 'visitor';

    /** The anonymous session id of the browse events. */
    public const SESSION = 'session';

    /** The recommendation that the last link named. */
    public const REC_ID = 'recId';

    /** The context of that link. */
    public const CONTEXT = 'context';

    /** Per cookie: the config key of its name, the default name, the config key of its lifetime, the default days. */
    private const COOKIES = [
        self::VISITOR => ['tracking_cookie_name', 'smaily_rec_uid', 'cookie_ttl_days', 365],
        self::SESSION => ['session_cookie_name', 'smaily_anon_sid', 'session_ttl_days', 30],
        self::REC_ID => ['rec_id_cookie_name', 'smaily_rec_id', 'rec_id_ttl_days', 30],
        self::CONTEXT => ['context_cookie_name', 'smaily_rec_ctx', 'context_ttl_days', 30],
    ];

    /** Per link parameter: the config key of its name, the default name. */
    private const PARAMETERS = [
        self::VISITOR => ['url_param_visitor_token', 'smaily_vt'],
        self::REC_ID => ['url_param_rec_id', 'smaily_rec'],
        self::CONTEXT => ['url_param_context', 'smaily_ctx'],
    ];

    private const NAME = '/^[A-Za-z0-9_-]{1,64}$/D';

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(Connection $connection)
    {
        $this->config = $connection->config();
    }

    /**
     * @param string $cookie one of VISITOR, SESSION, REC_ID, CONTEXT
     */
    public function cookieName(string $cookie): string
    {
        return $this->name(self::COOKIES[$cookie][0], self::COOKIES[$cookie][1]);
    }

    /**
     * @param string $cookie one of VISITOR, SESSION, REC_ID, CONTEXT
     */
    public function days(string $cookie): int
    {
        $days = $this->config[self::COOKIES[$cookie][2]] ?? null;

        return is_int($days) && $days >= 1 && $days <= 3650 ? $days : self::COOKIES[$cookie][3];
    }

    /**
     * @param string $parameter one of VISITOR, REC_ID, CONTEXT
     */
    public function parameter(string $parameter): string
    {
        return $this->name(self::PARAMETERS[$parameter][0], self::PARAMETERS[$parameter][1]);
    }

    private function name(string $key, string $default): string
    {
        $name = $this->config[$key] ?? null;

        return is_string($name) && preg_match(self::NAME, $name) === 1 ? $name : $default;
    }
}
