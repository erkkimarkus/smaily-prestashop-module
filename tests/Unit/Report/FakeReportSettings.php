<?php
declare(strict_types=1);

namespace Tests\Unit\Report;

use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;

/**
 * The installation report's stored settings held in memory.
 */
final class FakeReportSettings extends ReportSettings
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var int the number of writes */
    public int $writes = 0;

    public bool $failing = false;

    protected function storedValue(string $key, ?int $idShop = null): ?string
    {
        if ($this->failing) {
            throw new \RuntimeException('The settings cannot be read.');
        }

        return $this->values[$key] ?? null;
    }

    protected function set(string $key, string $value, int $idShop = 0): bool
    {
        if ($this->failing) {
            throw new \RuntimeException('The settings cannot be written.');
        }
        $this->values[$key] = $value;
        ++$this->writes;

        return true;
    }
}
