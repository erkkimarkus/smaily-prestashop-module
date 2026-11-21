<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\FinishUpgrade;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyConfigMapper;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyFiles;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyReader;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../Migration/LegacyStore.php';

/**
 * A migration that stopped shows as failed on the admin pages, and the "Finish upgrade" endpoint
 * completes it once the cause is gone, answering in the endpoints' envelope.
 *
 * The permission and CSRF checks of AbstractJsonAction::execute() need the back office's Symfony
 * kernel; the browser check of the pages covers them.
 */
final class FinishUpgradeTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::clear();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            LegacyStore::restore();
            self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'3.0.0\' WHERE `name` = \'smailyforprestashop\'');
        }
        parent::tearDown();
    }

    public function testFinishUpgradeCompletesAMigrationThatStopped(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'2.2.0\' WHERE `name` = \'smailyforprestashop\'');
        $failing = new Upgrade300(
            new LegacyReader(),
            new LegacyConfigMapper(),
            Services::instance()->get(AccountStore::class),
            new MigrationNotices(),
            new LegacyOverride(_PS_OVERRIDE_DIR_),
            // No such folder: the files step works on the module's folder only in a real upgrade.
            new LegacyFiles(sys_get_temp_dir() . '/smaily-no-module-folder'),
            static function (string $step): void {
                if ($step === 'verify') {
                    throw new \LogicException('injected');
                }
            }
        );
        self::assertFalse($failing->runPending());
        self::assertSame(['status' => 'failed'], BaseController::pendingUpgrade());

        $endpoint = new FinishUpgrade();
        $answer = self::call($endpoint, 'handle', new Request(), 0);
        $response = self::call($endpoint, 'ok', $answer);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'data' => ['current' => true]], json_decode((string) $response->getContent(), true));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertTrue(Schema::isCurrent());
        self::assertNull(BaseController::pendingUpgrade());
        self::assertSame('done', Services::instance()->get(Upgrade300::class)->state()['status']);
        self::assertSame(0, LegacyStore::countRows('SMAILY_PASSWORD'));
    }

    public function testAnErrorAnswersInTheEnvelopeWithItsStatus(): void
    {
        $response = self::call(new FinishUpgrade(), 'error', 'upgrade_not_finished', 'The upgrade did not finish.', 409);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            ['ok' => false, 'error' => ['code' => 'upgrade_not_finished', 'message' => 'The upgrade did not finish.']],
            json_decode((string) $response->getContent(), true)
        );
    }

    /**
     * @param mixed ...$arguments
     *
     * @return mixed
     */
    private static function call(object $object, string $method, ...$arguments)
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }
}
