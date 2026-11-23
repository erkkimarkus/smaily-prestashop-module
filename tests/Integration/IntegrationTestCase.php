<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The base of every test that runs inside the docker shop against its real database, with the
 * module installed. tests/bootstrap.php boots the shop when PS_ROOT_DIR is set; without a shop the
 * test is skipped. Each test seeds the state it needs and puts back what it changed.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('_PS_ROOT_DIR_')) {
            self::markTestSkipped('Needs a PrestaShop shop: set PS_ROOT_DIR to its root (see tests/ci/validate.sh).');
        }
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
