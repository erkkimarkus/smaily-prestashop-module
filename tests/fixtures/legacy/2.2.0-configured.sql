-- Smaily for PrestaShop 2.2.0, fully configured. Single shop (multistore off), so every row is global.
-- OBSERVED: on PrestaShop 9.2.0 after a fresh module install, the module's own write code ran in the
-- shop (CustomerSyncFormDataConfiguration, AbandonedCartFormDataConfiguration and
-- RssFeedFormDataConfiguration ::updateConfiguration, and the three Configuration::set calls that
-- AccountFormDataConfiguration makes on an HTTP 200 from Smaily), then the rows were dumped.
-- DERIVED: the input. The admin form checks the credentials against the live Smaily API first, so
-- the script skipped that check and passed form data shaped as Symfony submits it (bool switches,
-- int autoresponder ids, float NumberType values). All values are invented.
-- Assumes table prefix ps_. See README.md.

DELETE FROM `ps_configuration` WHERE `name` LIKE 'SMAILY\_%'
  OR `name` IN ('NW_VERIFICATION_EMAIL', 'NW_CONFIRMATION_EMAIL', 'NW_VOUCHER_CODE');
INSERT INTO `ps_configuration` (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`) VALUES
-- Account
(NULL, NULL, 'SMAILY_SUBDOMAIN', 'example-store', NOW(), NOW()),
(NULL, NULL, 'SMAILY_USERNAME', 'api-user', NOW(), NOW()),
(NULL, NULL, 'SMAILY_PASSWORD', 'not-a-real-password', NOW(), NOW()),
-- Customer sync
(NULL, NULL, 'SMAILY_ENABLE_CUSTOMER_SYNC', '1', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CUSTOMER_CRON_TOKEN', 'abc123def456', NOW(), NOW()),
(NULL, NULL, 'SMAILY_SYNCRONIZE_ADDITIONAL', '{"store":true,"first_name":true,"last_name":true,"birthday":true,"website":true}', NOW(), NOW()),
(NULL, NULL, 'SMAILY_OPTIN_ENABLED', '1', NOW(), NOW()),
(NULL, NULL, 'SMAILY_OPTIN_AUTORESPONDER', '101', NOW(), NOW()),
-- Abandoned cart
(NULL, NULL, 'SMAILY_ENABLE_ABANDONED_CART', '1', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CART_CRON_TOKEN', 'fed654cba321', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CART_AUTORESPONDER', '102', NOW(), NOW()),
(NULL, NULL, 'SMAILY_ABANDONED_CART_TIME', '45', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CART_SYNCRONIZE_ADDITIONAL', '{"first_name":true,"last_name":true,"name":true,"description":true,"sku":true,"price":true,"base_price":true,"quantity":true}', NOW(), NOW()),
-- RSS feed (category 3 is "Clothes" in the demo catalog)
(NULL, NULL, 'SMAILY_RSS_CATEGORY_ID', '3', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_LIMIT', '100', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_SORT_BY', 'price', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_SORT_ORDER', 'asc', NOW(), NOW()),
-- Side effect of saving customer sync with opt-in on: ps_emailsubscription stops sending its own mails.
(NULL, NULL, 'NW_VERIFICATION_EMAIL', '0', NOW(), NOW()),
(NULL, NULL, 'NW_CONFIRMATION_EMAIL', '0', NOW(), NOW()),
(NULL, NULL, 'NW_VOUCHER_CODE', NULL, NOW(), NOW());

CREATE TABLE IF NOT EXISTS `ps_smaily_cart` (
  `id_smaily_cart` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_customer` int(10) unsigned DEFAULT NULL,
  `id_cart` int(10) unsigned DEFAULT NULL,
  `date_sent` datetime NOT NULL,
  PRIMARY KEY (`id_smaily_cart`)
) ENGINE=InnoDB;
DELETE FROM `ps_smaily_cart`;
-- Written through the module's SentCart model, as AbandonedCartController does after a sent reminder.
-- Customer 2 and carts 1-3 exist in the PrestaShop demo data.
INSERT INTO `ps_smaily_cart` (`id_smaily_cart`, `id_customer`, `id_cart`, `date_sent`) VALUES
(1, 2, 1, '2026-11-01 10:15:00'),
(2, 2, 2, '2026-11-02 18:40:30'),
(3, 2, 3, '2026-11-03 07:05:59');
