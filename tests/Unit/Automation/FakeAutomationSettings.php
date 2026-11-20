<?php
declare(strict_types=1);

namespace Tests\Unit\Automation;

use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;

/**
 * The Automations settings from an array by shop (0 = global); a shop without a value reads the
 * global one.
 */
final class FakeAutomationSettings extends AutomationSettings
{
    /** @var array<int, array<string, string>> */
    private array $values;

    /**
     * @param array<int, array<string, string>> $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    protected function get(string $key, int $idShop = 0): ?string
    {
        return $this->values[$idShop][$key] ?? $this->values[0][$key] ?? null;
    }
}
