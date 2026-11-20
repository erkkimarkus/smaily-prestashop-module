<?php
declare(strict_types=1);

namespace Tests\Integration\Automation;

use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use Tests\Integration\IntegrationTestCase;

/**
 * The mapping rows in the shop's real table: a row is written, replaced and deleted by its unique
 * key, and read per shop scope.
 */
final class MappingRepositoryTest extends IntegrationTestCase
{
    private const TABLE = 'smaily_connect_automation_mapping';

    private MappingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        self::db()->delete(self::TABLE);
        $this->repository = new MappingRepository();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::db()->delete(self::TABLE);
        }
        parent::tearDown();
    }

    public function testWritesReplacesAndReadsTheRowsOfAShopScope(): void
    {
        self::assertTrue($this->repository->upsert(0, Trigger::WELCOME, '', 'default', null, true, true));
        self::assertTrue($this->repository->upsert(0, Trigger::ABANDONED_CART, '', 'default', 102, false, true));
        self::assertTrue($this->repository->upsert(2, Trigger::WELCOME, 'et', 'et', 103));
        self::assertTrue($this->repository->upsert(0, Trigger::WELCOME, '', 'default', 101, false, true));

        $rows = $this->repository->rows(0);
        self::assertCount(2, $rows);
        self::assertSame(
            ['id_shop' => 0, 'trigger' => 'welcome', 'language' => '', 'account_key' => 'default', 'workflow_id' => 101, 'optin_trigger' => false, 'is_default_fallback' => true],
            array_diff_key($rows[0], ['id' => true])
        );
        self::assertSame([102], array_column($this->repository->rows(0, Trigger::ABANDONED_CART), 'workflow_id'));
        self::assertSame([['et', 'et', 103]], array_map(
            static fn (array $row): array => [$row['language'], $row['account_key'], $row['workflow_id']],
            $this->repository->rows(2)
        ));
        self::assertSame([], $this->repository->rows(3));
    }

    public function testKeepsTheGenericOptInTriggerOfTheWelcome(): void
    {
        $this->repository->upsert(1, Trigger::WELCOME, '', 'default', null, true);

        $row = $this->repository->rows(1, Trigger::WELCOME)[0];
        self::assertNull($row['workflow_id']);
        self::assertTrue($row['optin_trigger']);
    }

    public function testDeletesOnlyTheRowOfItsKey(): void
    {
        $this->repository->upsert(0, Trigger::WELCOME, '', 'default', 101);
        $this->repository->upsert(0, Trigger::WELCOME, 'et', 'default', 103);

        self::assertTrue($this->repository->delete(0, Trigger::WELCOME, '', 'default'));

        self::assertSame(['et'], array_column($this->repository->rows(0), 'language'));
    }

    /**
     * @return array<string, array{string, string, string, int|null, bool}>
     */
    public function invalidRows(): array
    {
        return [
            'an unknown trigger' => ['order_confirmation', '', 'default', 101, false],
            'a language that is not an ISO code' => [Trigger::WELCOME, 'est', 'default', 101, false],
            'an unknown account key' => [Trigger::WELCOME, '', 'transactional', 101, false],
            'no workflow and no opt-in trigger' => [Trigger::WELCOME, '', 'default', null, false],
            'a workflow and the opt-in trigger' => [Trigger::WELCOME, '', 'default', 101, true],
            'a workflow id that is not positive' => [Trigger::WELCOME, '', 'default', 0, false],
            'the opt-in trigger for another automation' => [Trigger::ABANDONED_CART, '', 'default', null, true],
        ];
    }

    /**
     * @dataProvider invalidRows
     */
    public function testRefusesARowThatNoAutomationCanSend(string $trigger, string $language, string $accountKey, ?int $workflowId, bool $optinTrigger): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->upsert(0, $trigger, $language, $accountKey, $workflowId, $optinTrigger);
    }
}
