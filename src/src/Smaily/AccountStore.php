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
 * The rows of the account table: one Smaily account per shop and language, its password encrypted.
 *
 * A password that does not decrypt (for example after a move to a server with another cookie key)
 * reads as '', so the account has no credentials and the admin asks to reconnect it.
 */
final class AccountStore
{
    private const TABLE = 'smaily_connect_account';

    private Crypto $crypto;

    public function __construct(Crypto $crypto)
    {
        $this->crypto = $crypto;
    }

    public function find(int $idShop, string $language = ''): ?Account
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `subdomain`, `username`, `password_enc` FROM `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' WHERE `id_shop` = ' . $idShop . ' AND `language` = \'' . pSQL($language) . '\'',
            false
        );
        if (!is_array($row)) {
            return null;
        }

        return new Account(
            $idShop,
            $language,
            (string) $row['subdomain'],
            (string) $row['username'],
            $this->crypto->decrypt((string) $row['password_enc']) ?? ''
        );
    }

    /**
     * Writes the account of its shop and language, replacing the one stored before.
     */
    public function save(Account $account): bool
    {
        return \Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' (`id_shop`, `language`, `subdomain`, `username`, `password_enc`, `date_add`, `date_upd`)'
            . ' VALUES (' . $account->idShop()
            . ', \'' . pSQL($account->language()) . '\''
            . ', \'' . pSQL($account->subdomain()) . '\''
            . ', \'' . pSQL($account->username()) . '\''
            . ', \'' . pSQL($this->crypto->encrypt($account->password())) . '\''
            . ', NOW(), NOW())'
            . ' ON DUPLICATE KEY UPDATE `subdomain` = VALUES(`subdomain`), `username` = VALUES(`username`),'
            . ' `password_enc` = VALUES(`password_enc`), `date_upd` = VALUES(`date_upd`)'
        );
    }
}
