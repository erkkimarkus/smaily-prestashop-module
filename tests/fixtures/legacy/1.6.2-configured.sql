-- Smaily for PrestaShop 1.6.2 (release line 1.6.x, tag 1.6.2 = upstream dev/1.7.x), fully configured,
-- in a shop that later upgraded PrestaShop from 1.7 to 8 with the module data left as 1.6.x wrote it.
-- Single shop (multistore off), so every row is global.
-- DERIVED FROM CODE, not observed: `git show 1.6.2:src/smailyforprestashop.php` (install(),
-- getContent() form handlers) and src/controllers/admin/AdminSmailyforprestashopAjaxController.php
-- (credential save after a successful API call). All values are invented. See README.md.
-- Assumes table prefix ps_, and that the ps_module row for smailyforprestashop exists (part 2).

-- Part 1: configuration.
DELETE FROM `ps_configuration` WHERE `name` LIKE 'SMAILY\_%';
INSERT INTO `ps_configuration` (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`) VALUES
-- Account. The AJAX validation strips every non-alphanumeric character from the subdomain before it
-- saves it, so "example-store" is stored as "examplestore".
(NULL, NULL, 'SMAILY_SUBDOMAIN', 'examplestore', NOW(), NOW()),
(NULL, NULL, 'SMAILY_USERNAME', 'api-user', NOW(), NOW()),
(NULL, NULL, 'SMAILY_PASSWORD', 'not-a-real-password', NOW(), NOW()),
-- Customer sync. 1.6.x names the switch SMAILY_ENABLE_CRON; radio values are '1' / '0'.
(NULL, NULL, 'SMAILY_ENABLE_CRON', '1', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CUSTOMER_CRON_TOKEN', 'abc123def456', NOW(), NOW()),
-- PHP-serialized list of the ticked field names (not a JSON map; no "store" entry).
(NULL, NULL, 'SMAILY_SYNCRONIZE_ADDITIONAL', 'a:4:{i:0;s:10:"first_name";i:1;s:9:"last_name";i:2;s:8:"birthday";i:3;s:7:"website";}', NOW(), NOW()),
(NULL, NULL, 'SMAILY_OPTIN_ENABLED', '1', NOW(), NOW()),
(NULL, NULL, 'SMAILY_OPTIN_AUTORESPONDER', '101', NOW(), NOW()),
-- Abandoned cart
(NULL, NULL, 'SMAILY_ENABLE_ABANDONED_CART', '1', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CART_CRON_TOKEN', 'fed654cba321', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CART_AUTORESPONDER', '102', NOW(), NOW()),
(NULL, NULL, 'SMAILY_ABANDONED_CART_TIME', '45', NOW(), NOW()),
(NULL, NULL, 'SMAILY_CART_SYNCRONIZE_ADDITIONAL', 'a:8:{i:0;s:10:"first_name";i:1;s:9:"last_name";i:2;s:4:"name";i:3;s:11:"description";i:4;s:3:"sku";i:5;s:5:"price";i:6;s:10:"base_price";i:7;s:8:"quantity";}', NOW(), NOW()),
-- RSS feed
(NULL, NULL, 'SMAILY_RSS_CATEGORY_ID', '3', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_LIMIT', '100', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_SORT_BY', 'price', NOW(), NOW()),
(NULL, NULL, 'SMAILY_RSS_SORT_ORDER', 'asc', NOW(), NOW());
-- 1.6.x never writes SMAILY_ENABLE_CUSTOMER_SYNC nor any NW_* key.

-- smaily_cart: same table as in 2.x (1.6.x creates it in install() and in Upgrade-1.1.0.php).
-- 1.6.x inserts with CURRENT_TIMESTAMP after each sent reminder.
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
(2, 2, 2, '2026-11-02 18:40:30'),
(3, 2, 3, '2026-11-03 07:05:59');

-- Part 2: module registration that 1.6.x leaves behind. Needs the ps_module row.
SET @id_module = (SELECT `id_module` FROM `ps_module` WHERE `name` = 'smailyforprestashop');
UPDATE `ps_module` SET `version` = '1.6.2' WHERE `id_module` = @id_module;

-- Hidden admin tab for the AJAX controller (installTab('AdminAdmin', ...), active = 0). No tab with
-- class AdminAdmin exists in PrestaShop 1.7 or 8, so Tab::getIdFromClassName() gives 0 and the tab
-- is top-level. Columns are the PrestaShop 8 ps_tab columns.
DELETE FROM `ps_tab` WHERE `class_name` = 'AdminSmailyforprestashopAjax';
INSERT INTO `ps_tab` (`id_parent`, `position`, `module`, `class_name`, `route_name`, `active`, `enabled`, `icon`, `wording`, `wording_domain`)
SELECT 0, COALESCE(MAX(`position`), -1) + 1, 'smailyforprestashop', 'AdminSmailyforprestashopAjax', '', 0, 1, '', NULL, NULL
FROM `ps_tab` WHERE `id_parent` = 0;
SET @id_tab = LAST_INSERT_ID();
INSERT INTO `ps_tab_lang` (`id_tab`, `id_lang`, `name`)
SELECT @id_tab, `id_lang`, 'Smaily for PrestaShop' FROM `ps_lang` WHERE `active` = 1;
-- Tab::add() also creates the four authorization roles of the tab.
INSERT IGNORE INTO `ps_authorization_role` (`slug`) VALUES
('ROLE_MOD_TAB_ADMINSMAILYFORPRESTASHOPAJAX_CREATE'),
('ROLE_MOD_TAB_ADMINSMAILYFORPRESTASHOPAJAX_READ'),
('ROLE_MOD_TAB_ADMINSMAILYFORPRESTASHOPAJAX_UPDATE'),
('ROLE_MOD_TAB_ADMINSMAILYFORPRESTASHOPAJAX_DELETE');

-- Hooks 1.6.x registers. The old names it passes (backOfficeHeader, footerBefore, leftColumn,
-- rightColumn) resolve through ps_hook_alias to the display* hooks below.
DELETE FROM `ps_hook_module` WHERE `id_module` = @id_module;
INSERT INTO `ps_hook_module` (`id_module`, `id_shop`, `id_hook`, `position`)
SELECT @id_module, s.`id_shop`, h.`id_hook`,
  (SELECT COALESCE(MAX(hm.`position`), 0) + 1 FROM `ps_hook_module` hm WHERE hm.`id_hook` = h.`id_hook` AND hm.`id_shop` = s.`id_shop`)
FROM `ps_hook` h CROSS JOIN `ps_shop` s
WHERE h.`name` IN ('displayBackOfficeHeader', 'displayFooterBefore', 'displayLeftColumn', 'displayRightColumn',
                   'actionFrontControllerSetMedia', 'actionCustomerAccountAdd');
