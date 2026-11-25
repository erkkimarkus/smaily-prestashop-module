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

namespace PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Smaily answered with a code other than 101 in its {"code", "message"} envelope.
 *
 * Known codes: 101 success, 203 invalid data, 206 email not found, 227 a paid package is required.
 */
class ApiException extends SmailyClientException
{
    public const CODE_SUCCESS = 101;
    public const CODE_INVALID_DATA = 203;
    public const CODE_EMAIL_NOT_FOUND = 206;

    /** The message, read back by its wording in the Log (Log\FailureMessage::MESSAGES). */
    public const MESSAGE = 'Smaily API returned code %code%';

    /**
     * The codes that a retry of the same request can never change: 203, the same data is refused
     * again. Every other code may pass later.
     */
    private const PERMANENT_CODES = [self::CODE_INVALID_DATA];

    public function __construct(int $smailyCode)
    {
        parent::__construct(strtr(self::MESSAGE, ['%code%' => (string) $smailyCode]), $smailyCode);
    }

    public function getSmailyCode(): int
    {
        return $this->getCode();
    }

    /**
     * Whether Smaily refused the request itself, so a retry cannot pass.
     */
    public function isPermanent(): bool
    {
        return in_array($this->getCode(), self::PERMANENT_CODES, true);
    }
}
