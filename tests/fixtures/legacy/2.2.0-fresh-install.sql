-- Smaily for PrestaShop 2.2.0 right after install, nothing configured. Single shop (multistore off).
-- OBSERVED: `bin/console prestashop:module install smailyforprestashop` on PrestaShop 9.2.0
-- (prestashop-flashlight:9.2.0-nginx, MariaDB LTS), rows dumped from ps_configuration and ps_smaily_cart.
-- Only id_configuration and the dates are left out. The two cron tokens are random per install
-- (bin2hex(random_bytes(6))); these are the ones that install generated.
-- Assumes table prefix ps_. Load into a shop where the module row exists; see README.md.

DELETE FROM `ps_configuration` WHERE `name` LIKE 'SMAILY\_%';
INSERT INTO `ps_configuration` (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`) VALUES
(NULL, NULL, 'SMAILY_SUBDOMAIN', NULL, NOW(), NOW()),
(NULL, NULL, 'SMAILY_USERNAME', NULL, NOW(), NOW()),
(NULL, NULL, 'SMAILY_PASSWORD', NULL, NOW(), NOW()),
(NULL, NULL, 'SMAILY_ENABLE_CUSTOMER_SYNC', NULL, NOW(), NOW()),
(NULL, NULL, 'SMAILY_CUSTOMER_CRON_TOKEN', 'c5f10ba5bfe1', NOW(), NOW()),
(NULL, NULL, 'SMAILY_SYNCRONIZE_ADDITIONAL', '[]', NOW(), NOW()),
(NULL, NULL, 'SMAILY_OPTIN_ENABLED', NULL, NOW(), NOW()),
(NULL, NULL, 'SMAILY_OPTIN_AUTORESPONDER', NULL, NOW(), NOW()),
(NULL, NULL, 'SMAILY_ENABLE_ABANDONED_CART', '0', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CART_CRON_TOKEN', 'b3245a3cc413', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CART_AUTORESPONDER', NULL, NOW(), NOW()),
(NULL, NULL, 'SMAILY_ABANDONED_CART_TIME', '15', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CART_SYNCRONIZE_ADDITIONAL', '[]', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_CATEGORY_ID', NULL, NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_LIMIT', '50', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_SORT_BY', 'date_upd', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_SORT_ORDER', 'desc', NOW(), NOW());

-- Install does not touch the NW_* keys of ps_emailsubscription. They keep what that module set.

-- The table install creates, as SHOW CREATE TABLE printed it (charset and collation are the server's).
CREATE TABLE IF NOT EXISTS `ps_smaily_cart` (
  `id_smaily_cart` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_customer` int(10) unsigned DEFAULT NULL,
  `id_cart` int(10) unsigned DEFAULT NULL,
  `date_sent` datetime NOT NULL,
  PRIMARY KEY (`id_smaily_cart`)
) ENGINE=InnoDB;
DELETE FROM `ps_smaily_cart`;
