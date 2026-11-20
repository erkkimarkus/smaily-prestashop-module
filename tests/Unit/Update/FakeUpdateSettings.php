<?php
declare(strict_types=1);

namespace Tests\Unit\Update;

use PrestaShop\Module\SmailyForPrestaShop\Config\UpdateSettings;

/**
 * The update state held in memory at the global scope, as Config\Settings stores it.
 */
final class FakeUpdateSettings extends UpdateSettings
{
    public ?string $value;

    public function __construct(?string $value = null)
    {
        $this->value = $value;
    }

    protected function get(string $key, int $idShop = 0): ?string
    {
        return $key === self::KEY && $idShop === 0 ? $this->value : null;
    }

    protected function set(string $key, string $value, int $idShop = 0): bool
    {
        if ($key === self::KEY && $idShop === 0) {
            $this->value = $value;
        }

        return true;
    }

    protected function storedValue(string $key, ?int $idShop = null): ?string
    {
        return $this->get($key, $idShop ?? 0);
    }
}
