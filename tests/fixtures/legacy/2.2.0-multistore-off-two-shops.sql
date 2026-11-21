-- Smaily for PrestaShop 2.2.0, fully configured, in a shop whose multistore feature is switched off
-- while the `shop` table still holds two shops: multistore was on once, shop 2 was added in shop group 1
-- (which does not share customers), a save in shop 2's context left some rows, and multistore was
-- switched off again. PrestaShop then reads and writes only the global rows, whatever shop a call names
-- (observed on 8.2.8 and 9.2.0, docs/internal/spikes/platform-facts.md 6.9). The global rows are those of
-- 2.2.0-configured.sql. The shop 2 rows are dead: another Smaily account (subdomain other-store),
-- abandoned cart switched off, an empty cart cron token.
-- DERIVED from the observed 2.2.0 write pattern, not dumped. All values are invented. See README.md.
-- Assumes table prefix ps_, shop 1 and shop group 1 as in the demo data, and the module row.

-- Part 1: shop 2, multistore off, configuration and smaily_cart.
-- A minimal shop 2 that PrestaShop's configuration and shop classes read. It is no browsable storefront.
DELETE FROM `ps_shop_url` WHERE `id_shop` = 2;
DELETE FROM `ps_shop` WHERE `id_shop` = 2;
INSERT INTO `ps_shop` (`id_shop`, `id_shop_group`, `name`, `color`, `id_category`, `theme_name`, `active`, `deleted`)
SELECT 2, 1, 'Second shop', '', `id_category`, `theme_name`, 1, 0 FROM `ps_shop` WHERE `id_shop` = 1;
INSERT INTO `ps_shop_url` (`id_shop`, `domain`, `domain_ssl`, `physical_uri`, `virtual_uri`, `main`, `active`)
SELECT 2, `domain`, `domain_ssl`, `physical_uri`, 'second/', 1, 1 FROM `ps_shop_url` WHERE `id_shop` = 1 AND `main` = 1;
-- The back office stores a switched-off multistore switch as NULL.
DELETE FROM `ps_configuration` WHERE `name` = 'PS_MULTISHOP_FEATURE_ACTIVE';
INSERT INTO `ps_configuration` (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`) VALUES
(NULL, NULL, 'PS_MULTISHOP_FEATURE_ACTIVE', NULL, NOW(), NOW());

DELETE FROM `ps_configuration` WHERE `name` LIKE 'SMAILY\_%'
  OR `name` IN ('NW_VERIFICATION_EMAIL', 'NW_CONFIRMATION_EMAIL', 'NW_VOUCHER_CODE');
INSERT INTO `ps_configuration` (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`) VALUES
-- Global rows, as in 2.2.0-configured.sql: the only rows that PrestaShop reads while multistore is off.
(NULL, NULL, 'SMAILY_SUBDOMAIN', 'example-store', NOW(), NOW()),
(NULL, NULL, 'SMAILY_USERNAME', 'api-user', NOW(), NOW()),
(NULL, NULL, 'SMAILY_PASSWORD', 'not-a-real-password', NOW(), NOW()),
(NULL, NULL, 'SMAILY_ENABLE_CUSTOMER_SYNC', '1', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CUSTOMER_CRON_TOKEN', 'abc123def456', NOW(), NOW()),
(NULL, NULL, 'SMAILY_SYNCRONIZE_ADDITIONAL', '{"store":true,"first_name":true,"last_name":true,"birthday":true,"website":true}', NOW(), NOW()),
(NULL, NULL, 'SMAILY_OPTIN_ENABLED', '1', NOW(), NOW()),
(NULL, NULL, 'SMAILY_OPTIN_AUTORESPONDER', '101', NOW(), NOW()),
(NULL, NULL, 'SMAILY_ENABLE_ABANDONED_CART', '1', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CART_CRON_TOKEN', 'fed654cba321', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CART_AUTORESPONDER', '102', NOW(), NOW()),
(NULL, NULL, 'SMAILY_ABANDONED_CART_TIME', '45', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CART_SYNCRONIZE_ADDITIONAL', '{"first_name":true,"last_name":true,"name":true,"description":true,"sku":true,"price":true,"base_price":true,"quantity":true}', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_CATEGORY_ID', '3', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_LIMIT', '100', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_SORT_BY', 'price', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_SORT_ORDER', 'asc', NOW(), NOW()),
(NULL, NULL, 'NW_VERIFICATION_EMAIL', '0', NOW(), NOW()),
(NULL, NULL, 'NW_CONFIRMATION_EMAIL', '0', NOW(), NOW()),
(NULL, NULL, 'NW_VOUCHER_CODE', NULL, NOW(), NOW()),
-- Shop 2 rows that a save in shop 2's context left while multistore was on. Nothing reads them now.
(1, 2, 'SMAILY_SUBDOMAIN', 'other-store', NOW(), NOW()),
(1, 2, 'SMAILY_USERNAME', 'other-api-user', NOW(), NOW()),
(1, 2, 'SMAILY_PASSWORD', 'not-a-real-password-either', NOW(), NOW()),
(1, 2, 'SMAILY_ENABLE_ABANDONED_CART', NULL, NOW(), NOW()),
(1, 2, 'SMAILY_CART_CRON_TOKEN', NULL, NOW(), NOW());

CREATE TABLE IF NOT EXISTS `ps_smaily_cart` (
  `id_smaily_cart` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_customer` int(10) unsigned DEFAULT NULL,
  `id_cart` int(10) unsigned DEFAULT NULL,
  `date_sent` datetime NOT NULL,
  PRIMARY KEY (`id_smaily_cart`)
) ENGINE=InnoDB;
DELETE FROM `ps_smaily_cart`;
INSERT INTO `ps_smaily_cart` (`id_smaily_cart`, `id_customer`, `id_cart`, `date_sent`) VALUES
(1, 2, 1, '2026-11-01 10:15:00'),
(2, 2, 2, '2026-11-02 18:40:30');

-- Part 2: the module in shop 2 too, as PrestaShop enables an installed module in a new shop.
INSERT IGNORE INTO `ps_module_shop` (`id_module`, `id_shop`, `enable_device`)
SELECT `id_module`, 2, `enable_device` FROM `ps_module_shop`
WHERE `id_shop` = 1 AND `id_module` = (SELECT `id_module` FROM `ps_module` WHERE `name` = 'smailyforprestashop');
