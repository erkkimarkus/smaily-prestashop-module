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

namespace PrestaShop\Module\SmailyForPrestaShop\Privacy;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;

/**
 * The outcome of a queue row whose Campaign Intelligence call failed. A refusal that the same
 * request meets again (an HTTP 3xx or 4xx other than 429, or a request that the module itself
 * does not send) parks the row at once; no answer, a 429 and a 5xx take the retry ladder.
 *
 * The stored reason is the module's own sentence with the HTTP status and the error code (the
 * engine's plain code, or the module's own): never the engine's message, which can name a shopper.
 */
final class EngineFailure
{
    /** The skip reason of a row whose connection no longer exists. */
    public const SKIPPED_NO_CONNECTION = 'no_connection';

    public static function of(EngineException $error): Failure
    {
        if (!$error instanceof EngineRequestException) {
            return Failure::temporary($error->getMessage());
        }

        return $error->httpStatus() > 0
            ? Failure::refused($error->httpStatus(), $error->getMessage())
            : Failure::permanent($error->getMessage());
    }
}
