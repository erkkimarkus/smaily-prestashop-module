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

namespace PrestaShop\Module\SmailyForPrestaShop\Log;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Keeps secrets out of what the queue stores of a request and its answer, and out of what the Log's
 * Details show: a value under a key that looks like a secret (password, token, API key, …) becomes
 * "[redacted]", at any depth. The queue redacts before it stores (EventQueue::recordExchange());
 * Details redact again what they show, the queued payload included.
 *
 * Contact data stays as it is, as in the Magento connector's Log: the Log is an admin's debugging
 * tool behind its own permission. Text that is not JSON shows as it is.
 */
final class PayloadRedactor
{
    public const REDACTED = '[redacted]';

    private const SECRET_KEY_PATTERN = '/pass|secret|token|api[_-]?key|authorization|signature|credential/i';

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    public static function redact(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                $result[$key] = self::REDACTED;
            } else {
                $result[$key] = is_array($value) ? self::redact($value) : $value;
            }
        }

        return $result;
    }

    /**
     * A stored payload, request or answer as Details show it: JSON redacted and laid out on
     * lines; any other text as stored; '' for nothing.
     */
    public static function forDisplay(?string $stored): string
    {
        if ($stored === null || trim($stored) === '') {
            return '';
        }
        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return $stored;
        }
        $encoded = json_encode(self::redact($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? self::REDACTED : $encoded;
    }
}
