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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Crypto;

/**
 * The rows of the connection table: the Campaign Intelligence connections of the installation, one
 * per separately set-up shop (ARCHITECTURE 5.3). One store has the installation-wide connection
 * (id_shop 0), which serves every shop; in separate stores each shop has its own. A change of the
 * layout carries one connection over and ends the others (LayoutConnections), so no row of the
 * other layout stays.
 *
 * The API key is stored encrypted, as the Smaily password is. A key that does not decrypt reads as
 * '', and the connection then needs a new setup link.
 *
 * A row keeps its id when a new setup link replaces its connection. Its encrypted key names the
 * connection it holds now, so a write of the settings and a removal touch the row only while it
 * still holds the connection that was read: a health check that started before a reconnect does
 * not write the old engine store's state over the new one.
 */
final class Connections
{
    private const TABLE = 'smaily_connect_connection';

    private Crypto $crypto;

    private StoreLayout $layout;

    public function __construct(Crypto $crypto, StoreLayout $layout)
    {
        $this->crypto = $crypto;
        $this->layout = $layout;
    }

    /**
     * The id_shop of the connection that serves a shop: the shop itself in separate stores, else
     * 0, the installation-wide connection (as Scope\Scope::setupShopId()).
     */
    public function keyOf(int $idShop): int
    {
        return $this->layout->separateStores() ? $idShop : 0;
    }

    /**
     * The connection that serves a shop, or null when the shop has none.
     */
    public function forShop(int $idShop): ?Connection
    {
        return $this->find($this->keyOf($idShop));
    }

    /**
     * The connection stored for exactly this id_shop (0 = the installation-wide one).
     */
    public function find(int $idShop): ?Connection
    {
        $row = Sql::row(self::select() . ' WHERE `id_shop` = ' . $idShop);

        return $row === null ? null : $this->connection($row);
    }

    /**
     * Every stored connection, also one that the shop layout no longer reads: each holds a key
     * that the engine still accepts.
     *
     * @return list<Connection>
     */
    public function all(): array
    {
        return array_map([$this, 'connection'], Sql::rows(self::select() . ' ORDER BY `id`'));
    }

    /**
     * Stores the connection of an id_shop, in place of the one stored before.
     *
     * @param array<string, mixed> $settings what Connection::settings() returns
     */
    public function save(int $idShop, string $smailySubdomain, string $tenantId, string $apiKey, array $settings): Connection
    {
        // The second argument of pSQL() keeps every character (without it PrestaShop strips tags):
        // the row holds the engine's values as it sent them, the connection prefix among them.
        Sql::write(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' (`id_shop`, `smaily_subdomain`, `tenant_id`, `api_key_enc`, `settings`, `date_add`, `date_upd`)'
            . ' VALUES (' . $idShop
            . ', \'' . pSQL($smailySubdomain, true) . '\''
            . ', \'' . pSQL($tenantId, true) . '\''
            . ', \'' . pSQL($this->crypto->encrypt($apiKey)) . '\''
            . ', \'' . pSQL(self::encode($settings), true) . '\''
            . ', NOW(), NOW())'
            . ' ON DUPLICATE KEY UPDATE `smaily_subdomain` = VALUES(`smaily_subdomain`), `tenant_id` = VALUES(`tenant_id`),'
            . ' `api_key_enc` = VALUES(`api_key_enc`), `settings` = VALUES(`settings`), `date_upd` = VALUES(`date_upd`)'
        );
        $connection = $this->find($idShop);
        if ($connection === null) {
            throw new \LogicException('The connection that was stored cannot be read.');
        }

        return $connection;
    }

    /**
     * Writes the settings of a connection, while its row still holds that connection.
     *
     * @param array<string, mixed> $settings
     */
    public function saveSettings(Connection $connection, array $settings): void
    {
        Sql::write(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' SET `settings` = \'' . pSQL(self::encode($settings), true) . '\', `date_upd` = NOW()'
            . ' WHERE ' . self::same($connection)
        );
    }

    /**
     * Gives a connection to another id_shop, while its row still holds that connection: a change
     * of the shop layout carries it over (LayoutConnections). The row keeps its id, so its queue
     * rows and imports stay its own. No row may hold that id_shop.
     */
    public function move(Connection $connection, int $idShop): void
    {
        Sql::write(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' SET `id_shop` = ' . $idShop . ', `date_upd` = NOW()'
            . ' WHERE ' . self::same($connection)
        );
    }

    /**
     * Removes a connection with its key, while its row still holds that connection.
     */
    public function delete(Connection $connection): void
    {
        Sql::delete(self::TABLE, self::same($connection));
    }

    private static function select(): string
    {
        return 'SELECT `id`, `id_shop`, `smaily_subdomain`, `tenant_id`, `api_key_enc`, `settings`'
            . ' FROM `' . _DB_PREFIX_ . self::TABLE . '`';
    }

    private static function same(Connection $connection): string
    {
        return '`id` = ' . $connection->id() . ' AND `api_key_enc` = \'' . pSQL($connection->storedKey()) . '\'';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function encode(array $settings): string
    {
        return json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string|null> $row
     */
    private function connection(array $row): Connection
    {
        $settings = json_decode((string) $row['settings'], true);

        return new Connection(
            (int) $row['id'],
            (int) $row['id_shop'],
            (string) $row['smaily_subdomain'],
            (string) $row['tenant_id'],
            $this->crypto->decrypt((string) $row['api_key_enc']) ?? '',
            is_array($settings) ? $settings : [],
            (string) $row['api_key_enc']
        );
    }
}
