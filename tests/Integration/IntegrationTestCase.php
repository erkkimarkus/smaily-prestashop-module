<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\ActiveConnection;

/**
 * The base of every test that runs inside the docker shop against its real database, with the
 * module installed. tests/bootstrap.php boots the shop when PS_ROOT_DIR is set; without a shop the
 * test is skipped. Each test seeds the state it needs and puts back what it changed.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** The shop's own runtime while a test's stands in for it. */
    private ?Services $shopRuntime = null;

    private bool $runtimeSwapped = false;

    protected function setUp(): void
    {
        if (!defined('_PS_ROOT_DIR_')) {
            self::markTestSkipped('Needs a PrestaShop shop: set PS_ROOT_DIR to its root (see tests/ci/validate.sh).');
        }
        // The storefront reads a shop's connection once in a request. A test is a request of its
        // own, and tests write the connection table past Engine\Connections.
        ActiveConnection::reset();
    }

    /**
     * Opens a private or protected property or method to the test. PHP 8.0 needs the call; from
     * PHP 8.1 reflection reads such a member without it, and PHP 8.5 deprecates the call.
     *
     * @param \ReflectionProperty|\ReflectionMethod $member
     */
    protected static function accessible($member): void
    {
        if (PHP_VERSION_ID < 80100) {
            $member->setAccessible(true);
        }
    }

    /**
     * Makes $services the shop's own runtime, so that the module's hooks, its uninstall and its
     * admin endpoints run on them; restoreShopRuntime() in tearDown() puts the shop's own back.
     */
    protected function useAsShopRuntime(Services $services): void
    {
        if (!$this->runtimeSwapped) {
            $this->shopRuntime = self::shopRuntime()->getValue();
            $this->runtimeSwapped = true;
        }
        self::shopRuntime()->setValue(null, $services);
    }

    protected function restoreShopRuntime(): void
    {
        if ($this->runtimeSwapped) {
            self::shopRuntime()->setValue(null, $this->shopRuntime);
            $this->runtimeSwapped = false;
        }
    }

    private static function shopRuntime(): \ReflectionProperty
    {
        $property = new \ReflectionProperty(Services::class, 'instance');
        self::accessible($property);

        return $property;
    }

    protected static function db(): \Db
    {
        return \Db::getInstance();
    }

    protected static function module(): \SmailyForPrestaShop
    {
        $module = \Module::getInstanceByName('smailyforprestashop');
        self::assertInstanceOf(\SmailyForPrestaShop::class, $module, 'The module is installed.');

        return $module;
    }
}
