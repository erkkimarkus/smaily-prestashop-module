<?php
declare(strict_types=1);

namespace Tests\Unit\Automation;

use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;

/**
 * Mapping rows held in an array instead of the table.
 */
final class FakeMappingRepository extends MappingRepository
{
    /** @var list<array{id: int, id_shop: int, trigger: string, language: string, account_key: string, workflow_id: int|null, optin_trigger: bool, is_default_fallback: bool}> */
    private array $stored = [];

    /**
     * @param list<array{0: int, 1: string, 2: string, 3: string, 4: int|null, 5?: bool, 6?: bool}> $rows
     *                                                                                                id_shop, trigger, language, account_key, workflow_id, optin_trigger, is_default_fallback
     */
    public function __construct(array $rows)
    {
        foreach ($rows as $row) {
            $this->stored[] = [
                'id' => count($this->stored) + 1,
                'id_shop' => $row[0],
                'trigger' => $row[1],
                'language' => $row[2],
                'account_key' => $row[3],
                'workflow_id' => $row[4],
                'optin_trigger' => $row[5] ?? false,
                'is_default_fallback' => $row[6] ?? false,
            ];
        }
    }

    public function rows(int $idShop, ?string $trigger = null): array
    {
        return array_values(array_filter(
            $this->stored,
            static fn (array $row): bool => $row['id_shop'] === $idShop && ($trigger === null || $row['trigger'] === $trigger)
        ));
    }
}
