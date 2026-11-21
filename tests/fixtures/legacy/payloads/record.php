<?php
/**
 * Records the abandoned-cart payloads of 2.2.0 with every box of its field picker ticked, for the
 * carts that tests/Integration/Automation/AbandonedCart/LegacyPayloadTest.php builds, and prints them
 * as JSON: {"<number of products>": {<the payload>}, ...}.
 *
 * It runs 2.2.0's own code, AbandonedCartController::generatePayload() over
 * AbandonedCartCollection, from the folder given as its argument; 3.0.0 does not ship those
 * classes. The README of tests/fixtures/legacy/ has the commands. Run it as www-data in a dev shop
 * with the demo catalog; it removes the customers and carts it makes.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[1]) || !is_file($argv[1] . '/Controller/AbandonedCartController.php')) {
    fwrite(STDERR, "Usage: php record.php <folder with src/src of the 2.2.0 tag>\n");
    exit(2);
}

require '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/../../../Integration/Automation/AbandonedCart/CartFixture.php';
foreach (['Model/AbandonedCartProduct', 'Model/AbandonedCart', 'Model/AbandonedCartCollection', 'Controller/AbandonedCartController'] as $file) {
    require_once $argv[1] . '/' . $file . '.php';
}

use PrestaShop\Module\SmailyForPrestaShop\Controller\AbandonedCartController;
use PrestaShop\Module\SmailyForPrestaShop\Model\AbandonedCart;
use PrestaShop\Module\SmailyForPrestaShop\Model\AbandonedCartCollection;
use PrestaShop\PrestaShop\Core\ConfigurationInterface;
use Tests\Integration\Automation\AbandonedCart\CartFixture;

/** The cart sizes of LegacyPayloadTest. */
const SIZES = [1, 2, 4, 10, 11];

$db = Db::getInstance();
// 2.2.0 reads its table of sent reminders; a 3.0.0 store has none.
$hadTable = (bool) $db->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'smaily_cart\'');
if (!$hadTable) {
    $db->execute('CREATE TABLE `' . _DB_PREFIX_ . 'smaily_cart` (`id_smaily_cart` int(10) unsigned NOT NULL AUTO_INCREMENT,'
        . ' `id_customer` int(10) unsigned NULL, `id_cart` int(10) unsigned NULL, `date_sent` datetime NOT NULL,'
        . ' PRIMARY KEY (`id_smaily_cart`)) ENGINE=InnoDB');
}

$selection = array_fill_keys(['first_name', 'last_name', 'name', 'description', 'sku', 'price', 'base_price', 'quantity'], true);
$configuration = new class(['SMAILY_CART_SYNCRONIZE_ADDITIONAL' => json_encode($selection)]) implements ConfigurationInterface {
    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function get($key)
    {
        return $this->values[$key] ?? null;
    }

    public function set($key, $value)
    {
        $this->values[$key] = $value;

        return $this;
    }
};

$context = Context::getContext();
if ($context->container === null) {
    $context->container = PrestaShop\PrestaShop\Adapter\ContainerBuilder::getContainer('front', false);
}
$generate = new ReflectionMethod(AbandonedCartController::class, 'generatePayload');
$generate->setAccessible(true);
$fixture = new CartFixture();
$payloads = [];
try {
    foreach (SIZES as $products) {
        // The customer and the cart of the test: 2.x took the language and the currency from the cron request.
        $idCart = $fixture->cart($fixture->customer('shopper1@example.test'), 20, $products);
        $cart = new Cart($idCart);
        $context->cart = $cart;
        $context->language = new Language((int) $cart->id_lang);
        $context->currency = new Currency((int) $cart->id_currency);
        $context->currentLocale = null;
        $context->currentLocale = Tools::getContextLocale($context);
        $carts = array_values(array_filter(
            (new AbandonedCartCollection())->carts,
            static fn (AbandonedCart $legacy): bool => $legacy->cartID === $idCart
        ));
        if (count($carts) !== 1) {
            throw new RuntimeException('2.2.0 did not find the cart of ' . $products . ' products.');
        }
        $payloads[(string) $products] = $generate->invoke(new AbandonedCartController($configuration), $carts[0]);
        $fixture->remove();
    }
} finally {
    $fixture->remove();
    if (!$hadTable) {
        $db->execute('DROP TABLE `' . _DB_PREFIX_ . 'smaily_cart`');
    }
}

fwrite(STDOUT, json_encode($payloads, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
