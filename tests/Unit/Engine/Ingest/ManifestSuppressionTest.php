<?php
declare(strict_types=1);

namespace Tests\Unit\Engine\Ingest;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\CatalogManifest;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestFlusher;

/**
 * No nightly product list is sent for a connection that shares its engine store with another
 * connection while either holds no prefix (ARCHITECTURE 5.3): two lists without a prefix would
 * each claim the whole engine store and remove the other's products.
 */
final class ManifestSuppressionTest extends TestCase
{
    private const STORE = '550e8400-e29b-41d4-a716-446655440000';

    private const OTHER_STORE = '660e8400-e29b-41d4-a716-446655440001';

    /**
     * @dataProvider pairs
     */
    public function testAConnectionIsHeldBackOnlyByAnotherOfItsEngineStoreWhileEitherHoldsNoPrefix(string $prefix, string $otherStore, string $otherPrefix, bool $suppressed): void
    {
        $connection = self::connection(1, self::STORE, $prefix);
        $other = self::connection(2, $otherStore, $otherPrefix);

        self::assertSame($suppressed, CatalogManifest::suppressed($connection, [$connection, $other]));
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function pairs(): iterable
    {
        yield 'one engine store, neither holds a prefix' => ['', self::STORE, '', true];
        yield 'one engine store, this one holds none' => ['', self::STORE, 'c3-', true];
        yield 'one engine store, the other holds none' => ['c2-', self::STORE, '', true];
        yield 'one engine store, both hold a prefix' => ['c2-', self::STORE, 'c3-', false];
        yield 'two engine stores, neither holds a prefix' => ['', self::OTHER_STORE, '', false];
    }

    public function testAConnectionAloneIsNeverHeldBack(): void
    {
        $connection = self::connection(1, self::STORE, '');

        self::assertFalse(CatalogManifest::suppressed($connection, [$connection]));
        self::assertFalse(CatalogManifest::suppressed($connection, []));
    }

    public function testAStoredAnswerHoldsNoTextOfTheEngine(): void
    {
        $answer = [
            'ok' => true,
            'message' => 'No customer found with email shopper@example.test',
            'errors' => [['index' => 2, 'field' => 'email', 'message' => 'Invalid email shopper@example.test']],
            'details' => ['errors' => [['field' => 'x', 'message' => 'text']]],
        ];

        self::assertSame(
            ['ok' => true, 'errors' => [['index' => 2, 'field' => 'email']], 'details' => ['errors' => [['field' => 'x']]]],
            IngestFlusher::withoutText($answer)
        );
    }

    private static function connection(int $id, string $tenant, string $prefix): Connection
    {
        return new Connection($id, $id, '', $tenant, 'k', ['config' => ['connection' => ['prefix' => $prefix]]]);
    }
}
