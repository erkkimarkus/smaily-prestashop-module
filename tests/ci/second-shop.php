<?php
/**
 * Runs inside a shop container: turns multistore on and adds a second shop to the default shop's
 * group, with the data of shop 1 and its own address (the domain in the first argument), as the
 * back office's "Add new shop" with every data type imported does. Prints the new shop's id.
 *
 *   php /smaily/tests/ci/second-shop.php 127.0.0.1:8000
 */
declare(strict_types=1);

require '/var/www/html/config/config.inc.php';

$domain = $argv[1] ?? '';
if ($domain === '') {
    fwrite(STDERR, "usage: second-shop.php <domain>\n");
    exit(2);
}
$db = Db::getInstance();
Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', '1');
$first = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
$shop = new Shop();
$shop->name = 'Second shop';
$shop->id_shop_group = (int) $first->id_shop_group;
$shop->id_category = (int) $first->id_category;
$shop->theme_name = $first->theme_name;
$shop->active = true;
if (!$shop->add()) {
    fwrite(STDERR, "the shop was not added\n");
    exit(1);
}
// AdminShopController reads the categories of the new shop from the form.
$_POST['categoryBox'] = array_map('intval', array_column($db->executeS('SELECT id_category FROM ' . _DB_PREFIX_ . 'category_shop WHERE id_shop = ' . (int) $first->id), 'id_category'));
$shop->copyShopData((int) $first->id, array_fill_keys(array_keys(Shop::getAssoTables()), true));
$db->execute('INSERT IGNORE INTO ' . _DB_PREFIX_ . 'module_carrier (id_module, id_shop, id_reference) SELECT id_module, ' . (int) $shop->id . ', id_reference FROM ' . _DB_PREFIX_ . 'module_carrier WHERE id_shop = ' . (int) $first->id);
$db->insert('shop_url', [
    'id_shop' => (int) $shop->id, 'domain' => pSQL($domain), 'domain_ssl' => pSQL($domain),
    'physical_uri' => '/', 'virtual_uri' => '', 'main' => 1, 'active' => 1,
]);
echo $shop->id . "\n";
