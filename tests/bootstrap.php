<?php
/**
 * PHPUnit bootstrap: loads the module's classes and its development dependencies.
 *
 * With PS_ROOT_DIR set to the root of a PrestaShop installation (inside the docker shop:
 * /var/www/html), it also boots that shop, and the integration tests run against it. Without it the
 * integration tests are skipped.
 */
declare(strict_types=1);

// PHPUnit's own launcher has loaded an autoloader already. Loading the same Composer autoloader a
// second time from another path (the dev shop mounts src/ as the module folder) is fatal.
if (!class_exists(Composer\Autoload\ClassLoader::class, false)) {
    require __DIR__ . '/../src/vendor/autoload.php';
}

require __DIR__ . '/Integration/IntegrationTestCase.php';

$psRoot = getenv('PS_ROOT_DIR');
if ($psRoot) {
    require $psRoot . '/config/config.inc.php';
} else {
    // The module's files refuse to load outside PrestaShop; the tests stand in for it.
    define('_PS_VERSION_', '0.0.0');
}
