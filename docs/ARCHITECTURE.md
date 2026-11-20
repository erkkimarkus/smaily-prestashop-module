# Architecture of Smaily Connect for PrestaShop 3.0.0

This document is the architecture proposal for version 3.0.0 of the module `smailyforprestashop`.
It was written on 2026-11-20, before any 3.0.0 code. Workers build to it. The product owner confirms
the design-level choices that section 15 lists.

## How to read this document

Every direction carries one of three markers.

| Marker | Meaning | Who can change it |
|---|---|---|
| **[D]** | A decision that this document takes on the evidence. It binds every worker. | The orchestrator, with a note in this file. |
| **[R]** | A recommendation. The owner confirms it or picks another option. Until the owner answers, workers build nothing that rests on it. | The owner. |
| **[O]** | An open question. The answerer is named in section 14. | The named answerer. |

Short names for sibling sources:

| Short name | Path |
|---|---|
| `magento:` | `/Users/erkkimarkus/Downloads/smaily/connect-magento/` (Smaily Connect for Magento, 3.0.0, the closest analog: a PHP module rewritten in place with a legacy upgrade) |
| `woo:` | `/Users/erkkimarkus/Downloads/smaily/connect-woo/` (Smaily Connect for WooCommerce, the reference for platform-neutral parts) |
| contract | `docs/RECENGINE_API_CONTRACT.md` v1.34.0, a byte copy of `magento:docs/RECENGINE_API_CONTRACT.md` |
| report contract | `/Users/erkkimarkus/Downloads/smaily/subscription/docs/contracts/installation-report-v1.md` v1.0.1 |

A sentence about "the module today" describes 2.2.0 in `src/`. A sentence about "3.0.0" describes the
product that this document proposes.

## Repositories

- `smaily-prestashop-module` (this repository). All 3.0.0 code lands here.
- `subscription` (Smaily subscription service). It must register the product id `connect_prestashop` and
  the platform `prestashop` before the module sends its first installation report (section 11).
- The Campaign Intelligence engine (`re`). It must agree the product key prefix and the shop code
  (section 8). It changes no code for 3.0.0 unless it rejects a proposal here.

## 0. The module today: facts that the design rests on

| Fact today (2.2.0) | Source | What 3.0.0 does with it |
|---|---|---|
| Technical name `smailyforprestashop`, class `SmailyForPrestaShop`, `module_key` `bcea90ce…`, namespace `PrestaShop\Module\SmailyForPrestaShop\` (PSR-4 on `src/src/` and `src/lib/`). | `src/smailyforprestashop.php`, `src/composer.json` | Kept. |
| Three hooks: `actionCustomerAccountAdd`, `actionCustomerAccountUpdate`, `actionNewsletterRegistrationAfter`. Each calls Smaily synchronously inside the shopper's request. | main class, `OptInController` | Replaced by the event queue (section 7). |
| Two token-protected cron front controllers: `SmailyCustomerCron` (pulls all unsubscribers, then pushes every `customer.newsletter=1` and every active `emailsubscription` row) and `SmailyCartCron` (reminds each customer with a cart from the last 10 days, no order, not in `smaily_cart`). | `controllers/front/`, `CustomerSyncController`, `AbandonedCartController` | Both URLs keep working (section 4). |
| The contact push has no shop filter. It sends the customers of every shop with the config of the shop whose URL the crontab calls. The cart query uses `Shop::addSqlRestriction(SHARE_CUSTOMER)`. | `SubscriberCollection`, `AbandonedCartCollection` | The migration reads the default shop's values for the two cron features (section 3). |
| Table `smaily_cart (id_smaily_cart, id_customer, id_cart, date_sent)`. A row means "reminded". | `Install/Installer.php` | Carried into the new cart tracker (section 3). |
| `SMAILY_PASSWORD` is stored in plain text. | `AccountFormDataConfiguration` | Encrypted, the plain row deleted (section 2). |
| 2.x saves its settings in the admin's current shop context, so a multishop store can hold `SMAILY_*` rows at global, shop-group and shop scope. | `Form/*FormDataConfiguration.php` | The mapper reads every scope (section 3). |
| The cron token check is `!==`. A stored empty token accepts a request with no token. The 1.6.x installer stored `''` as both tokens. | `CustomerSyncController::sync()`, 1.6.2 `install()` | An empty token never authorizes (section 3). |
| Enabling opt-in sets `NW_VERIFICATION_EMAIL=0`, `NW_CONFIRMATION_EMAIL=0`, `NW_VOUCHER_CODE=null` in `ps_emailsubscription`. The previous values are not kept. | `CustomerSyncFormDataConfiguration` | Left as they are (section 3). |
| Install copies `overrides/ps_emailsubscription.php` only when no override exists. Uninstall deletes that path always, also when another module owns the file. | `Installer::copyOverrides()`, `deleteOverrides()` | Removed only when the file is ours (section 3). |
| The opt-in with no workflow posts `addresses` without `autoresponder` to `api/autoresponder.php`. Smaily then starts every "subscriber opted in" workflow. | `Lib\Api::optInSubscribers()` | Kept for upgraded stores (section 3, decision 5). |
| Abandoned-cart payload: `email`, `is_abandoned_cart`, `force_opt_in=false`, optional `first_name`/`last_name`, `product_{name,description,sku,price,base_price,quantity}_1..10` (empty strings for unused slots), `over_10_products`. Prices are formatted strings in the context currency. | `AbandonedCartController::generatePayload()` | Kept byte-compatible (section 7). |
| Smaily HTTP: Symfony HttpClient, which PrestaShop core ships; no runtime Composer package. | `lib/Api.php`, CHANGELOG 2.2.0 | Kept (section 1). |
| 2.0.0 added no upgrade script from 1.6.x. A 1.6.x store moved to 2.x keeps 1.6.x rows: `SMAILY_ENABLE_CRON` (2.x reads `SMAILY_ENABLE_CUSTOMER_SYNC`), PHP-serialized field lists (2.x calls `json_decode()`). | git tags `1.6.2`, `2.0.0` | See the next row. |
| On such a store the 2.x code sends the opt-in, sends no contact sync (the switch it reads is absent), and stops with a `TypeError` in the cart cron (`array_filter(null)` on PHP 8), so it sends no reminder. | reading of 2.2.0 code against 1.6.2 rows | The migration switches on only what the running code sent (section 3, decision 4). |
| The 1.6.x module itself installs on PrestaShop 8 (`ps_versions_compliancy.max = _PS_VERSION_`). A store can upgrade from 1.6.x code straight to 3.0.0. | 1.6.2 `smailyforprestashop.php` | The mapper takes the previous code version as input (section 3). |

---

## 1. Module structure

### 1.1 Layout and namespaces

**[D]** The module folder stays `src/` in the repository and `smailyforprestashop/` in the zip. The
namespace stays `PrestaShop\Module\SmailyForPrestaShop\`, mapped to `src/src/`. `src/lib/` is ported
into `src/src/Smaily/` and deleted.

```
src/
  smailyforprestashop.php     main class: identity, install/uninstall, one-line hook methods
  upgrade/upgrade-3.0.0.php   PrestaShop upgrade entry point (section 3)
  config/services.yml         admin and front container: exposes the runtime services
  config/admin/services.yml   admin controllers, form handlers
  config/routes.yml           admin routes
  controllers/front/          SmailyCustomerCron, SmailyCartCron, SmailyRssFeed (kept names),
                              tick, relay, land, recommendations, personalization
  src/
    Runtime/                  Services (composition root) + Provider/<Area>Provider.php
    Hook/                     Dispatcher: hook name -> listeners from the providers
    Install/                  Installer, Schema, HookList, Uninstaller
    Config/                   Settings base + one settings class per section
    Migration/                LegacyReader, LegacyConfigMapper (pure), Upgrade300
    Http/                     Transport (Symfony HttpClient behind a module interface)
    Smaily/                   Client, ApiException, AccountStore, Crypto
    Queue/                    EventQueue, RetryPolicy, Failure, Flusher, HandlerInterface
    Scheduler/                Tick, JobRegistry, Lock, TickState
    Scope/                    StoreLayout, ShopContext, LanguageResolver, AccountResolver
    Contacts/                 ContactSyncMode, ContactAudience, SubscriberPayloadBuilder,
                              Reconciler, Import/
    Automation/               Router, MappingRepository, Welcome, FirstOrder, AbandonedCart/
    Engine/                   Client, Connections, Setup, Payload/, IngestQueue, History/,
                              Automations/
    Storefront/               Relay, Attribution, Recommendations, PageContext
    Privacy/                  Export, Erasure, ProfilingConsent
    Report/                   ReportBuilder, ReportSender, InstallationReport, ConnectionHealth
    Rss/                      RssFeed (ported from Controller/RssFeedController)
    Log/                      LogQuery, Details, Retry
    Admin/                    BaseController, Dashboard/, Setup/, Settings/, Log/, Api/
  views/templates/admin/      layout/, dashboard/, setup/, settings/, log/ (Twig)
  views/templates/front/      recommendations, personalization (Smarty, as PrestaShop front)
  views/js/admin/, views/js/front/, views/css/
  translations/               XLIFF, domains Modules.Smailyforprestashop.Admin / .Shop
```

### 1.2 Service wiring on PrestaShop 8 and 9

**[D]** Runtime code (queues, clients, routers, builders, the tick) is plain PHP. One hand-written
composition root, `Runtime\Services`, builds it lazily. Each area adds its services in its own
`Runtime/Provider/<Area>Provider.php`. Hooks, front controllers, the upgrade script and the admin
controllers all reach the runtime through `Runtime\Services`. `config/services.yml` exposes the same
objects to the Symfony container for admin controllers (factory service), so there is one instance
graph.

- **Rationale.** The upgrade script, the hooks and the front controllers run without a guaranteed
  Symfony container of the module: PrestaShop 8 and 9 build different front containers, and an
  upgrade runs in whatever context PrestaShop calls it. The Woo plugin's runtime is plain PHP too, so
  its classes port with no DI rewrite. Magento uses `di.xml` because Magento has one container
  everywhere; PrestaShop has not.
- **Alternatives.** (a) Symfony DI for everything: the upgrade script and front controllers then
  depend on container behaviour that differs between 8 and 9. (b) Static helpers as in 2.2.0: not
  testable.

**[D]** Hooks. The main class declares one method per hook in `Install\HookList`. Each method is one
line: `return Hook\Dispatcher::dispatch('<hookName>', $params);`. Listeners register per area in the
providers. Only Story 1.1 edits the main class. Later stories register listeners in their own
provider.

### 1.3 Code ported from the siblings

**[D]** Port by translation, not by copy of framework code. Keep class names and method names of the
source where they exist, so a reader can diff behaviour.

| 3.0.0 area | Port from | What changes |
|---|---|---|
| `Smaily/Client`, `ApiException` (envelope 101, 203 permanent, 206, 227) | `magento:Model/Client/SmailyClient.php` + `ApiException` | Symfony HttpClient instead of Guzzle. |
| `Queue/*` (retry ladder 60 s / 5 min / 15 min / 1 h, 5 attempts, `Failure::of()`, 429 `Retry-After`) | `magento:Model/Queue/*`, ARCHITECTURE "Queue semantics" | `Db` instead of Magento resource models. |
| `Contacts/ContactSyncMode`, `ContactAudience` | `woo:includes/Smaily/ContactSyncMode.php`, `ContactAudience.php` | WordPress meta reads become `customer.newsletter` / `emailsubscription` reads. |
| `Contacts/SubscriberPayloadBuilder` | `magento:Model/ContactSync/*` + `woo:spec/FIELD_MAPPING.md` | Field sources from PrestaShop tables; `store` keeps the 2.x value (shop domain). |
| `Automation/Router` | `magento:Model/Automation/Router*`, `woo:includes/Multilingual/Router.php` | Website becomes shop. |
| `Automation/AbandonedCart/*` | `magento:Model/AbandonedCart/*`, `woo:includes/Smaily/CartFlusher.php` (consent gate) | Payload stays the 2.x shape. |
| `Engine/Client`, `Setup`, `Payload/*`, `IngestQueue`, `History/*` | `woo:includes/Smaily/RecEngine/*`, `magento:Model/Engine/*` | PrestaShop product, customer and order reads. |
| `Report/*` | `magento:Model/Report/ReportSender.php`, `ReportBuilder`, `InstallationReport`, `ConnectionHealth` | Product id `connect_prestashop`. |
| `views/js/front/{consent,attribution,tracker,recommendations}.js` | `magento:view/frontend/web/js/*.js` | AMD `define()` becomes an IIFE (section 8.6). |
| Engine mock for tests | `woo:tests/Integration/Fixtures/RecEngineMockServer.php`, `mock-rec-engine/` | None. |

### 1.4 Composer and the release zip

**[D]** No runtime Composer package. Composer generates only the autoloader, as today
(`bin/package.sh` runs `composer install --no-dev`; `prepend-autoloader: false`). The zip is
self-contained.

- HTTP: Symfony HttpClient from PrestaShop core, as 2.2.0 does on 8 and 9. The module codes against
  `Symfony\Contracts\HttpClient\HttpClientInterface` only, behind `Http\Transport`. (to verify in Story
  1.2: present on PrestaShop 8.0.0)
- Encryption: `PhpEncryption` from PrestaShop core (section 2.3).

### 1.5 PrestaShop 8 and 9 differences that matter

| Area | PrestaShop 8.x | PrestaShop 9.x | Decision |
|---|---|---|---|
| PHP | 7.2–8.1 (module floor 8.0) | 8.1+ | **[D]** Code to PHP 8.0 syntax (no enums, no `readonly`). CI lints on 8.0. No hard reason to raise the floor was found. |
| Symfony | 4.4 | 6.4 | **[D]** Admin controllers extend `FrameworkBundleAdminController` through `Admin\BaseController`, as 2.2.0 does on 9. (to verify in Story 1.2 that 9.x keeps it) |
| Twig | 2/3 | 3 | **[D]** Twig 3 syntax that Twig 2 also parses. |
| Product page | legacy page (8.0), v2 page optional (8.1+) | v2 page default | **[D]** Catalog capture listens to ObjectModel hooks, which both pages fire (to verify in Story 1.2). |
| Front theme | Classic (jQuery) | Hummingbird (no jQuery) or Classic | **[D]** Front JS is vanilla. Cart events come from the `prestashop` event emitter that both themes carry. |
| DB access | Doctrine DBAL 2/3, `Db` | DBAL 3, `Db` | **[D]** `Db::getInstance()` everywhere. |
| Translations | new system, XLIFF | same | **[D]** XLIFF in `translations/`, as 2.2.0. |

---

## 2. Persisted state

### 2.1 Configuration keys

**[D]** New keys carry the prefix `SMAILY_CONNECT_`. The module never deletes by `LIKE 'SMAILY_%'`:
every key list is explicit, because the legacy prefix `SMAILY_` also matches the new prefix.

**[D]** Scope rule: the module writes a key at the global scope or at one shop's scope, never at a
shop group's scope. It always passes the scope explicitly and never relies on the admin's shop
context. A read resolves shop, then global (PrestaShop's own fallback). This mirrors Magento's
default + website scopes (`magento:docs/ARCHITECTURE.md` "Multi-website").

| Key | Scope | Content |
|---|---|---|
| `SMAILY_CONNECT_SCHEMA_VERSION` | global | The schema version the upgrade completed. Runtime code is inert while it differs from the code version. |
| `SMAILY_CONNECT_SETUP_STATE` | global | `new`, `upgraded` or `finished` (section 10). |
| `SMAILY_CONNECT_MIGRATION` | global | JSON: the step markers of the 3.0.0 migration (section 3.7). |
| `SMAILY_CONNECT_NOTICES` | global | JSON: admin notices the migration left. |
| `SMAILY_CONNECT_SEPARATE_STORES` | global | `0` one store in several versions, `1` separate stores. |
| `SMAILY_CONNECT_LANGUAGE_MODE`, `_FALLBACK_LANGUAGE` | global or shop | `single`, `a`, `b`, `c`; the fallback language's ISO code. |
| `SMAILY_CONNECT_CONTACTS_ENABLED`, `_CONTACTS_MODE`, `_CONTACTS_GUESTS`, `_CONTACTS_FIELDS`, `_CONTACTS_CONFIRMED`, `_CHECKOUT_CHECKBOX` | global or shop | Contacts section (section 6). |
| `SMAILY_CONNECT_WELCOME_ENABLED`, `_FIRST_ORDER_ENABLED`, `_CART_ENABLED`, `_CART_DELAY_MINUTES` | global or shop | Automations section. Workflows live in the mapping table. |
| `SMAILY_CONNECT_RSS_CATEGORY`, `_RSS_LIMIT`, `_RSS_SORT_BY`, `_RSS_SORT_ORDER` | global | The RSS URL builder's defaults. |
| `SMAILY_CONNECT_TICK_TOKEN`, `_TICK_STATE`, `_FALLBACK_TICK` | global | Section 4. |
| `SMAILY_CONNECT_CONSENT_*` | global or shop | Only if the owner picks option C in section 9. |
| `SMAILY_CONNECT_HEALTH`, `_REPORT_STATE` | global | Section 11. |
| `SMAILY_CUSTOMER_CRON_TOKEN`, `SMAILY_CART_CRON_TOKEN` | as stored | **Kept with their legacy names.** They are the URL contract in merchants' crontabs. |

### 2.2 Tables

**[D]** All tables use `_DB_PREFIX_` + the names below, `ENGINE=_MYSQL_ENGINE_`, `utf8mb4`. Story 1.1
creates every table in `Install\Schema`, so later stories change no installer code. A later column
change is a new upgrade script, never an edit of an applied one.

| Table | Purpose | Columns (level of detail for review) | Indexes |
|---|---|---|---|
| `smaily_connect_event_queue` | Marketing events to Smaily: `contact.sync`, `automation.trigger`, `engine.identity_merge`, `engine.profiling_consent` | `id` BIGINT PK, `id_shop` INT, `event_type` VARCHAR(48), `entity_id` VARCHAR(64) (address, or its keyed hash when longer, as `magento:Model/Queue/ContactEntity`), `language` CHAR(2) NULL, `payload` MEDIUMTEXT JSON, `status` VARCHAR(16) (`pending`, `processing`, `sent`, `failed`, `skipped`), `attempts` TINYINT, `next_retry_at` DATETIME, `claim_token` CHAR(32) NULL, `last_error` VARCHAR(255), `last_request` MEDIUMTEXT, `last_response` TEXT, `created_at`, `updated_at`, `sent_at` | (`status`,`next_retry_at`), (`entity_id`,`event_type`,`status`), (`created_at`) |
| `smaily_connect_ingest_queue` | Engine ingest: `catalog`, `catalog_remove`, `customers`, `orders` | `id` BIGINT PK, `id_connection` INT, `domain` VARCHAR(16), `entity_key` VARCHAR(80), `payload` MEDIUMTEXT NULL (a catalog row is a marker; the payload is built at flush, see `woo:docs/LESSONS.md` §2.24), status/attempt/claim/error columns as above | (`domain`,`status`,`next_retry_at`), (`entity_key`,`domain`,`status`), (`created_at`) |
| `smaily_connect_job` | Imports: contacts import, catalog import, history import, daily refresh | `id` PK, `type` VARCHAR(32), `id_shop` INT, `id_connection` INT NULL, `status` (`queued`,`running`,`done`,`failed`,`cancelled`), `cursor` VARCHAR(255), `counts` TEXT JSON, `import_id` VARCHAR(64) NULL, `started_at`, `updated_at`, `finished_at`, `last_error` | (`status`,`type`) |
| `smaily_connect_account` | Smaily accounts per shop and language | `id` PK, `id_shop` INT (0 = all shops), `language` CHAR(2) NULL (NULL = the shop's account or the fallback account in mode A), `subdomain` VARCHAR(63), `username` VARCHAR(128), `password_enc` TEXT, `date_add`, `date_upd` | UNIQUE (`id_shop`,`language`) |
| `smaily_connect_automation_mapping` | (shop, trigger, language, account) → workflow | `id` PK, `id_shop` INT (0 = all shops), `trigger` VARCHAR(32) (`welcome`,`first_order`,`abandoned_cart`), `language` CHAR(2) NULL, `account_key` VARCHAR(16) (`default` or a language), `workflow_id` INT NULL (NULL with `optin_trigger=1` = the 2.x generic opt-in, decision 5), `optin_trigger` TINYINT, `is_default_fallback` TINYINT, `date_upd` | UNIQUE (`id_shop`,`trigger`,`language`,`account_key`) |
| `smaily_connect_cart` | Abandoned-cart tracker and reminder state | `id_cart` INT PK, `id_shop` INT, `id_customer` INT NULL, `email` VARCHAR(255) NULL, `id_lang` INT, `status` VARCHAR(16) (`open`,`mailed`,`skipped`,`completed`,`expired`,`erased`), `last_activity_at` DATETIME, `mailed_at` DATETIME NULL, `checkout_optin` TINYINT, `event_id` BIGINT NULL, `skip_reason` VARCHAR(64) NULL | (`status`,`last_activity_at`), (`email`) |
| `smaily_connect_connection` | Campaign Intelligence connections | `id` PK, `id_shop` INT (0 = installation-wide), `smaily_subdomain` VARCHAR(63), `tenant_id` CHAR(36), `api_key_enc` TEXT, `settings` MEDIUMTEXT JSON (tenant name, base URL, engine version, endpoints map, `config` incl. `history` and `connection.prefix`, `issued_at`, `refused_at`, browse switch), `date_add`, `date_upd` | UNIQUE (`id_shop`) |
| `smaily_connect_order_attribution` | Email-click attribution per order | `id_order` INT PK, `rec_id` CHAR(36) NULL, `context` VARCHAR(64) NULL, `visitor_token` VARCHAR(64) NULL, `created_at` | — |
| `smaily_connect_privacy` | Durable profiling opt-out record (contract §10; `magento:` parity audit R1) | `address_key` CHAR(64) PK (keyed hash of the trimmed lower-case address), `opted_out` TINYINT, `changed_at`, `source` VARCHAR(16) | — |

**Rationale for an account table instead of language-scoped configuration.** PrestaShop's
language-scoped configuration keys by `id_lang`, which differs between installations; an account is
three values that change together; and the admin must list which shops hold own accounts. A table
answers all three with one query.

**[D]** No separate log table. The Log screen reads the two queue tables and the job table, as
Magento's unified Log does (`magento:Controller/Adminhtml/Log/*`).

### 2.3 Secrets

**[D]** The Smaily API password and the engine API key are stored encrypted with PrestaShop's
`PhpEncryption` (defuse/php-encryption, bundled in core) keyed by `_NEW_COOKIE_KEY_`. `Smaily\Crypto`
is the one place that encrypts and decrypts. (to verify in Story 1.2: the class and the constant on
8.0 and 9.x)

- A value that does not decrypt (for example after a move to a server with a new cookie key) reads as
  "no credentials". The admin shows "Reconnect the account". It never throws into a hook.
- No secret enters a log line, a queue row, the Log screen or the installation report.

### 2.4 What happens to each legacy key and table

| Legacy item | 3.0.0 |
|---|---|
| `SMAILY_SUBDOMAIN`, `SMAILY_USERNAME` | Migrated into `smaily_connect_account`, then deleted. |
| `SMAILY_PASSWORD` | Encrypted into `smaily_connect_account`. Deleted only after a read-back decrypts to the same value. |
| `SMAILY_ENABLE_CUSTOMER_SYNC`, `SMAILY_ENABLE_CRON` (1.6.x), `SMAILY_SYNCRONIZE_ADDITIONAL`, `SMAILY_OPTIN_ENABLED`, `SMAILY_OPTIN_AUTORESPONDER`, `SMAILY_ENABLE_ABANDONED_CART`, `SMAILY_CART_AUTORESPONDER`, `SMAILY_ABANDONED_CART_TIME`, `SMAILY_CART_SYNCRONIZE_ADDITIONAL` | Migrated (section 3), then deleted. |
| `SMAILY_RSS_CATEGORY_ID`, `_LIMIT`, `_SORT_BY`, `_SORT_ORDER` | Migrated to `SMAILY_CONNECT_RSS_*`, then deleted. |
| `SMAILY_CUSTOMER_CRON_TOKEN`, `SMAILY_CART_CRON_TOKEN` | **Kept and read.** An empty value is replaced by a new random token and a notice (section 3.4). |
| Table `smaily_cart` | Rows copied into `smaily_connect_cart` as `mailed`. **[D]** The table stays untouched in 3.0.0 and is dropped in a later release, so a manual reinstall of 2.x does not resend reminders. |
| `override/modules/ps_emailsubscription/ps_emailsubscription.php` | Deleted only when its content equals a 2.x copy of the module's file (hash list). Otherwise left, with a notice. |
| `NW_VERIFICATION_EMAIL`, `NW_CONFIRMATION_EMAIL`, `NW_VOUCHER_CODE` | Not touched. 3.0.0 never writes them. Settings > Contacts shows a note when `ps_emailsubscription`'s own emails are on while the welcome automation is on. |
| Hooks registered by 1.6.x (`footerBefore`, `leftColumn`, `rightColumn`, `backOfficeHeader`, `actionFrontControllerSetMedia`) or 2.x | The upgrade unregisters every hook that `Install\HookList` does not name, then registers the 3.0.0 list. |

---

## 3. The legacy → 3.0.0 migration

### 3.1 Shape

**[D]** Two parts, as in Magento (`magento:Model/Migration/LegacyConfigMapper.php`,
`magento:Setup/Patch/Data/MigrateLegacyConfig.php`):

1. `Migration\LegacyReader` reads raw state: every `SMAILY_*` row of `configuration` with its
   (`id_shop_group`, `id_shop`), the shop tree, `smaily_cart`, the previous module version from
   `module.version`, and the override file's hash. It writes nothing.
2. `Migration\LegacyConfigMapper::map(LegacyState): MigrationPlan` is pure: no PrestaShop class, no
   database, no clock. It returns the settings per scope, the account rows, the mapping rows, the
   notices and the keys to delete. Unit tests run it on the fixtures in `tests/fixtures/legacy/`.
3. `Migration\Upgrade300` applies the plan. `upgrade/upgrade-3.0.0.php` calls it from
   `upgrade_module_3_0_0($module)`.

### 3.2 Inputs the mapper accepts

| Input | 2.x format | 1.6.x format |
|---|---|---|
| Field lists | JSON map `{"first_name": true, …}` | PHP-serialized list `["first_name", …]`. Read with `unserialize($v, ['allowed_classes' => false])`; a value that does not decode is an empty list plus a notice. |
| Contact sync switch | `SMAILY_ENABLE_CUSTOMER_SYNC` | `SMAILY_ENABLE_CRON` |
| Booleans | `'1'`, `'0'`, `''`, absent | same |
| Previous code version | `2.0.0`–`2.2.0` | `1.x` |

### 3.3 Inference rules

**[D]** The mapper first computes, per shop, the **effective 2.x value** of each key exactly as the
previous code resolved it in that shop's context: shop row, else shop-group row, else global row.
It then derives what that code **actually sent** in that shop, and switches on only that. This
answers the class of Magento's release-candidate defects, which came from inherited values switching
features on at scopes that never sent them (`magento:docs/UPGRADING.md` "What migrates
automatically").

| 3.0.0 setting | On when (per shop) | Notes |
|---|---|---|
| Account | Subdomain, username and password are all non-empty. | Shops whose effective account equals the global one get no own row. |
| Contact sync | Previous code 2.x: `SMAILY_ENABLE_CUSTOMER_SYNC` true. Previous code 1.x: `SMAILY_ENABLE_CRON` = `1`. In both: an account exists. | Read at the **default shop** (`PS_SHOP_DEFAULT`): the cron URL in the crontab decides the shop context, and the module cannot see the crontab. A notice says so on a multishop store. `SMAILY_CONNECT_CONTACTS_CONFIRMED` is set where sync is on (Magento PRO-4036). |
| Consent mode | Always `subscribers` (consent) with guests on. | 2.x synced `customer.newsletter=1` plus active `emailsubscription` rows. This is the narrowest mode that covers it. |
| Contact fields | The legacy list, with `store` always on. | Unknown names are dropped. |
| Welcome | `SMAILY_OPTIN_ENABLED` = `1` and an account exists. | Workflow = `SMAILY_OPTIN_AUTORESPONDER`; when empty, the generic opt-in trigger row (decision 5). |
| First order | Off. | Did not exist. |
| Abandoned cart | Previous code 2.x: `SMAILY_ENABLE_ABANDONED_CART` true, `SMAILY_CART_AUTORESPONDER` set, an account exists, **and** `SMAILY_CART_SYNCRONIZE_ADDITIONAL` is valid JSON. Previous code 1.x: the first three. | The JSON condition reproduces the `TypeError` that stopped 2.x on 1.6.x rows (section 0). Such a shop gets the automation off and a notice (decision 4). Delay = `SMAILY_ABANDONED_CART_TIME` minutes, at least 15. Read at the default shop, as contact sync. |
| Language mode | `single`. | 2.x had one account. |
| Shop layout | Separate stores when no global account exists and at least two shops hold different own subdomains. Otherwise one store. | Magento `WebsiteOverrides::holdsSeparateAccounts()`. |
| Setup state | `upgraded` when an account exists, else `new`. | Section 10. |

### 3.4 Continuity of URLs

| URL | 3.0.0 |
|---|---|
| `…?fc=module&module=smailyforprestashop&controller=SmailyCustomerCron&token=<customer token>` | Runs the whole tick (section 4). Answers `text/plain` as today. |
| `…controller=SmailyCartCron&token=<cart token>` | Same. |
| `…controller=SmailyRssFeed&limit=&sort_by=&sort_order=&category_id=` | Same output contract and parameters. New parameters are additive only. |
| Friendly URL forms of the three | Unchanged: the controller names stay. |

**[D]** A request whose token is empty, or whose stored token is empty, is refused. The upgrade gives
an empty legacy token a new random value and adds a notice with the new URL. This closes the 2.x
hole in section 0. It changes the URL of the stores that it affects; those stores had an
unauthenticated cron URL.

### 3.5 Already-reminded state

**[D]** Every `smaily_cart` row becomes a `smaily_connect_cart` row with `status = mailed`,
`mailed_at = date_sent`. A cart that has a legacy row is never reminded again. A customer whose
legacy row is younger than 10 days is not reminded for another cart in that window (the 2.x query
grouped by customer). This keeps "nobody gets a duplicate reminder because of the upgrade"
(Magento UPGRADING "What is cleaned up").

### 3.6 Admin notice

**[D]** The migration writes its notices to `SMAILY_CONNECT_NOTICES`. Every module admin page shows
them, and `displayBackOfficeHeader` shows one line with a link on other admin pages until the
merchant dismisses it. Notice texts: settings migrated; automations kept off and why; cron URL
changed (empty token); override file left in place; multishop cron context assumed; the 1.6.x
newsletter block is gone.

### 3.7 Failure behaviour

**[D]** The migration is idempotent and ordered. Each step records its marker in
`SMAILY_CONNECT_MIGRATION` after it succeeds and is skipped when its marker exists.

1. Create tables (`CREATE TABLE IF NOT EXISTS`).
2. Write new settings and account rows. Legacy rows stay.
3. Copy cart rows (`INSERT IGNORE`).
4. Seed mapping rows.
5. Swap hooks.
6. Remove the owned override file.
7. Verify: every account row decrypts; every new key reads back.
8. Delete legacy keys (not the two tokens). Write `SMAILY_CONNECT_SCHEMA_VERSION`.

- A failure returns `false` from `upgrade_module_3_0_0()`. PrestaShop then keeps the old version
  number and offers the upgrade again. Legacy keys are still there, so the next run starts from the
  same input.
- While `SMAILY_CONNECT_SCHEMA_VERSION` differs from the code version, every hook listener and the
  tick do nothing, and the admin shows "Finish the upgrade". This covers files copied over FTP
  before PrestaShop ran the upgrade (`woo:docs/LESSONS.md` §2.31: new code on the old schema).
  Legacy crons then do nothing until the upgrade runs; the notice says so.

### 3.8 Zip upgrade and marketplace upgrade

| Path | What PrestaShop does | 3.0.0 |
|---|---|---|
| Module Manager → Upload a module (zip of 3.0.0 over 2.x) | Replaces the files and runs the upgrade scripts newer than the stored version. (to verify in Story 1.2 on 8.0 and 9.x) | Same migration. |
| Module Manager → Upgrade (marketplace) | Same. | Same. |
| Files copied by FTP | Runs the upgrade when an admin opens the module list or the module. | Inert runtime until then (3.7). |
| Uninstall 2.x, install 3.0.0 | 2.x uninstall deletes all `SMAILY_*` keys and `smaily_cart`. | A new install. UPGRADING.md warns against this path. |

---

## 4. Scheduling without a platform scheduler

PrestaShop has no scheduler. Woo has Action Scheduler and Magento has cron; the module must bring
its own tick.

### 4.1 Jobs

| Job | Interval | Time budget per run | Sibling |
|---|---|---|---|
| Event queue flush | 1 min | 20 s | `magento:Cron/FlushEventQueue` |
| Ingest queue flush | 1 min | 25 s | `FlushIngestQueue` |
| Import chunk (contacts, catalog, history, daily refresh) | 1 min | 15 s | `BackfillTick` |
| Abandoned-cart sweep | 5 min | 10 s | `AbandonedCart` |
| Contact reconcile from Smaily | 15 min | 15 s | `ContactReconcile` |
| Daily contact refresh | daily | as import chunks | `woo:` daily refresh |
| Health check | 15 min | 5 s | `HealthCheck` |
| Janitor (retention) | daily | 10 s | `QueueJanitor` |
| Catalog manifest (§3c) | daily, 03:30 shop time | as import chunks | `SendCatalogManifest` |
| Installation report | due check every tick | 5 s | `SendInstallationReport` |

### 4.2 Options

| Option | For | Against |
|---|---|---|
| A. Token-protected cron URL as the single tick | Already in merchants' crontabs; precise; runs outside shopper requests. | Many merchants never set a crontab; 2.x merchants may call it daily, not every minute. |
| B. Traffic-triggered fallback tick | Works with no setup. | Runs only when visitors come; must not slow a page; needs locks. |
| C. `ps_cronjobs` module (`actionCronJob`) | Some stores have it. | Its finest frequency is hourly (to verify); it still needs a crontab or a web cron. |
| D. Hosted pinger (a Smaily service calls the tick URL) | No merchant setup. | Needs Smaily infrastructure and the store's tick token; not in any agreed scope. |

### 4.3 Recommendation

**[R]** (decision 1) A + B, with A as the primary tick:

1. **One tick, many doors.** New URL `controller=tick&token=<SMAILY_CONNECT_TICK_TOKEN>`. The two
   legacy URLs with their own tokens run the same tick. Every door calls `Scheduler\Tick::run($source,
   $budget)`. The tick runs every job that is due, in the table order, within the budget:
   `min(50 s, max_execution_time − 5 s)` for a URL request.
2. **Traffic fallback.** `actionFrontControllerSetMedia` and `actionAdminControllerSetMedia` read
   `SMAILY_CONNECT_TICK_STATE` (PrestaShop loads all configuration at bootstrap, so the read costs no
   query). When the last tick is older than 60 s, the listener registers a shutdown function. At
   shutdown it calls `fastcgi_finish_request()` (or `litespeed_finish_request()`), so the response is
   already sent, and runs the tick with a 10 s budget. Where neither function exists, the fallback
   does not run, and the Dashboard says that a cron line is needed.
3. **Enqueue flush.** A request that enqueued an `automation.trigger` or a `contact.sync` row runs the
   event-queue flush at its own shutdown in the same non-blocking way, whatever the 60 s gate says.
   2.x sent the opt-in inside the request; this keeps the welcome email about as fast without making
   the shopper wait.
4. **Concurrency.** The tick takes `GET_LOCK('<db>_smaily_tick', 0)`; a second tick that cannot take
   it returns at once. Rows are claimed with `UPDATE … SET claim_token = ? … LIMIT n` and then read
   by token, because `SKIP LOCKED` needs MySQL 8 or MariaDB 10.6 and PrestaShop 8 supports older
   servers. A claim older than 10 min returns to `pending` (the process died).
5. **One poison row never owns the pass** (`woo:docs/LESSONS.md` §2.18): each row runs in its own
   `try`/`catch (\Throwable)` and fails alone.
6. C is not built in 3.0.0. D is an open question for the owner (section 14).

**Dashboard.** A "Background tasks" card shows the last tick time and its source (cron URL, visitor
traffic, enqueue). States: green when a cron-URL tick ran within 5 min; amber when only fallback
ticks ran in the last hour ("Tasks run only when visitors come; add the cron line"); red when no tick
ran for 15 min or never ("Background tasks are not running"). Amber and red show the cron line
`* * * * * curl -fsS "<tick URL>" > /dev/null`. The Initial setup's Overview step shows the same card.

**Why PrestaShop forces a departure.** Woo and Magento delegate scheduling to the platform. PrestaShop
has none, so the module owns the trigger, the lock and the time budget.

---

## 5. Scope model: shops and languages

### 5.1 Two axes

**[D]** The shop axis follows Magento's "website layout" (`magento:docs/ARCHITECTURE.md` "Separate
stores", CON-279) with the PrestaShop shop in place of the Magento website:

| Shop layout | Settings | Accounts | Engine connection |
|---|---|---|---|
| One store (one shop, or several shops as versions of one store) | Global values; a shop holds own values per section (Smaily account, Contacts, Automations) only where Settings saved them. | Global account row (`id_shop` 0) plus own rows. | One installation-wide connection. |
| Separate stores | Every section at the shop's scope; no global values. | One row set per shop. | One connection per Smaily account; shops on the same account share it (the engine allows one engine store per Smaily account). |

Shop groups carry no settings of their own (section 2.1). A shop group matters only for customer
sharing (5.3).

**[D]** The language axis follows Woo's multilingual modes (`woo:includes/Multilingual/Router.php`):
`single`; `a` one Smaily account per language with a fallback account; `b` one account, one workflow
per language; `c` one workflow that branches on the contact's `language` field. As in Magento, mode `a`
is always a shop's own setting (owner decision 2026-10-22 in Magento), and a store-wide save on a
multishop install does not offer it.

### 5.2 Resolution rules

| Question | Rule |
|---|---|
| Contact language | `customer.id_lang`; for an `emailsubscription` row its `id_lang`; for an order or cart its `id_lang`. Mapped to the two-letter ISO code of that language. |
| Contact shop | `customer.id_shop`; `emailsubscription.id_shop`; `orders.id_shop`; `cart.id_shop`. |
| Account for a contact | `Scope\AccountResolver::for(id_shop, language)`: in mode `a` the shop's row for that language, else the shop's fallback-language row; in other modes the shop's own row, else the global row (one store only). |
| Workflow for (trigger, shop, language) | `Automation\Router`, the Magento rules: the shop's own mapping rows first, then the `id_shop = 0` rows; mode `a` exact (trigger, language) row, else the (trigger, fallback language) row; mode `b` exact row, else the `is_default_fallback` row; `single`/`c` the default row. No match is a terminal skip with a reason. The matched row's `account_key` travels with the workflow, and the handler posts through that account. A shop on another account than the global one reads only its own rows (Magento CON-190). |

### 5.3 Campaign Intelligence scope

- The contract allows one engine store per Smaily account and lets one engine store serve several
  "websites" (contract §1 `store_config`, §3 `websites`, v1.21.0). Magento: one store = one connection;
  separate stores = one per Smaily account (`magento:docs/internal/ENGINE_CONNECTIONS_PLAN.md`).
- **[R]** (decision 7) 3.0.0 builds both layouts from the first release: the connection table is keyed
  by `id_shop` (0 = installation-wide), and every ingest row carries `id_connection`.
- `store_config.customer_account_scope`: `global` when every shop is in one shop group that shares
  customers (`shop_group.share_customer = 1`), else `website`.
- PrestaShop product, order and customer ids are unique across all shops of one installation. Two
  shops of one installation therefore never collide on keys in one engine store, which removes the
  reason for connection prefixes inside one installation. The module still declares the
  `connection_prefix` capability (section 8.2).

### 5.4 What the upgrade infers

Section 3.3: language mode `single`; shop layout from the account rows; every 2.x setting at the
scope where its effective value differs.

---

## 6. Consent mode on PrestaShop

### 6.1 Where opt-in lives

| Source | Meaning in 3.0.0 |
|---|---|
| `customer.newsletter` (+ `newsletter_date_add`, `ip_registration_newsletter`) | The marketing consent of a customer or a guest-checkout customer (`is_guest = 1`). |
| `customer.optin` | Partner offers. Not marketing consent; never read (2.x did not read it either). |
| `emailsubscription` (`ps_emailsubscription`): `email`, `id_shop`, `id_lang`, `active` | The consent of a newsletter-block subscriber who has no account. |
| Newsletter checkbox on registration and checkout | Added by `ps_emailsubscription` to the customer form; it writes `customer.newsletter`. (to verify in Story 1.2 for the guest form on 8.0 and 9.x) |
| psgdpr | Export and erasure hooks (section 11). It holds no marketing consent. |

**[D]** Where `ps_emailsubscription` is not active, "Show a newsletter checkbox at checkout" (Contacts
setting, default on for new installs, off for upgrades) adds the box through
`additionalCustomerFormFields` and writes `customer.newsletter`. Where `ps_emailsubscription` is
active, the module adds no box. This is a visible difference from Magento and Woo, which add their own
checkout box: PrestaShop's box already exists in most stores, and two boxes confuse shoppers.

### 6.2 What each mode syncs

The modes are Woo's presets (`woo:docs/CONTACT_SYNC_MODES.md` §2), with Smaily as the consent
authority and `force_opt_in = false` on every automation trigger.

| | Subscribers only (default; upgrades) | All customers | Checkout opt-in only |
|---|---|---|---|
| Live sync | A customer or guest at opt-in; opt-out sends `is_unsubscribed=1`. | Every customer at register, profile change, order. Never sends `is_unsubscribed`. | Only a checkout with the box ticked. |
| Newsletter-block guests | When "include guests" is on (on for upgrades). | When "include guests" is on. | No. |
| First import and daily refresh | `newsletter = 1` customers (+ active `emailsubscription` rows with guests on). | Every customer. | None. |
| Reconcile from Smaily | Both directions: unsubscribe sets `newsletter = 0` and `emailsubscription.active = 0` (as 2.x); re-subscribe sets `newsletter = 1`. | None. | None. |
| `is_unsubscribed` on upsert | Sent only when consent changed in the store. | Never. | `0` with the tick. |
| Abandoned-cart reminder goes out when | Decision 3. | Smaily does not hold the contact as unsubscribed. | The box was ticked, or Smaily holds the contact as subscribed. |

**[D]** "Status sent only on change": a `contact.sync` row carries `is_unsubscribed` only when the
listener saw the consent value change. Automations and imports never send `is_unsubscribed = 0`.
Reconcile writes go straight to the tables with `Db`, so they fire no hook and enqueue nothing back to
Smaily.

### 6.3 Capture hooks

| Hook | Captures |
|---|---|
| `actionCustomerAccountAdd` | New customer or guest, its `newsletter` value, and whether the form was the checkout (`Context::getContext()->controller` is `order`). |
| `actionObjectCustomerUpdateBefore` / `…UpdateAfter` | The stored `newsletter` value before the save (read from the database), compared after the save. Covers front-office account edits, the admin form and the admin grid toggle. (to verify in Story 1.2 that the grid toggle fires them) |
| `actionCustomerAccountUpdate` | Front-office account edits; deduplicated with the row above by (address, consent) within one request. |
| `actionNewsletterRegistrationAfter` | Newsletter-block subscribe (`action = 0`) and unsubscribe (`action = 1`). (to verify the `action` parameter) |
| `actionValidateOrder` | Profile and consent at order time (all-customers mode), checkout opt-in. |

---

## 7. Event hook map

| Hook | Queue event(s) | Story |
|---|---|---|
| `actionCustomerAccountAdd` | `contact.sync`; `automation.trigger` welcome (when `newsletter = 1`); engine customer row | 2.3, 4.2, 5.2 |
| `actionObjectCustomerUpdateBefore`, `actionObjectCustomerUpdateAfter`, `actionCustomerAccountUpdate` | `contact.sync` (with status only on change); welcome on 0→1 | 2.3, 4.2 |
| `actionObjectCustomerDeleteAfter` | Cart tracker rows of the customer → `erased`. Nothing to Smaily: erasure goes through psgdpr. | 4.3 |
| `actionNewsletterRegistrationAfter` | `contact.sync`; welcome on subscribe | 2.3, 4.2 |
| `actionAuthentication` | engine `identity_merge` (§7), customer row (§4 group c), `vs_` token | 5.2, 5.5 |
| `actionCartSave` | Cart tracker upsert (`last_activity_at`, email when known) | 4.3 |
| `actionValidateOrder` | Tracker → `completed`; pending reminder of that address withdrawn; first-order trigger; attribution capture; engine order row | 4.2, 4.3, 5.2, 5.5 |
| `actionOrderStatusPostUpdate`, `actionOrderEdited` | Engine order re-send (whole row) | 5.2 |
| `actionOrderSlipAdd` | Engine order re-send with `items[].returned_at` on fully refunded lines | 5.2 |
| `actionObjectProductAddAfter`, `actionObjectProductUpdateAfter`, `actionUpdateQuantity`, `actionObjectCombinationUpdateAfter`, `actionObjectSpecificPriceAddAfter` / `UpdateAfter` / `DeleteAfter` | Catalog marker for the product | 5.2 |
| `actionObjectProductDeleteAfter`, `actionObjectCombinationDeleteAfter` | `catalog_remove` (§3b) | 5.2 |
| `actionObjectCategoryUpdateAfter` | None. A category rename reaches the engine through the next catalog import ("Run again"), as the nightly manifest does not carry names. | — |
| `actionObjectShopDeleteAfter` | §16 for a connection anchored on that shop | 5.1 |
| `actionFrontControllerSetMedia` | Front JS, page context, fallback tick | 1.6, 5.5 |
| `actionAdminControllerSetMedia`, `displayBackOfficeHeader` | Fallback tick; upgrade and health notices | 1.6, 1.7 |
| `additionalCustomerFormFields` | Own checkout box (6.1) | 2.3 |
| `displayCustomerAccount` | "Personalization" link (§10), only with a connection | 5.6 |
| `moduleRoutes` | Friendly URLs for relay, land, recommendations, personalization | 5.5 |
| `actionExportGDPRData`, `actionDeleteGDPRCustomer`, `registerGDPRConsent` | Export and erasure (section 11) | 5.6 |

**[D]** The abandoned-cart payload keeps the 2.x template fields byte-compatible: the 10 product slots
with `''` for unused slots (the empty values clear the previous cart in Smaily,
`woo:docs/LESSONS.md` §2.21), `over_10_products`, `is_abandoned_cart`, `force_opt_in = false`. Two
2.x gates are removed because they are dead switches on fresh installs (`woo:` §2.21): product slots
and first/last name are always sent. **[D]** Each automation writes its marker (`welcome_automation_at`,
`first_order_automation_at`, `abandoned_cart_automation_at`) as a contact field on the trigger, as the
siblings do.

---

## 8. Campaign Intelligence integration

### 8.1 Endpoints in scope

| Contract section | 3.0.0 use |
|---|---|
| §1 setup exchange | Settings > Intelligence and the setup's Intelligence step: paste the setup link. Sends `plugin_info` (`ecommerce_platform: prestashop`), `store_config` on multishop, `capabilities: ["connection_prefix"]`, `connection.previous_prefix` on a reconnect. Refuses a link whose `smaily_subdomain` differs from the shop's account before storing anything. |
| §2 ping | "Check again" button; health check every 15 min; reads `config.history` and `config.connection.prefix` when missing. |
| §3, §3b, §3c | Live catalog markers, removals, nightly manifest, catalog import. |
| §4 | Customers: group a (contacts that the module syncs), b (an order within 60 months), c (login with consent). |
| §5 | Orders, newest first: 18 months, then to 60 months (§25 sender rules). |
| §6 | Browse events through the relay. |
| §7 | Identity merge at login. |
| §8, §9 | psgdpr export and erasure. |
| §10 | Shopper's profiling opt-out. |
| §11, §12, §13 | Engine-run automations in the Automations tab. |
| §15 | Storefront recommendations. |
| §16 | Uninstall and disconnect. |
| §25 | History import progress. |
| §14, §17–§24 | Out of 3.0.0 (owner's list). |

**[D]** Endpoint URLs come from the stored endpoints map, with the fallback path constants for keys a
connection lacks, as in both siblings. Retry and timeouts: Magento's `Engine\Client` rules (contract
"Wire contracts" in `magento:docs/ARCHITECTURE.md`).

### 8.2 Ids and keys

| Id | 3.0.0 value |
|---|---|
| Catalog `sku` | **[O]** proposed `ps-<id_product>` for a product without combinations and `ps-<id_product>-<id_product_attribute>` for a combination, after the connection prefix. PrestaShop's `reference` is optional and not unique, so the Shopify/Woo rule applies: never the merchant's reference (send it as `tags.merchant_sku` only if an operator asks). Needs the engine team's agreement on `ps-` (siblings: `shp-`, `woo-`, `mag-`). |
| `tags.product_id` | `<prefix><id_product>` |
| `external_id` (catalog) | `<id_product>` or `<id_product>-<id_product_attribute>` |
| `external_order_id` | `<prefix><id_order>` |
| `customer_external_id` | `<id_customer>` |
| `website` / `websites` key | **[O]** proposed: the shop id as a string (`"1"`). PrestaShop shops have no code; the id is stable. Sent only by a connection that serves 2+ shops. Needs the engine team's agreement. |
| History `import_id` | `ps-<YYYY-MM-DD>-<job id>` |
| Connection prefix | `config.connection.prefix`, `""` today. Put in front of every key from the first release, so the engine's future refusal of connectors without the capability does not break the module. |

### 8.3 Money and tax

| Field | Source |
|---|---|
| Catalog `price` | `Product::getPriceStatic($id, true, $ipa, 6)` at the connection's canonical shop (gross, after reductions). |
| Catalog `compare_price` | The same without reductions, sent only when greater than `price`. |
| Catalog `currency` | The canonical shop's default currency. |
| Order `total_amount` | `orders.total_paid_tax_incl`. |
| `items[].line_total`, `unit_price` | `order_detail.total_price_tax_incl`, `unit_price_tax_incl` (gross, contract §5 "Amount semantics"). |
| Order `currency` | The order's currency (`orders.id_currency`). |
| Order `status` | **[D]** `refunded` for `PS_OS_REFUND`; `cancelled` for `PS_OS_CANCELED` and `PS_OS_ERROR`; `completed` when the state has `shipped` or `delivered`; `processing` otherwise. |

### 8.4 Front controllers

| Controller | Does | Sibling |
|---|---|---|
| `relay` (POST) | Takes batched browse events, validates them (no client-sent identity), adds the visitor token from the cookie, rate-limits per address, forwards to §6 with the server-side key; one 3 s attempt. Never named `beacon` (ad blockers). | `magento:Controller/Relay/Index`, `woo:` relay |
| `land` (POST) | Sets the three attribution cookies server-side (Safari keeps a JavaScript cookie for 7 days at most). | `magento:Controller/Attribution/Land` |
| `recommendations` (GET) | §15 for the logged-in customer or the visitor token; renders the HTML fragment; cached per (connection, shop, language, customer or token) for Magento's period; same guards as Magento (404 without connection, per-address limit, cross-site → empty 200). | `magento:Controller/Recommendations/Index` |
| `personalization` | My Account page for the §10 opt-out. | `woo:`/Magento "Personalization" |

### 8.5 Cookies

From the setup exchange's `config`, with the contract defaults: `smaily_rec_uid` (365 d),
`smaily_anon_sid` (30 d), `smaily_rec_id` (30 d), `smaily_rec_ctx` (30 d). `SameSite=Lax`, `Secure` on
HTTPS, not `HttpOnly`. The context cookie rule of contract v1.9.0 applies. The order attribution
validates `smaily_rec_id` as a UUID before it stores it (Magento parity audit P1).

### 8.6 Storefront JavaScript

**[D]** Port Magento's four hand-written scripts (`consent.js`, `attribution.js`, `tracker.js`,
`recommendations.js`), not Woo's TypeScript bundle. Each `define()` becomes an IIFE that exposes only
`window.smailyConnect`. No build step and no `node_modules` in the repository; the files ship as they
are written.

- **Rationale.** The legacy repository has no JavaScript toolchain. Magento's scripts are already
  framework-free vanilla JavaScript (`tracker.js` says so) and carry the same rules as Woo's
  `beacon-core.ts` / `recs-core.ts`. A classic script must not leak top-level bindings
  (`woo:docs/LESSONS.md` §2.26); an IIFE meets that by construction, and CI checks that each file's
  first statement opens one.
- **Alternatives.** Vendor Woo's built `sc-runtime.js` / `sc-recs.js`: they carry WordPress consent
  wiring and WooCommerce cart events, so they need a source change and a build anyway.
- Cart events come from `prestashop.on('updateCart', …)`. Page context (`window.smailyPageContext`:
  page type, product key, category path, search query) is printed per page through `Media::addJsDef`,
  the same for every visitor.

### 8.7 Recommendations block

**[D]** The main class implements PrestaShop's `WidgetInterface`. The widget renders an empty
container `<div data-smaily-connect-recs>`, the same for every visitor, and nothing without a
connection. The merchant places it with Design > Positions on any display hook, or with
`{widget name='smailyforprestashop'}` in a theme. The module registers no display hook for it, so
nothing is placed automatically (Magento parity). `recommendations.js` loads after `load`, only with
consent, calls the `recommendations` controller once per page, and fills every container.

**Full-page cache.** PrestaShop core has no full-page cache, but cache modules exist (for example
LiteSpeed Cache). Every per-visitor value reaches the page only through the after-load calls and the
server-set cookies, so a cached page is correct for every visitor.

---

## 9. Cookie consent for tracking and recommendations

PrestaShop has no consent API: no equivalent of the WP Consent API or Magento's cookie restriction
mode. Browse tracking and the recommendations call set or read non-essential cookies, so they need the
visitor's prior consent under ePrivacy rules. Attribution capture from email links (URL parameters →
cookies) runs as in the siblings (`woo:` PRO-1388); whether it needs consent is part of this decision.

| Option | What it means legally | What it means in practice |
|---|---|---|
| A. Fail closed + `window.smailyConnect.consentOverride()` (Magento's rule without a platform signal) | The module never tracks without a signal that the store set. The store's consent banner owns the decision. Lowest risk for Smaily. | Tracking and recommendations stay off until the merchant or their developer adds one line of JavaScript that wires their banner. Most stores add nothing, so Campaign Intelligence learns only from orders. |
| B. A + adapters for named consent modules | As A for each supported module. | Each adapter is vendor code that breaks when the vendor changes. PrestaShop's consent modules are many and small; no adapter covers most stores. Woo chose one standard signal, no per-vendor code (F3-50). |
| C. A + a merchant-configured consent cookie rule (cookie name + the value that means "accepted", per shop) | As A: the visitor's own recorded choice decides. The merchant states which cookie records it. | Most PrestaShop consent modules store the choice in a first-party cookie. One generic rule covers them with no vendor code. A wrong rule fails closed. |
| D. Merchant-confirmed "my banner covers Smaily; treat visitors as consenting" | The merchant takes the legal risk; the module tracks every visitor. A store with no banner tracks without consent. | Highest coverage. Contradicts the "explicit yes" rule set across the siblings (Erkki 2026-10-06, `woo:public/js/lib/marketing-consent.ts`). |

**[R]** (decision 2) Option C. The rule order is: the override function when defined; else the
configured cookie rule; else no consent. The Settings help text names the cookie of two common
modules as examples. It keeps the siblings' "explicit yes" rule and gives a merchant without a
developer a way to switch tracking on.

---

## 10. Admin UI

### 10.1 Stack and placement

**[D]** Symfony admin controllers (`Admin\BaseController`) with Twig templates, PrestaShop's admin UI
kit (Bootstrap 4 classes that the PrestaShop back office ships), plain JavaScript for the steps and
tabs, and JSON endpoints under `Admin\Api\*` for saves and checks. No front-end build.

- **Rationale.** This is the stack PrestaShop offers on 8 and 9, and 2.2.0 already uses it. Magento's
  screens are server templates + vanilla JavaScript + JSON actions too, so the structure ports.
- **Alternative.** Woo's React admin bundle: needs a build toolchain and a second visual system inside
  PrestaShop's back office.

**[D]** Menu: a tab "Smaily Connect" with four children (Dashboard, Initial setup, Settings, Log),
registered through the module's `$tabs`, under the Marketing entry of the side menu where the version
has one, else under Customers. (to verify in Story 1.2: the parent tab's class name on 8 and 9) The
module's "Configure" button opens the Dashboard, or the Initial setup while it is not finished.

### 10.2 Screen map

| PrestaShop screen | Magento source | Notes |
|---|---|---|
| Dashboard | `Controller/Adminhtml/Dashboard/Index.php`, `view/adminhtml/templates/dashboard/index.phtml`, `docs/internal/ADMIN_UI_TARGET_SPEC.md` §2.1 | Adds the Background tasks card (section 4). |
| Initial setup: Connect → Contacts → Automations → Intelligence → Overview | `Controller/Adminhtml/Wizard/Index.php`, `templates/wizard/index.phtml`, `templates/panel/{connection,subscribers,automations,intelligence,store-shape}.phtml`, `Api/SaveStep.php` | Forced for `SETUP_STATE = new`; prefilled and not blocking for `upgraded`. The store-layout question comes first on a multishop install while the layout is unknown. Invitation banner for a store without an account: `Controller/Adminhtml/Invitation`, `templates/panel/smaily-invitation-banner.phtml`. |
| Settings: Connection, Contacts, Automations, Intelligence, RSS | `Controller/Adminhtml/Settings/Index.php`, `templates/settings/index.phtml`, the same panels, `templates/config/{engine-automations,rss-builder}.phtml` | One writer for setup and Settings (Magento `WizardStepSaver`). |
| Log | `Controller/Adminhtml/Log/{Index,Details,MassRetry,Resend}.php`, `templates/log/*.phtml` | One list over both queues and the import jobs. |

### 10.3 Visible differences that PrestaShop forces

| Difference | Why |
|---|---|
| The shop is chosen with PrestaShop's own shop-context selector in the header, not a picker on the page. Each tab carries the Magento switch "Use its own … on this shop". | PrestaShop admins already pick the shop there; a second picker gives two answers. |
| Page frame, toolbar and buttons are PrestaShop's. | Platform shell. |
| The Log is a Twig table with filters, not a native grid. | A PrestaShop Grid component needs Symfony grid definitions that differ between 8 and 9; a plain table is one code path. |
| No checkout checkbox where `ps_emailsubscription` shows one (6.1). | Avoids two boxes. |
| The cron line and Background tasks card. | Section 4. |

### 10.4 Wording and translations

**[D]** EN source strings are copied from `magento:i18n/en_US.csv`, with "website" changed to "shop"
and "store view" to "language". ET strings come from `magento:i18n/et_EE.csv`, then from Woo's
`languages/` where Magento has none. Domains: `Modules.Smailyforprestashop.Admin` for the back office,
`Modules.Smailyforprestashop.Shop` for the storefront. Stories write EN strings in code and do not edit
XLIFF files; one translation sweep per stage (Stories 3.5 and 6.2) writes
`translations/et-EE/*.xlf`, so no two workers edit one XLIFF file.

---

## 11. Installation report, privacy, retention, uninstall

### 11.1 Installation report

| Report field | 3.0.0 source |
|---|---|
| `product` | `connect_prestashop` (**[O]**: not in the registry; the subscription team adds it) |
| `product_version` | module version |
| `platforms` | `[{name: "prestashop", version: _PS_VERSION_}]` (**[O]**: registry row) |
| `php_version` | `PHP_VERSION` |
| `installation_url` | The default shop's main URL (`Tools::getShopDomainSsl(true)` + base URI) |
| `smaily_subdomain` (top) | The global account's subdomain when a connection names it, else the first marketing connection's |
| `connections[]` | One `marketing` connection per (shop, account) with a working account; per language account in mode `a` with `language` |
| `connections[].store_url` | The shop's main URL |
| `connections[].website` | **[O]** The field is "Magento only". For a multishop store the subscription team must name a PrestaShop field (proposed: the same value as the engine's shop code, section 8.2). Until then the module sends one connection per (account, language) and no shop field, with `store_url` of the first shop that uses it. |
| `features` | `subscriber_sync` = contact sync on; an automation id when switched on **and** the Router resolves a workflow on this account; `campaign_intelligence`, `browse_tracking` while the shop's connection is connected |
| `smaily`, `intelligence` | `Report\ConnectionHealth`, Magento's rules |
| Snapshots and events | `setup_saved` 5 min after a save; `daily`; `disconnected`; `deactivated` first in uninstall. One report per tick at most. |

### 11.2 Privacy

| Request | 3.0.0 |
|---|---|
| Export (psgdpr `actionExportGDPRData`) | Local rows for the address (event queue, cart tracker, attribution, privacy record) + engine §8 per connection, one 10 s attempt; a failed engine call names the failure, not the remote text (`woo:docs/LESSONS.md` §2.32). |
| Erasure (psgdpr `actionDeleteGDPRCustomer`) | Deletes local rows that hold the address or its keyed hash; marks tracker rows `erased`; queues §9 per connection as a durable row with retries. Smaily contacts are not deleted: Smaily owns them (sibling rule). |
| Profiling opt-out (§10) | My Account "Personalization" page, shown only with a connection; writes the local record first, then queues §10; the newest choice wins; a marketing unsubscribe also stops profiling (Magento parity audit R1–R3). |
| Without psgdpr | The same two operations run from the Log screen's "Privacy request" form for an address. |

### 11.3 Retention

**[D]** Magento's janitor periods: sent and skipped queue rows 30 days, failed rows 90 days, tracker
rows in a terminal state 30 days, finished jobs 90 days. Engine-side retention follows contract §8;
the user guide carries the privacy-policy template of `woo:docs/DATA_MODEL_GDPR.md`.

### 11.4 Uninstall

**[R]** (decision 8) Uninstall and Reset remove all module data, as 2.x does and as PrestaShop
merchants expect: first the `deactivated` report and §16 per connection (one 3 s attempt each; a
failure does not stop the removal), then every `SMAILY_CONNECT_*` key, the two legacy token keys, every
`smaily_connect_*` table and the legacy `smaily_cart` table. The override file is removed only under
the rule of section 2.4. "Disable" runs nothing (`woo:docs/LESSONS.md` §2.27: the tick, the fallback and
the hooks all stop because the module is not loaded).

---

## 12. Testing and tooling

| Layer | What | Where |
|---|---|---|
| Unit | Pure logic: legacy mapper (every fixture), consent policy, audience, router, payload builders, retry ladder, failure classes, tick scheduling, report builder. At least one case per behaviour runs on the shipped defaults (`woo:docs/LESSONS.md` §2.21). | `tests/Unit/<Area>/` |
| Integration | PHPUnit inside the docker store against its real database: install, upgrade from each fixture in `tests/fixtures/legacy/` (2.x, 1.6.x leftovers, 1.6.x code, multishop), hooks → queue rows, tick, flush against mocks. A test seeds its own state and never relies on suite order (`woo:` §2.25). | `tests/Integration/<Area>/` |
| Mocks | Engine mock ported from `woo:tests/Integration/Fixtures/RecEngineMockServer.php` + `mock-rec-engine/router.php`; a Smaily API mock of the same shape; `subscription.smaily.com` resolves to 127.0.0.1 in the sandbox (Magento rule). | `tests/Integration/Fixtures/` |
| JavaScript | The IIFE first-statement check for every file in `views/js/front/`; logic of `consent.js` tested with Node's built-in test runner (no npm packages). | `tests/js/` |
| Contract check | CI compares `docs/RECENGINE_API_CONTRACT.md` and `docs/contracts/installation-report-v1.md` with the Magento repository's copies (`gh api`, read token) and fails on any difference. | CI |
| Matrix | PrestaShop 8.0 (PHP 8.0), 8.2 (PHP 8.1), 9.0 and 9.2 (newest PHP each supports), flashlight images as `tests/ci/validate.sh` uses today. | CI |
| Static | php-cs-fixer, PHPStan (existing config), PHP 8.0 lint. | CI |
| Release | `composer package` as today; checks: version equal in main class and `composer.json`; no `tests/`, `docs/internal/` or dev files in the zip; the zip installs and upgrades on the matrix. | `bin/package.sh`, CI |

---

## 13. Work breakdown

Each Story fits one worker dispatch. "Owns" lists the paths only that Story creates or edits; any
other path is read-only for it. "Parallel group" names Stories that may run at the same time (at most
three). Every Story also owns its tests under `tests/Unit/<Area>/` and `tests/Integration/<Area>/`.

### Story 1.1 — Shared skeleton (first, alone)

- **Goal.** Fix the layout, the base classes, the schema and the test harness so later Stories never
  edit the same file.
- **Owns.** `src/smailyforprestashop.php`, `src/src/Runtime/` (incl. empty `Provider/<Area>Provider.php`
  for every area of 1.1), `src/src/Hook/Dispatcher.php`, `src/src/Install/{Installer,Schema,HookList}.php`,
  `src/src/Config/Settings.php` (base + empty section classes), `src/src/Queue/HandlerInterface.php`,
  `src/src/Automation/WorkflowResolverInterface.php`, `src/config/services.yml`, `src/composer.json`,
  `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/Integration/IntegrationTestCase.php`, CI workflow.
- **Must fix in place.** The directory layout of 1.1; version `3.0.0` and displayName "Smaily Connect";
  every hook method of section 7 delegating to the Dispatcher; `Install\HookList`; every table of
  2.2 in `Install\Schema`; the provider pattern; the schema-version guard (3.7); the integration test
  base that boots PrestaShop in the docker store.
- **Depends on.** Nothing.
- **Acceptance.**
  1. A fresh install on 8.0 and 9.2 creates every table of section 2.2 and registers every hook of
     section 7.
  2. Calling any registered hook with the schema version unset does nothing and raises no error.
  3. `composer test` runs one unit and one integration test green in CI on the matrix.
  4. `composer package` builds a zip that installs on 8.0 and 9.2.

### Story 1.2 — Spike: platform facts on PrestaShop 8.0 and 9.x

- **Goal.** Answer every "(to verify)" in this document before the Stories that rest on it.
- **Owns.** `docs/internal/spikes/platform-facts.md`.
- **Depends on.** Nothing (runs any time in Stage 1).
- **Acceptance.**
  1. Each "(to verify)" item has an answer with the PrestaShop version and the observed evidence.
  2. Every answer that contradicts this document names the section to change.
  3. The hook list of section 7 is confirmed or corrected for the admin grid newsletter toggle, the v2
     product page, combinations, stock and specific prices.

### Story 1.3 — Legacy config mapper (pure)

- **Goal.** Turn any 2.x or 1.6.x state into a migration plan without touching PrestaShop.
- **Owns.** `src/src/Migration/{LegacyState,LegacyConfigMapper,MigrationPlan}.php`.
- **Depends on.** 1.1. Reads `tests/fixtures/legacy/` (another worker writes it).
- **Acceptance.**
  1. Every rule of section 3.3 has a unit case; every fixture maps to a plan that the test pins.
  2. A 1.6.x-rows store under 2.x code maps to welcome on, contact sync off, abandoned cart off, with a
     notice for each.
  3. A shop that inherits a global value but never sent the feature gets the feature off at its scope.
  4. An empty legacy token maps to a new-token instruction and a notice.

### Story 1.4 — Smaily client, transport, accounts and encryption

- **Goal.** One tested way to call Smaily with stored, encrypted accounts.
- **Owns.** `src/src/{Http,Smaily}/`, `src/src/Config/AccountSettings.php`, `src/src/Runtime/Provider/SmailyProvider.php`; deletes `src/lib/`.
- **Depends on.** 1.1.
- **Acceptance.**
  1. The client classifies 101, 203, 206, 227, 4xx, 429 and 5xx as Magento's client does.
  2. An account row round-trips encrypted; a value that does not decrypt reads as "no credentials".
  3. No log line or exception message contains the password.
  4. All 2.x Smaily calls (contact upsert, unsubscriber list, autoresponder with and without id) have
     a passing test against the Smaily mock.

### Story 1.5 — Event queue core

- **Goal.** A durable queue with the sibling retry semantics and a generic flush.
- **Owns.** `src/src/Queue/` (except `HandlerInterface.php`), `src/src/Runtime/Provider/QueueProvider.php`.
- **Depends on.** 1.1.
- **Acceptance.**
  1. A failing row follows 60 s / 5 min / 15 min / 1 h and parks as `failed` after 5 attempts.
  2. A 4xx other than 429 and a Smaily 203 park on the first attempt with their reason.
  3. Two concurrent flushes never send one row twice.
  4. A handler that throws `\Throwable` fails its row only; the other rows of the pass are sent.

### Story 1.6 — Scheduler, tick and cron URLs

- **Goal.** Every job of section 4 runs on time from any door, without blocking a shopper.
- **Owns.** `src/src/Scheduler/`, `src/src/Config/TickSettings.php`, `src/controllers/front/{SmailyCustomerCron,SmailyCartCron,tick}.php`, `src/src/Runtime/Provider/SchedulerProvider.php`.
- **Depends on.** 1.5.
- **Acceptance.**
  1. The two legacy URLs with their tokens and the new tick URL each run the due jobs and answer
     `text/plain`.
  2. An empty or wrong token is refused.
  3. A storefront request with a stale tick returns its page before the tick work starts (measured
     time to last byte unchanged within 50 ms).
  4. Two ticks started at once: the second returns without work.
  5. The tick state records the time and source that the Dashboard needs.

### Story 1.7 — Upgrade to 3.0.0, install and uninstall

- **Goal.** A 2.x or 1.6.x store upgrades in place and behaves as before.
- **Owns.** `src/upgrade/upgrade-3.0.0.php`, `src/src/Migration/{LegacyReader,Upgrade300}.php`, `src/src/Install/Uninstaller.php`, `src/overrides/` (removal), `src/src/Config/MigrationNotices.php`.
- **Depends on.** 1.3, 1.4, 1.5.
- **Acceptance.**
  1. Each fixture store upgrades through Module Manager upload on 8.0 and 9.2; the plan's settings,
     accounts, mapping rows and cart rows exist; legacy keys except the two tokens are gone.
  2. A failure injected at each step leaves legacy keys in place, and a second run completes.
  3. The plain-text password is absent from `configuration` after the upgrade.
  4. The override file is removed only when its hash is in the 2.x list.
  5. Uninstall removes every key and table of section 11.4.

**Stage 1 parallel groups.** 1.1 alone → {1.3, 1.4, 1.5}, with 1.2 any time → {1.6, 1.7}.

### Story 2.1 — Scope: shop layout, languages, account resolution

- **Goal.** One answer to "which shop, language and account" for every event.
- **Owns.** `src/src/Scope/`, `src/src/Runtime/Provider/ScopeProvider.php`.
- **Depends on.** 1.4.
- **Acceptance.**
  1. Each rule of section 5.2 has a unit case for one shop, one store with several shops, and separate
     stores.
  2. Mode `a` resolves a language without an account to the fallback account.
  3. `customer_account_scope` reads `global` or `website` from the shop groups.

### Story 2.2 — Consent mode policy and contact payload

- **Goal.** One policy object decides who syncs and what a contact row carries.
- **Owns.** `src/src/Contacts/{ContactSyncMode,ContactAudience,SubscriberPayloadBuilder}.php`, `src/src/Config/ContactsSettings.php`, `src/src/Queue/Handler/ContactSyncHandler.php`.
- **Depends on.** 1.4, 1.5.
- **Acceptance.**
  1. Each cell of the table in 6.2 has a unit case.
  2. `is_unsubscribed` appears only on a consent change.
  3. A contact row carries `store` (shop domain, as 2.x), `language`, and only the selected fields; an
     empty value is left out.
  4. A 203 on a group re-sends each contact alone (Magento PRO-3753).

### Story 2.3 — Contact capture hooks

- **Goal.** Store changes reach the event queue for the right shop and language.
- **Owns.** `src/src/Contacts/Capture/`, `src/src/Runtime/Provider/ContactsProvider.php`.
- **Depends on.** 2.1, 2.2.
- **Acceptance.**
  1. Registration, guest checkout, account edit, admin edit, grid toggle and newsletter-block
     subscribe/unsubscribe each enqueue the row of section 6.3 on 8.0 and 9.2.
  2. One request that fires two of these hooks enqueues one row.
  3. The own checkout box appears only where `ps_emailsubscription` is inactive and the setting is on.

### Story 2.4 — Reconcile, daily refresh and first import

- **Goal.** Smaily's unsubscribes reach the store, and existing contacts reach Smaily once.
- **Owns.** `src/src/Contacts/{Reconciler.php,Import/}`.
- **Depends on.** 2.1, 2.2, 1.6.
- **Acceptance.**
  1. In subscribers mode an unsubscribe in Smaily sets `newsletter = 0` and `emailsubscription.active = 0`
     within one reconcile run, and enqueues nothing.
  2. All-customers and checkout-opt-in modes reconcile nothing.
  3. The first import sends exactly the mode's audience and resumes from its cursor after a stop.
  4. No import row carries `is_unsubscribed = 0`.

**Stage 2 parallel groups.** {2.1, 2.2} → {2.3, 2.4}.

### Story 3.1 — Admin shell

- **Goal.** Menu, routes, base controller, JSON API base and notices that every screen uses.
- **Owns.** `src/config/admin/services.yml`, `src/config/routes.yml`, `src/src/Admin/{BaseController.php,Api/AbstractJsonAction.php}`, `src/views/templates/admin/layout/`, `src/views/js/admin/common.js`, `src/views/css/admin.css`.
- **Depends on.** 1.1.
- **Acceptance.**
  1. The four menu entries open on 8.0 and 9.2 for an admin with module rights, and are refused
     without them.
  2. A new install redirects every module page to the Initial setup; an upgraded install does not.
  3. Migration notices show on module pages and as one line elsewhere until dismissed.

### Story 3.2 — Initial setup and Dashboard

- **Goal.** Magento's five-step setup and Dashboard, step by step.
- **Owns.** `src/src/Admin/{Setup,Dashboard}/`, `src/views/templates/admin/{setup,dashboard}/` (the Automations and Intelligence step templates are stubs that 4.1 and 5.3 own), `src/views/js/admin/{setup,dashboard}.js`, `src/src/Admin/Api/{SaveStep,TestSmaily}.php`.
- **Depends on.** 3.1, 2.1, 2.2.
- **Acceptance.**
  1. Step order, step names and wording equal Magento's EN strings.
  2. An upgraded store opens the setup prefilled with its migrated values.
  3. The store-layout question shows first on a multishop install while the layout is unknown.
  4. The Dashboard shows the Background tasks card in its three states.

### Story 3.3 — Settings: Connection, Contacts, RSS, shop switches

- **Goal.** The Settings page with the tabs that exist after Stage 2.
- **Owns.** `src/src/Admin/Settings/`, `src/views/templates/admin/settings/{index,connection,contacts,rss}.html.twig`, `src/views/js/admin/settings.js`, `src/src/Rss/`, `src/controllers/front/SmailyRssFeed.php`, `src/src/Config/RssSettings.php`.
- **Depends on.** 3.1, 2.1, 2.2.
- **Acceptance.**
  1. Setup and Settings write through one saver; a value saved in one reads back in the other.
  2. "Use its own … on this shop" writes the shop's scope; unticked, the save removes the shop's own
     values.
  3. The RSS URL with legacy parameters returns the same XML items as 2.2.0 for the same catalog.
  4. The password field never renders the stored password.

### Story 3.4 — Log

- **Goal.** One list of everything sent, failed or skipped, with retry.
- **Owns.** `src/src/Log/`, `src/src/Admin/Log/`, `src/views/templates/admin/log/`, `src/views/js/admin/log.js`.
- **Depends on.** 3.1, 1.5.
- **Acceptance.**
  1. Rows of both queues and the jobs list together, filterable by status, type, shop and entity.
  2. Details show the request as sent and the last response, without secrets.
  3. Retry of a failed row returns it to `pending` once; mass retry works on a filtered set.

### Story 3.5 — Translation sweep (stage 3)

- **Goal.** EN/ET XLIFF for everything built so far.
- **Owns.** `src/translations/`.
- **Depends on.** 3.2, 3.3, 3.4.
- **Acceptance.**
  1. Every admin string has an ET translation taken from Magento's or Woo's catalogue, or marked new.
  2. Switching the employee language to Estonian shows no EN string on the four screens.

**Stage 3 parallel groups.** 3.1 → {3.2, 3.3, 3.4} → 3.5.

### Story 4.1 — Automation routing, mapping and the Automations tab

- **Goal.** (trigger, shop, language) → (account, workflow), editable in setup and Settings.
- **Owns.** `src/src/Automation/{Router,MappingRepository,WorkflowMatch}.php`, `src/src/Config/AutomationSettings.php`, `src/views/templates/admin/{settings/automations,setup/automations}.html.twig`, `src/src/Admin/Api/{Workflows,SaveAutomations}.php`.
- **Depends on.** 2.1, 3.2, 3.3.
- **Acceptance.**
  1. Each rule of section 5.2 (workflow row) has a unit case, including a shop on another account.
  2. A saved workflow id is checked against the same account's workflow list.
  3. A migrated generic opt-in row shows as "Smaily's opt-in trigger (from 2.x)" until another
     workflow is picked.

### Story 4.2 — Welcome and first-order automations

- **Goal.** The two contact automations fire once per contact, through the right account.
- **Owns.** `src/src/Automation/{Welcome,FirstOrder}/`, `src/src/Queue/Handler/AutomationHandler.php`.
- **Depends on.** 4.1, 2.3.
- **Acceptance.**
  1. A subscribe fires welcome once; a resubscribe, an admin change or an import does not (Magento
     parity audit R6).
  2. A first order fires first-order once per address; the trigger carries its `*_automation_at` field.
  3. Every trigger sends `force_opt_in = false`.

### Story 4.3 — Abandoned cart

- **Goal.** Reminders with the 2.x template fields, never twice for one cart.
- **Owns.** `src/src/Automation/AbandonedCart/`.
- **Depends on.** 4.1, 1.6.
- **Acceptance.**
  1. The payload of a cart equals the 2.x payload field by field, with the 10 slots.
  2. A cart with a migrated `smaily_cart` row is never reminded.
  3. An order withdraws a pending reminder for that address.
  4. The consent gate of decision 3 skips with a reason that the Log shows.

**Stage 4 parallel groups.** 4.1 → {4.2, 4.3}.

### Story 5.1 — Engine client and connections

- **Goal.** Connect, check and disconnect Campaign Intelligence per connection.
- **Owns.** `src/src/Engine/{Client,Connections,Connection,Setup,Disconnector,HealthCheck}.php`, `src/src/Config/EngineSettings.php`, `src/src/Runtime/Provider/EngineProvider.php`, `docs/RECENGINE_API_CONTRACT.md` (byte copy).
- **Depends on.** 1.4, 1.6, 2.1.
- **Acceptance.**
  1. A setup link from the mock stores an encrypted key and the endpoints map; a link of another
     Smaily account is refused before anything is stored.
  2. Ping and health check record `connected` / `refused` per connection.
  3. Uninstall sends §16 once per connection with a 3 s limit.
  4. The contract copy equals Magento's byte for byte.

### Story 5.2 — Catalog, customers and orders ingest

- **Goal.** Live and import rows reach the engine on the contract's rules.
- **Owns.** `src/src/Engine/{Payload,Ingest}/`, `src/src/Engine/Capture/`.
- **Depends on.** 5.1.
- **Acceptance.**
  1. A product save, a stock change and a price change each produce one catalog row with gross
     prices and the agreed key.
  2. A deleted product produces a §3b removal; the nightly manifest lists only the connection's
     products.
  3. An order's amounts satisfy Σ line_total + shipping ≈ total_amount; a full refund sends
     `refunded`.
  4. Every key carries the connection prefix.

### Story 5.3 — History import and the Intelligence tab

- **Goal.** First data load (§25) with progress, and the Intelligence screens.
- **Owns.** `src/src/Engine/History/`, `src/src/Admin/Api/{EngineExchange,EnginePing,BackfillState}.php`, `src/views/templates/admin/{settings/intelligence,setup/intelligence}.html.twig`.
- **Depends on.** 5.2, 3.2, 3.3.
- **Acceptance.**
  1. Orders go newest first: 18 months, then to 60 months; progress calls follow the §25 sender rules.
  2. "Run again" starts a new `import_id`.
  3. The Catalog, Customers and Orders cards show live progress.

### Story 5.4 — Engine-run automations

- **Goal.** §11–§13 automations listed and saved from the Automations tab.
- **Owns.** `src/src/Engine/Automations/`, `src/views/templates/admin/settings/engine-automations.html.twig`.
- **Depends on.** 5.1, 4.1.
- **Acceptance.**
  1. The list comes from §11 at page load; nothing is hard-coded.
  2. A save sends all eight keys per row and redraws from §12, not from the request.
  3. Rows of other platforms (`per_language`) survive a save.

### Story 5.5 — Storefront: relay, attribution, tracking, recommendations, consent

- **Goal.** Consent-gated browse tracking, click attribution and the recommendations block.
- **Owns.** `src/src/Storefront/`, `src/controllers/front/{relay,land,recommendations}.php`, `src/views/js/front/`, `src/views/templates/front/recommendations.tpl`, `tests/js/`.
- **Depends on.** 5.1; decision 2.
- **Acceptance.**
  1. Without consent no tracking cookie is set and no event leaves the browser.
  2. A landing with `smaily_rec`/`smaily_ctx`/`smaily_vt` sets the three cookies server-side; the
     next order stores them in `smaily_connect_order_attribution`.
  3. The relay rejects client-sent identity fields and forwards with the server-side key.
  4. The widget renders the same HTML for every visitor; the block fills after `load`.
  5. Every front script passes the IIFE check; the scripts add no global other than
     `window.smailyConnect`.

### Story 5.6 — Privacy

- **Goal.** Export, erasure and profiling opt-out across store, queues and engine.
- **Owns.** `src/src/Privacy/`, `src/controllers/front/personalization.php`, `src/views/templates/front/personalization.tpl`.
- **Depends on.** 5.1.
- **Acceptance.**
  1. A psgdpr export returns local rows and the engine's §8 answer per connection.
  2. A psgdpr erasure removes local rows by address and keyed hash and queues §9 durably.
  3. The opt-out page shows only with a connection; the newest choice wins.
  4. No log line or stored reason contains the remote error text.

**Stage 5 parallel groups.** 5.1 → {5.2, 5.5, 5.6} → {5.3, 5.4}.

### Story 6.1 — Installation report

- **Goal.** Smaily's subscription service knows where 3.0.0 runs and how it is.
- **Owns.** `src/src/Report/`, `docs/contracts/installation-report-v1.md` (byte copy).
- **Depends on.** 5.1, 4.1; the registry rows (section 14).
- **Acceptance.**
  1. A setup save sends `setup_saved` 5 min later; `daily` follows 24 h after the last attempt.
  2. Uninstall sends `deactivated` before §16.
  3. The body validates against the contract's schema; no secret is in it.
  4. Tests and the sandbox never reach the real service.

### Story 6.2 — Upgrade guide, user guide, changelog, translation sweep

- **Goal.** Merchants know what changes and what to do.
- **Owns.** `docs/UPGRADING.md`, `USERGUIDE.md`, `CHANGELOG.md`, `README.md`, `src/translations/`.
- **Depends on.** All Stage 5 Stories.
- **Acceptance.**
  1. UPGRADING.md lists every row of sections 2.4 and 3.3 in merchant words, and the cron line.
  2. Every admin and storefront string has ET.
  3. The privacy-policy template is in EN and ET.

### Story 6.3 — Release candidate and upgrade walk

- **Goal.** `3.0.0-rc1` zip proven on the matrix and on real legacy data.
- **Owns.** `bin/`, `tests/ci/` (release checks), `src/.zipignore`.
- **Depends on.** 6.1, 6.2.
- **Acceptance.**
  1. The zip installs, upgrades from every fixture and uninstalls cleanly on 8.0, 8.2, 9.0, 9.2.
  2. A scripted walk on an upgraded fixture store shows: same cron URLs answer, RSS output unchanged,
     a reminder goes out for a new cart and not for a migrated one, a welcome goes out on subscribe.
  3. The contract check passes in CI.

**Stage 6 parallel groups.** 6.1 (may start after 5.1 and 4.1) → 6.2 → 6.3.

---

## 14. Risks and open questions

| # | Risk or question | Condition and cost | Who answers |
|---|---|---|---|
| 1 | Product key prefix `ps-` and per-combination rows | Without agreement the engine can refuse rows under a SKU-identity policy. Per-combination rows multiply catalog size for stores with many combinations. | Engine team |
| 2 | Shop code for `website`/`websites` | Without agreement a multishop store cannot send per-shop links. | Engine team |
| 3 | Registry rows `connect_prestashop`, `prestashop`, and a shop field in the report | Until they exist the service rejects every report. | Owner → subscription team |
| 4 | Multi-currency orders | A shop that sells in several currencies sends orders in each; the engine assumes one currency per store. | Engine team |
| 5 | Which shop context ran the 2.x crons | The migration assumes the default shop; a store whose crontab named another shop's domain gets that shop's values wrong. | Pilot multishop store (spike in 6.3) |
| 6 | Fallback tick on hosts without `fastcgi_finish_request` | No fallback; the store depends on the cron line. | Story 1.2 measures; owner accepts |
| 7 | A hosted pinger (option D) | Removes the cron setup for every merchant; needs Smaily infrastructure. | Owner |
| 8 | The cookie key changes when a store moves servers | Stored secrets do not decrypt; the merchant reconnects. | Accepted cost |
| 9 | Hook coverage on 8.0 vs 9.x (grid toggle, v2 product page, guest newsletter field) | A missed hook loses an event silently. | Story 1.2 |
| 10 | `FrameworkBundleAdminController` and the Marketing parent tab on 9.x | If either is gone, Story 3.1 changes its base class or parent tab. | Story 1.2 |
| 11 | Reconcile re-subscribes in the store (`newsletter` 0 → 1) for contacts who re-subscribed in Smaily | 2.x pulled only unsubscribes. This follows Woo preset 2; the store mirrors Smaily, the Smaily audience does not grow. | Owner, if this is a widening in his reading |
| 12 | Merchants who disabled `ps_emailsubscription` emails through 2.x | 3.0.0 does not restore `NW_*` values, and `NW_VOUCHER_CODE` is lost. | Accepted; UPGRADING.md says so |
| 13 | 1.6.x stores lose the 1.6.x newsletter block | Own signup form is out of 3.0.0. | Accepted; notice + UPGRADING.md |

## 15. Decisions for the owner

Each decision below blocks only the Stories named.

| # | Question | Options | Recommendation | Blocks |
|---|---|---|---|---|
| 1 | How background tasks run | A cron URL only; A + visitor-traffic fallback; + hosted pinger later | A + fallback (section 4.3) | 1.6 |
| 2 | Cookie consent rule | A override only; B vendor adapters; C override + merchant-configured consent cookie; D merchant attests | C (section 9) | 5.5 |
| 3 | Abandoned-cart audience after upgrade | Keep 2.x (every customer with a cart); sibling gate (subscribers mode: only `newsletter = 1`, a ticked box, or subscribed in Smaily) | Sibling gate | 4.3 |
| 4 | 1.6.x settings that 2.x did not run | Switch on what the running code sent (contact sync and cart off + notice); honour the 1.6.x intent | What the running code sent | 1.3 |
| 5 | 2.x opt-in with no workflow (generic opt-in trigger) | Keep it for upgraded stores; switch welcome off with a notice (Magento's choice) | Keep it | 1.3, 4.1 |
| 6 | Product key per combination | `ps-<id>-<ipa>` rows per combination; one row per product | Per combination (Woo/Shopify parity), pending engine team | 5.2 |
| 7 | Separate stores' engine connections in 3.0.0 | Build per-account connections now; one installation-wide connection only and refuse Intelligence on separate-store shops | Build now | 5.1 |
| 8 | Uninstall | Remove all data (2.x, PrestaShop habit); keep settings | Remove all | 1.7 |
