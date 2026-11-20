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

namespace PrestaShop\Module\SmailyForPrestaShop\Smaily;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The one place that encrypts and decrypts the module's secrets: the Smaily API password and the
 * engine API key.
 *
 * It uses PhpEncryptionEngine, the engine behind PrestaShop's PhpEncryption (defuse/php-encryption),
 * on PrestaShop 8 and 9. PhpEncryption itself keeps one engine for the whole process, so a
 * PhpEncryption built with another key would replace the key of the shop's own cookie encryption.
 */
final class Crypto
{
    private string $key;

    /**
     * @param string $key a key in defuse/php-encryption's ASCII form; the shop's is _NEW_COOKIE_KEY_
     */
    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        return (new \PhpEncryptionEngine($this->key))->encrypt($plaintext);
    }

    /**
     * @return string|null the plaintext, or null when the value does not decrypt with this key
     */
    public function decrypt(string $ciphertext): ?string
    {
        try {
            $plaintext = (new \PhpEncryptionEngine($this->key))->decrypt($ciphertext);
        } catch (\Throwable $exception) {
            return null;
        }

        return is_string($plaintext) ? $plaintext : null;
    }
}
