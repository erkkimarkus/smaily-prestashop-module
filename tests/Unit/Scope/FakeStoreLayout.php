<?php
declare(strict_types=1);

namespace Tests\Unit\Scope;

use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;

/**
 * The store layout over settings held in memory: id_shop => key => value, id_shop 0 the global
 * scope. A shop without its own value reads the global one, as Config\Settings does.
 */
final class FakeStoreLayout extends StoreLayout
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
