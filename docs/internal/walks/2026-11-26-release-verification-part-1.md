# Release verification, part 1 — 2026-11-26

Internal record. STATUS.md listed three things that had never run before the 3.0.0 release
candidate: a MySQL database (only MariaDB had run), Module Manager's own Uninstall and Reset buttons
(only the console had run), and an upload over a genuine 1.6.2 on the current tip. A worker ran each
for real, behind the egress guard, every service a mock.

| Item | Verdict | Evidence |
|---|---|---|
| 1. The suite on MySQL 8.0 | **HOLDS** after one fix | 2284 tests on 8.2.8 (12214 assertions) and on 9.2.0 (12219), leftovers check silent |
| 2. Module Manager's Reset and Uninstall | **HOLDS** | 8 of 8 checks on 8.2.8 and on 9.2.0 |
| 3. Upload over 1.6.2 on the tip | **HOLDS** | 10 of 10 checks on 8.2.8 |

## 1. How it ran

- **Code.** `v3` at `91acd79`. The 3.0.0 zip is the release zip (`composer --working-dir src
  package`, SHA-256 `4e841253…`, 446 files outside `vendor/`), built before the fix of item 1; the
  fix adds an `ORDER BY` to one query of the Log.
- **Shops.** Flashlight `8.2.8-8.0-nginx` (PHP 8.0.30) and `9.2.0-nginx` (PHP 8.5.12), each made
  from scratch, at most two at a time, each removed with `down -v`. Item 1 ran on the dev stack with
  `docker-compose.mysql.yaml` (`docs/DEV_ENVIRONMENT.md` 4c); items 2 and 3 on the dev stack without
  the `src/` mount (as `build/walk-upgrade/` of 2026-11-23), MariaDB, debug mode on, each shop at a
  name of its own under `.localhost`.
- **Guard.** `tests/egress-guard/install.sh` and `guard active` before the first request to the
  module, in every shop. The proxy's logs hold tunnels to the three mocks and refusals only
  (`api.github.com`, `api.prestashop-project.org`, `mbo-api.prestashop.com`, the guard's probe).
- **Browser.** The desktop app's built-in browser, signed in with the demo employee. Items 2 and 3
  pressed PrestaShop's own controls. One shortcut: the upload's file chooser was filled by a script
  of the page, which handed the release zip (served for that minute by the shop itself, then
  deleted) to the dialog's own drop zone; the dialog then sent its own request
  (`POST …/improve/modules/import`).
- **People.** Invented addresses on `example.test`; the mock's invented API user; the fixture's
  invented account `examplestore`.
- **Crashes.** No `SIGSEGV` line in the PHP-FPM log of any shop.

## 2. Item 1 — the suite on MySQL 8.0

MySQL 8.0.46 with its default global `sql_mode` (`ONLY_FULL_GROUP_BY`, `STRICT_TRANS_TABLES`, …),
binary log on, server collation `utf8mb4_0900_ai_ci`. The module installed with the console; the
whole in-shop suite with debug mode on.

| Run | 8.2.8 | 9.2.0 |
|---|---|---|
| 1 | 2284 tests, 12179 assertions, 5 errors, 1 failure | 2284 tests, 12184 assertions, 5 errors, 1 failure |
| 2 (fresh shops, after the fix) | **OK**, 12214 assertions | **OK**, 12219 assertions |

The counts of run 2 equal the MariaDB runs of 2026-11-25 with a tick state.

The six of run 1, the same on both lanes:

1. **Five errors, test environment.** `CodeIssuerTest::testACodeThatLacksARestrictionOfItsTemplateIsNotIssued`
   (five data sets) makes a trigger to fail an insert. With the binary log on, MySQL refuses a
   trigger of a user without `SUPER` ("You do not have the SUPER privilege and binary logging is
   enabled"). The module makes no trigger. The override sets `log_bin_trust_function_creators`.
2. **One failure, module.** `IngestLogTest::testRetryReturnsTheFailedIngestRowsToPendingAndLeavesTheRefusedOnesAlone`:
   `LogRows::ingestRows()` read `WHERE id IN (…)` without an order. MySQL answered through the
   covering index `entity_key_domain_status` (the nightly list's row, with an empty key, came
   first); MariaDB reads the primary key. The rows of the Log's retry came in another order.
   Fixed: the query orders by `id`.

Nothing else differed: the queue's `FORCE INDEX` with its concurrent flushes, `GET_LOCK`,
`ON DUPLICATE KEY UPDATE`, `INSERT IGNORE`, the upgrade tests and the module's tables
(`utf8mb4_general_ci`) passed as on MariaDB. MySQL 5.7 was not tried.

## 3. Item 2 — Module Manager's Reset and Uninstall

Each shop: the 3.0.0 zip installed with the console; the Initial setup walked in the browser
(account `ver1-store` on the mock, "Subscribers only", Campaign Intelligence connected through a
setup link of the engine mock: "Connected: Mock Store"); an invented address subscribed in the
storefront's newsletter block (one contact row, sent to the mock); one request to the storefront's
`land` endpoint, which made the cache folder `var/cache/dev/smaily_connect`. Before each button:
26 `SMAILY_*` keys, 10 tables with an account, a connection, 19 ingest rows, 3 jobs and a queue row,
5 tabs, 30 hooks, 24 authorization roles, the cache folder.

Reset first (on that store), then the Initial setup and the subscription again, then Uninstall with
"Optional: delete module folder after uninstall" left unticked.

| # | Check | 8.2.8 | 9.2.0 |
|---|---|---|---|
| R1 | "Yes, reset it": `POST …/manage/action/reset/smailyforprestashop` answers 200 | pass | pass |
| R2 | The module installed, 3.0.0, active; 30 hooks with debug mode on, no error | pass | pass |
| R3 | Clean: the 10 tables empty; of the keys only `SMAILY_CONNECT_SCHEMA_VERSION` and the tick state of the next request; no cache folder; the module's page opens on step 1 of the Initial setup | pass | pass |
| R4 | The subscription mock got `deactivated` (no contract error, credentials accepted), then the engine mock `POST /api/v1/store/uninstall` | pass | pass |
| U1 | "Yes, uninstall it": `POST …/manage/action/uninstall/smailyforprestashop` answers 200; the card offers Install | pass | pass |
| U2 | No `SMAILY_*` or `SMAILY_CONNECT_*` key, no module table (also no `smaily_cart`), no `ps_module` row | pass | pass |
| U3 | No tab, hook registration or authorization role of the module; no cache folder; no cart rule of the module | pass | pass |
| U4 | The subscription mock got `deactivated`, then the engine mock the uninstall call | pass | pass |

The module's files stay (446 outside `vendor/`), as the dialog's unticked box asks.

## 4. Item 3 — upload over 1.6.2 on the tip (8.2.8)

1.6.2, built from its tag as `docs/DEV_ENVIRONMENT.md` 7a says, installed by its own installer
(the console, debug mode off for that command); part 1 of `1.6.2-configured.sql` loaded. Before the
upload: module 1.6.2, 17 legacy keys, the plain-text password in one row, three `smaily_cart` rows,
the hidden tab `AdminSmailyforprestashopAjax`, 6 hooks, 27 files. 1.6.2's own RSS feed answered the
legacy feed address (`controller=SmailyRssFeed&limit=100&sort_by=price&sort_order=asc&category_id=3`)
with two products.

| # | Check | Result |
|---|---|---|
| L1 | Module Manager > Upload a module with the 3.0.0 zip, debug mode on | "Module installed!", `POST …/improve/modules/import` 200 |
| L2 | The module 3.0.0, active | pass |
| L3 | `Upgrade300` ran: `SMAILY_CONNECT_MIGRATION` `done` from `1.6.2`, its 15 steps, no failure; schema version 3.0.0 | pass |
| L4 | Of the legacy keys only the two cron tokens, each with its old value (compared by hash); no row holds the plain-text password | pass |
| L5 | The settings carried over: account `examplestore` with its API user; contact sync on, "Subscribers only", fields `store, first_name, last_name, birthday, website`; welcome on with workflow 101; abandoned cart on, workflow 102, 45 minutes; RSS category 3, limit 100, price ascending; the three reminded carts as `mailed` rows; `smaily_cart` kept | pass |
| L6 | The customer cron URL with its old token runs the tick ("OK: ran … contacts_reconcile, …") | pass |
| L7 | The cart cron URL with its old token runs ("OK: ran installation_report, migration."); both refuse a wrong token ("Access denied!", HTTP 403) | pass |
| L8 | The legacy RSS address answers 200 with the same two products, prices and order as 1.6.2 | pass |
| L9 | No file of `Migration\LegacyFiles::FILES` left, and its folders gone; the module's folder is the zip's files plus `translations/et.php` (kept on purpose: a merchant's own translations) and `vendor/.htaccess` | pass |
| L10 | The 1.6.x tab and hooks gone (5 tabs, 30 hooks); the Dashboard opens without a cache removal, with two notices: the settings carried over, and the 1.6 newsletter block that is gone | pass |

## 5. Defects

1. **Fixed: the Log's ingest rows came in index order on MySQL** (`LogRows::ingestRows()`). Seen as a
   test failure; a merchant would see at most another order of the rows a retry names.

## 6. Observations (no defect claimed)

1. **The shop image's database client and MySQL 8.** The flashlight images restore their demo dump
   with MariaDB's client, which has no `caching_sha2_password` plugin; the 9.2.0 image's client also
   refuses MySQL's self-signed certificate. The override works around both; PHP itself needs neither.
2. **Right after a Reset**, PrestaShop's copy `AdminSmailyConnect_MTR` is the parent of the module's
   tabs (6 tabs, 28 authorization roles) until the first page of the module runs `Admin\Menu`; the
   menu shows one "Smaily Connect" whose link opens the module. A console install leaves 5 tabs.
3. **The legacy 1.6.2 under PrestaShop 8.2.8** shows its deprecation of `backOfficeHeader` on every
   back-office page with debug mode on, until the upgrade removes the hook.
