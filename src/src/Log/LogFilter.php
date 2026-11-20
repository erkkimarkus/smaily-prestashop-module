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
 * The filters of the Log's list, as the page's address carries them: `source`, `type`, `entity`,
 * `status`, `last_error`, and the day ranges `created_from`, `created_to`, `updated_from` and
 * `updated_to` (YYYY-MM-DD, in the shop's time zone, both ends included). A value that is empty or
 * not of its form is no filter. No filter is named `error`: PrestaShop's back office reads a
 * parameter of that name in a page's address as its own, and the page then fails.
 */
final class LogFilter
{
    public const SOURCE = 'source';

    public const TYPE = 'type';

    public const ENTITY = 'entity';

    public const STATUS = 'status';

    public const ERROR = 'last_error';

    public const DATES = ['created_from', 'created_to', 'updated_from', 'updated_to'];

    private const TEXT_LENGTH = 255;

    /** @var array<string, string> */
    private array $values = [];

    /**
     * @param array<string, mixed> $query
     */
    public function __construct(array $query = [])
    {
        foreach ([self::TYPE, self::ENTITY, self::ERROR] as $field) {
            $this->set($field, mb_substr(self::text($query[$field] ?? ''), 0, self::TEXT_LENGTH));
        }
        $source = self::text($query[self::SOURCE] ?? '');
        $this->set(self::SOURCE, in_array($source, [LogRows::SMAILY, LogRows::INTELLIGENCE], true) ? $source : '');
        $status = self::text($query[self::STATUS] ?? '');
        $this->set(self::STATUS, in_array($status, array_merge(LogStatus::QUEUE, LogStatus::JOB_ONLY), true) ? $status : '');
        foreach (self::DATES as $field) {
            $date = self::text($query[$field] ?? '');
            $this->set($field, preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false ? $date : '');
        }
    }

    public function get(string $field): string
    {
        return $this->values[$field] ?? '';
    }

    /**
     * The filters that are set, for a link that keeps them.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        return $this->values;
    }

    private function set(string $field, string $value): void
    {
        if ($value !== '') {
            $this->values[$field] = $value;
        }
    }

    /**
     * @param mixed $value
     */
    private static function text($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
