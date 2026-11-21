<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\DismissUpdate;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Config\UpdateSettings;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Tests\Integration\IntegrationTestCase;

/**
 * The module pages tell the merchant about a newer release that the daily check stored, until an
 * admin dismisses that version, and never while the upgrade is not finished. The state is seeded
 * in the settings key; nothing here asks GitHub.
 */
final class UpdateNoticeTest extends IntegrationTestCase
{
    private const RELEASE = [
        'version' => '9.9.9',
        'download_url' => 'https://github.com/sendsmaily/smaily-prestashop-module/releases/download/v9.9.9/smailyforprestashop.zip',
        'release_url' => 'https://github.com/sendsmaily/smaily-prestashop-module/releases/tag/v9.9.9',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        \Configuration::deleteByName(UpdateSettings::KEY);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Configuration::deleteByName(UpdateSettings::KEY);
            Schema::markCurrent();
        }
        parent::tearDown();
    }

    public function testNoNoticeWhileNoNewerReleaseIsStored(): void
    {
        self::assertNull(BaseController::updateNotice());

        self::seed(['version' => '3.0.0'] + self::RELEASE);
        self::assertNull(BaseController::updateNotice(), 'the installed version is not newer');
    }

    public function testAStoredNewerReleaseShowsUntilItIsDismissed(): void
    {
        self::seed(self::RELEASE);
        self::assertSame(self::RELEASE, BaseController::updateNotice());

        $answer = self::call(new DismissUpdate(), 'handle', new Request([], [], [], [], [], [], (string) json_encode(['version' => '9.9.9'])), 0);

        self::assertSame([], $answer);
        self::assertNull(BaseController::updateNotice());
        self::assertSame('9.9.9', (new class() extends UpdateSettings {
            public function dismissed(): ?string
            {
                return $this->storedState()['dismissed'] ?? null;
            }
        })->dismissed());
    }

    public function testTheUnfinishedUpgradeHasPriority(): void
    {
        self::seed(self::RELEASE);
        \Configuration::deleteByName(Schema::VERSION_KEY);

        self::assertNull(BaseController::updateNotice());
    }

    public function testAWrongTokenIsRefusedAndChangesNothing(): void
    {
        self::seed(self::RELEASE);
        $endpoint = new class() extends AbstractJsonAction {
            public bool $handled = false;

            protected function handle(Request $request, int $idShop)
            {
                $this->handled = true;

                return [];
            }

            protected function isGranted($attribute, $subject = null): bool
            {
                return true;
            }

            protected function isCsrfTokenValid(string $id, ?string $token): bool
            {
                return $id === BaseController::API_TOKEN_ID && $token === 'right';
            }

            protected function trans($key, $domain, array $parameters = [])
            {
                return $key;
            }
        };
        $request = new Request();
        $request->headers->set('X-Smaily-Token', 'wrong');
        $request->headers->set('X-Smaily-Scope', AbstractJsonAction::pageScope());

        $response = $endpoint->execute($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('invalid_token', json_decode((string) $response->getContent(), true)['error']['code']);
        self::assertFalse($endpoint->handled);
        self::assertSame(self::RELEASE, BaseController::updateNotice());

        $request->headers->set('X-Smaily-Token', 'right');
        self::assertSame(200, $endpoint->execute($request)->getStatusCode());
        self::assertTrue($endpoint->handled);
    }

    /**
     * @param array<string, string> $state
     */
    private static function seed(array $state): void
    {
        \Configuration::updateGlobalValue(UpdateSettings::KEY, (string) json_encode($state + ['checked_at' => time(), 'dismissed' => null]));
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
