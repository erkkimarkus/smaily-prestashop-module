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

namespace PrestaShop\Module\SmailyForPrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The admin notices that the legacy migration left: each a stable code and its parameters, stored
 * as JSON at the global scope until the merchant dismisses it. The admin owns the wording. The codes
 * are the NOTICE_* constants of Migration\MigrationPlan and the ones below.
 */
final class MigrationNotices extends Settings
{
    public const KEY = self::PREFIX . 'NOTICES';

    /** An override file of ps_emailsubscription that 2.x did not ship stays in place; params: path. */
    public const OVERRIDE_LEFT_IN_PLACE = 'override_left_in_place';

    /**
     * Stores a notice unless the same code with the same parameters is stored already.
     *
     * @param array<string, int|string> $params
     */
    public function add(string $code, array $params = []): void
    {
        $notices = $this->all();
        $id = self::id($code, $params);
        foreach ($notices as $notice) {
            if ($notice['id'] === $id) {
                return;
            }
        }
        $notices[] = ['id' => $id, 'code' => $code, 'params' => $params];
        $this->store($notices);
    }

    /**
     * @return list<array{id: string, code: string, params: array<string, int|string>}> in the order they were added
     */
    public function all(): array
    {
        $notices = json_decode((string) $this->get(self::KEY), true);

        return is_array($notices) ? array_values($notices) : [];
    }

    public function dismiss(string $id): void
    {
        $this->store(array_values(array_filter(
            $this->all(),
            static fn (array $notice): bool => $notice['id'] !== $id
        )));
    }

    /**
     * @param list<array{id: string, code: string, params: array<string, int|string>}> $notices
     */
    private function store(array $notices): void
    {
        if (!$this->set(self::KEY, (string) json_encode($notices))) {
            throw new \RuntimeException('The admin notices could not be stored.');
        }
    }

    /**
     * @param array<string, int|string> $params
     */
    private static function id(string $code, array $params): string
    {
        return substr(sha1($code . "\0" . json_encode($params)), 0, 12);
    }
}
