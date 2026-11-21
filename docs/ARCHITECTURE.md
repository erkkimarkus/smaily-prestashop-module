# Architecture of Smaily Connect for PrestaShop 3.0.0

This document is the binding architecture of version 3.0.0 of the module `smailyforprestashop`.
It was written on 2026-11-20, before any 3.0.0 code. Workers build to it. The product owner decided
the design-level choices on 2026-11-20; section 15 records them. Sections 1 to 7, 10, 11.3 to 11.5
and 12 describe the code on branch `v3` as of 2026-11-21: stages 1 and 2, the admin screens
(Dashboard, Initial setup, Settings with its tabs Connection, Contacts, Automations and RSS, Log),
the store automations and the new-version check. Section 13 holds the state of each Story. A
sentence that names a Story which section 13 lists as not done describes work that is not built yet.

The observed platform facts of PrestaShop 8 and 9 are in `docs/internal/spikes/platform-facts.md`
(the facts file). Where the facts file and this document differ, the facts file wins, and the section
is corrected.

## How to read this document

Every direction carries one of three markers.

| Marker | Meaning | Who can change it |
|---|---|---|
| **[D]** | A decision that this document takes on the evidence. It binds every worker. | The orchestrator, with a note in this file. |
| **[R]** | A recommendation that waits for the owner. None is left: the owner decided all eight on 2026-11-20, and each former [R] is now [D] (section 15). | The owner. |
| **[O]** | An open question. The answerer is named in section 14. | The named answerer. |

Short names for sibling sources:

| Short name | Path |
|---|---|
| `magento:` | `/Users/erkkimarkus/Downloads/smaily/connect-magento/` (Smaily Connect for Magento, 3.0.0, the closest analog: a PHP module rewritten in place with a legacy upgrade) |
| `woo:` | `/Users/erkkimarkus/Downloads/smaily/connect-woo/` (Smaily Connect for WooCommerce, the reference for platform-neutral parts) |
| contract | `docs/RECENGINE_API_CONTRACT.md` v1.36.0, a byte copy of the engine repository's `docs/RECENGINE_API_CONTRACT.md` (`/Users/erkkimarkus/Downloads/smaily/intelligence/`, the source of truth). Never edited here. |
| report contract | `/Users/erkkimarkus/Downloads/smaily/subscription/docs/contracts/installation-report-v1.md` v1.0.1 |

A sentence about "the module today" describes 2.2.0 (git tag `2.2.0`); the sources that section 0
names are that tag's files. A sentence about "3.0.0" describes the product that this document
specifies.

## Repositories

- `smaily-prestashop-module` (this repository). All 3.0.0 code lands here.
- `subscription` (Smaily subscription service). It must register the product id `connect_prestashop` and
  the platform `prestashop` before the module sends its first installation report (section 11).
- The Campaign Intelligence engine (`intelligence`, named `re` in the contract). It must agree the
  PrestaShop platform key namespace `ps-` (section 8.2). The contract lists this repository among its
  synchronized copies since the re-sync of 2026-11-20. The contract already settles the connection
  prefix and the shop code. The engine changes no code for 3.0.0 unless it rejects a proposal here.

## 0. The module today: facts that the design rests on

| Fact today (2.2.0) | Source | What 3.0.0 does with it |
|---|---|---|
| Technical name `smailyforprestashop`, class `SmailyForPrestaShop`, `module_key` `bcea90ce…`, namespace `PrestaShop\Module\SmailyForPrestaShop\` (PSR-4 on `src/src/` and `src/lib/`). | `src/smailyforprestashop.php`, `src/composer.json` | Kept. |
| Three hooks: `actionCustomerAccountAdd`, `actionCustomerAccountUpdate`, `actionNewsletterRegistrationAfter`. Each calls Smaily synchronously inside the shopper's request. | main class, `OptInController` | Replaced by the event queue (section 7). |
| Two token-protected cron front controllers: `SmailyCustomerCron` (pulls all unsubscribers, then pushes every `customer.newsletter=1` and every active `emailsubscription` row) and `SmailyCartCron` (reminds each customer with a cart from the last 10 days, no order, not in `smaily_cart`). | `controllers/front/`, `CustomerSyncController`, `AbandonedCartController` | Both URLs keep working (section 4). |
| The contact push has no shop filter. It sends the customers of every shop with the config of the shop whose URL the crontab calls. The cart query uses `Shop::addSqlRestriction(SHARE_CUSTOMER)`. | `SubscriberCollection`, `AbandonedCartCollection` | The migration reads the default shop's values for the two cron features (section 3). |
| Table `smaily_cart (id_smaily_cart, id_customer, id_cart, date_sent)`. A row means "reminded". | `Install/Installer.php` | Carried into the new cart tracker (section 3). |
| `SMAILY_PASSWORD` is stored in plain text. | `AccountFormDataConfiguration` | Encrypted, the plain row deleted (section 2). |
| 2.x saves its settings in the admin's current shop context, so a multishop store can hold `SMAILY_*` rows at global, shop-group and shop scope. Observed on 9.2.0: a save in one shop's context writes a row for every key at that shop, also for values equal to the global ones; a later "All shops" save changes only the global rows, and the shop keeps its own older values. | `Form/*FormDataConfiguration.php`; `tests/fixtures/legacy/README.md` "Multistore" | The mapper reads every scope and resolves per shop (section 3.3). |
| PrestaShop stores an empty value (`''`, `false`, `null`) as SQL `NULL`; an integer `0` stays `'0'`. So abandoned cart reads `'0'` after the 2.2.0 install and `NULL` after a merchant switches it off. | `tests/fixtures/legacy/README.md` "Keys" (observed on 9.2.0) | The mapper treats `NULL`, `''`, `'0'` and an absent row as off (section 3.2). |
| 2.2.0 stores `SMAILY_SUBDOMAIN` exactly as typed. 1.6.x kept only `[a-zA-Z0-9]` and took the first label of a full URL. | `tests/fixtures/legacy/README.md` "Keys" | The mapper normalises the subdomain (section 3.2). |
| The cron token check is `!==`. A stored empty token accepts a request with no token. The 1.6.x installer stored `''` as both tokens. | `CustomerSyncController::sync()`, 1.6.2 `install()` | An empty token never authorizes (section 3). |
| Enabling opt-in sets `NW_VERIFICATION_EMAIL=0`, `NW_CONFIRMATION_EMAIL=0`, `NW_VOUCHER_CODE=null` in `ps_emailsubscription`. The previous values are not kept. | `CustomerSyncFormDataConfiguration` | Left as they are (section 3). |
| Install copies `overrides/ps_emailsubscription.php` only when no override exists. Uninstall deletes that path always, also when another module owns the file. | `Installer::copyOverrides()`, `deleteOverrides()` | 3.0.0 ships no override. The upgrade and the uninstall remove the installed file only when its hash is the 2.x copy (section 2.4). |
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
namespace stays `PrestaShop\Module\SmailyForPrestaShop\`, mapped to `src/src/`. `src/lib/` and
`src/overrides/` are gone: Story 1.4 ported `src/lib/` into `src/src/Smaily/`, and 3.0.0 ships no
override (section 2.4).

What `v3` holds today:

```
src/
  smailyforprestashop.php     main class: identity, the $tabs menu, install, uninstall,
                              getContent(), one-line hook methods
  config.xml                  the file PrestaShop writes for its module list; shipped
                              (docs/MARKETPLACE.md)
  upgrade/upgrade-3.0.0.php   PrestaShop upgrade entry point: calls Upgrade300::runPending() (3.7)
  config/services.yml         exposes Runtime\Services to the Symfony container
  config/admin/services.yml,  import one file per admin area (shell, dashboard, setup, settings,
  config/routes.yml           log) from config/admin/services/ and config/routes/
  controllers/front/          tick, SmailyCustomerCron, SmailyCartCron (doors to the tick, section 4;
                              all three extend Scheduler\DoorFrontController);
                              SmailyRssFeed (2.x code, still wired);
                              capture (the checkout address of the cart reminder, section 7)
  src/
    Runtime/                  Services (composition root), Log (the one error-class logger)
                              + Provider/<Area>Provider.php
    Hook/                     Dispatcher: hook name -> listeners from the providers
    Install/                  Installer, Schema, HookList, Uninstaller
    Config/                   Settings (base: get/set, getJson/setJson, reload, storedValue,
                              removeShopValue), TickSettings, MigrationNotices, ContactsSettings,
                              UpdateSettings, AccountSettings, AutomationSettings, EngineSettings,
                              RssSettings (the RSS URL builder's defaults), AdminSettings (the
                              setup state, the invitation's dismissal)
    Migration/                LegacyState, LegacyReader, LegacyConfigMapper (pure), MigrationPlan,
                              Upgrade300, LegacyOverride
    Http/                     Transport (interface), SymfonyTransport, Response, NetworkException
    Smaily/                   Client, Account, AccountStore, Crypto, Exception/*
    Queue/                    EventQueue (with deleteClosed()), Flusher, RetryPolicy, Failure,
                              Skipped, Pending, HandlerInterface, ExchangeSource, ContactEntity;
                              Handler/ContactSyncHandler, Handler/AutomationHandler
    Scheduler/                Tick, TickReport, TickState, Job, Deadline, Lock, Door,
                              DoorFrontController, TrafficTick, QueueJanitor
    Scope/                    StoreLayout, AccountResolver, Scope, ScopeResolver
    Contacts/                 ContactSyncMode, ContactAudience, SubscriberPayloadBuilder,
                              StaleSubscription, Reconciler; Capture/ContactEvents,
                              CustomerCapture, NewsletterBlockCapture, CheckoutBox,
                              CheckoutBoxSetting; Import/ContactImport, ContactSource, JobStore
    Update/                   LatestRelease, UpdateNotice, Version (section 11.5)
    Automation/               Trigger, Router, WorkflowMatch, MappingRepository, WorkflowList,
                              TriggerSender, MarketingConsent, WorkflowResolverInterface;
                              Welcome/, FirstOrder/, AbandonedCart/ (with Capture/)
    Discount/                 TemplateRules, CodeIssuer, AutomationDiscount, IssuedCodes: the
                              discount code of a store automation's email (section 7)
    Log/                      LogRows, LogFilter, LogStatus, FailureMessage, AttemptHistory,
                              PayloadRedactor, Resend, ResendGuard, Retry (section 10.8)
    Admin/                    BaseController, ContextTranslation, EntryController, Menu,
                              NoticeMessages, BackOfficeNotice
    Admin/Dashboard/          DashboardController, DashboardFacts, DashboardQueries, DashboardView,
                              BackgroundTasks, CronLine, EntityLabel
    Admin/Setup/              SetupController, SetupPanels, SetupScope, SetupProgress,
                              ConnectionPanel, ContactsPanel, AutomationsPanel (section 10.9),
                              StoreShapePanel, SmailyCheck
    Admin/Settings/           SettingsController (the page), SettingsPanels, SettingsScope (which
                              values the selected shop reads and saves), TabSaver, ShopOverrides
                              (a shop's own values), LayoutChange (section 5.1), RssPanel
    Admin/Log/                LogController, LogView, Details, MassRetry, SendAgain
    Admin/Api/                AbstractJsonAction, FinishUpgrade, DismissNotice, DismissUpdate,
                              DismissInvitation, Workflows and DiscountRules (the workflow lists
                              and the cart rules of the Automations panel); Setup/ (AbstractSetupAction, SaveStep, TestSmaily,
                              ContactImportAction, ContactImportProgress); Settings/
                              (AbstractSettingsAction, SaveTab, TestConnection, ChangeLayout)
  views/templates/admin/      layout/, dashboard/, setup/, settings/, log/, panel/ (Twig)
  views/js/admin/             common.js, panels.js (shared by the setup and Settings),
                              dashboard.js, setup.js, settings.js, log.js
  views/js/front/             checkout-capture.js
  views/css/admin.css, views/img/ (the logos of section 10.5)
  translations/et-EE/         XLIFF, domain Modules.Smailyforprestashop.Admin
```

The 2.x admin configuration page is gone (Story 3.3): its controller, its forms, its five templates
and its script. The RSS feed is still the 2.x code (`Controller/RssFeedController`,
`Model/RssFeedProductsCollection`), behind `controllers/front/SmailyRssFeed.php`; no folder
`src/src/Rss/` exists, and the feed's output is pinned by `tests/Integration/Rss/RssFeedTest.php`.
The 2.x sync code is gone from the tree: `Controller/CustomerSyncController`,
`Controller/AbandonedCartController` and the subscriber and cart classes of `Model/`. Nothing
reached them: the two cron URLs go through `Scheduler\Door`. The payload that 2.2.0's cart code
built is kept as a recorded fixture (`tests/fixtures/legacy/payloads/`), which `LegacyPayloadTest`
compares the reminder with.

Later Stories add these areas:

```
src/
  controllers/front/          relay, land, recommendations, personalization
  src/
    Engine/                   Client, Connections, Setup, Payload/, IngestQueue, History/,
                              Automations/
    Storefront/               Relay, Attribution, Recommendations, PageContext
    Privacy/                  Export, Erasure, ProfilingConsent
    Report/                   ReportBuilder, ReportSender, InstallationReport, ConnectionHealth
  views/templates/admin/      the Intelligence templates of the setup and Settings
  views/templates/front/      recommendations, personalization (Smarty, as PrestaShop front)
  views/js/front/             consent, attribution, tracker, recommendations
  translations/               the storefront domain Modules.Smailyforprestashop.Shop
```

### 1.2 Service wiring on PrestaShop 8 and 9

**[D]** Runtime code (queues, clients, routers, builders, the tick) is plain PHP. One hand-written
composition root, `Runtime\Services`, builds it lazily. Each area adds its services in its own
`Runtime/Provider/<Area>Provider.php`. Hooks, front controllers, the upgrade script and the admin
controllers all reach the runtime through `Runtime\Services::instance()`. `config/services.yml`
exposes `Runtime\Services` itself to the Symfony container as a public service whose factory is
`Services::instance`, so admin controllers get the same object, and there is one instance graph.

- `Services::PROVIDERS` lists the providers in the order they register. A provider implements
  `ProviderInterface::register(Services $services)` and builds nothing: `set($id, $factory)` stores a
  factory, `get($id)` builds the service on first use and then shares it, and `listen($hook,
  $listener, bool $beforeUpgrade = false)` adds a hook listener. A listener registered with
  `$beforeUpgrade = true` also runs while the schema is not current (below). Three listeners use
  the flag: the scheduler's two traffic-tick hooks (section 4.3) and the admin's line about an
  unfinished upgrade on `displayBackOfficeHeader` (section 3.6).

- **Rationale.** The upgrade script, the hooks and the front controllers run without a guaranteed
  Symfony container of the module: PrestaShop 8 and 9 build different front containers, and an
  upgrade runs in whatever context PrestaShop calls it. The Woo plugin's runtime is plain PHP too, so
  its classes port with no DI rewrite. Magento uses `di.xml` because Magento has one container
  everywhere; PrestaShop has not.
- **Alternatives.** (a) Symfony DI for everything: the upgrade script and front controllers then
  depend on container behaviour that differs between 8 and 9. (b) Static helpers as in 2.2.0: not
  testable.

**[D]** Hooks. The main class declares one method per hook in `Install\HookList`. Each method is one
line: `return Dispatcher::dispatch('<hookName>', $params);`. Listeners register per area in the
providers. Later Stories register listeners in their own provider. The main class changes after Story
1.1 only for the named wirings of section 13 ("The main class").

`Hook\Dispatcher` runs the listeners of a hook:

1. While the schema is not current (`Install\Schema::isCurrent()`, section 3.7), it runs only the
   listeners registered with `$beforeUpgrade = true`. Today these are the two listeners of the
   fallback tick, which can finish a pending migration, and the admin's line about the unfinished
   upgrade. When no listener applies, it returns `null`.
2. It runs each listener in its own `try`/`catch (\Throwable)`. A listener that throws fails alone: the
   other listeners run, and the shop's request goes on.
3. It logs a failure through `Runtime\Log::failure()` with the hook name, the error class and the file
   name and line only. It never logs the error message, which can hold a secret or an address.
   `Log::failure()` is the one logger of this form: the tick and the upgrade script use it too.
4. It combines the listeners' results: strings join (display hooks), arrays merge (`moduleRoutes`,
   `additionalCustomerFormFields`), and any other value replaces the previous one. A `null` result
   changes nothing.

**[D]** The one line a later Story writes, in its own provider:

| To | Write |
|---|---|
| Register a queue handler | `$services->set(QueueProvider::handlerId('<type>'), fn (Services $s) => new MyHandler(…));` (section 4.3) |
| Enqueue an event | `$services->get(QueueProvider::EVENT_QUEUE)->enqueue('<type>', $idShop, $payload, $entityId, $language);` |
| Register a scheduler job | `$services->listen(SchedulerProvider::JOBS, fn (): Job => new Job('<name>', $intervalSeconds, $budgetSeconds, function (Deadline $deadline) use ($services): void { … }, $runsBeforeUpgrade));` The last argument defaults to `false`; only the `migration` job sets it. The same commit adds the job to the two tests that pin the tick's full job list (section 12). |
| Log a failure | `Log::failure('<what failed>', $error);` (error class, file name and line; never the message) |
| Read or write a setting | A subclass of `Config\Settings`: `get()`/`set()`, `getJson()`/`setJson()` for a JSON value, `storedValue()` for the value stored now (past this request's cache), `reload()` to load the configuration again (section 2.1). |
| Run code before uninstall removes the data | `$services->listen(Uninstaller::BEFORE_REMOVAL, function (array $params) use ($services): void { … });` (section 11.4) |
| Resolve the scope of a record | `$services->get(ScopeResolver::class)->forRecord($customerOrOrderOrCartOrRow)`, or `->resolve($idShop, $idLang)` (section 5.2) |

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
  `Symfony\Contracts\HttpClient\HttpClientInterface` only, behind `Http\Transport`
  (`Http\SymfonyTransport`). The component is present on 8.0.0 and 9.2.0 (facts file 1.1), and the
  API client smoke check of `tests/ci/validate.sh` passed on 8.0.0, 8.2.8 and 9.2.0. 8.0 ships the
  contracts at v1 and 9.2 at v3, so the module calls only the methods that both share.
- Encryption: `PhpEncryptionEngine` from PrestaShop core (section 2.3).

### 1.5 PrestaShop 8 and 9 differences that matter

| Area | PrestaShop 8.x | PrestaShop 9.x | Decision |
|---|---|---|---|
| PHP | 7.2–8.1 (module floor 8.0) | 8.1+ | **[D]** Code to PHP 8.0 syntax (no enums, no `readonly`). CI lints on 8.0. No hard reason to raise the floor was found. |
| Symfony | 4.4 | 6.4 | **[D]** Admin controllers extend `FrameworkBundleAdminController` through `Admin\BaseController`, as 2.2.0 does on 9. Verified: it works on 9.2.0, but it is deprecated since 9.0, and its successor `PrestaShopAdminController` exists only from 9.0 (facts file 1.2). Story 3.1 keeps `FrameworkBundleAdminController` and accepts the deprecation notices on 9.x; the move waits until the module drops 8.x. |
| Twig | 2/3 | 3 | **[D]** Twig 3 syntax that Twig 2 also parses. |
| Product page | legacy page (8.0), v2 page optional (8.1+) | v2 page default | **[D]** Catalog capture listens to ObjectModel hooks. Verified: both pages fire them. One save on the v2 page fires `actionObjectProductUpdateAfter` 7 times, and a "Save list" of combinations fires `actionObjectCombinationUpdateAfter` for every row of the list (facts file 1.3 and part 2). Story 5.2 therefore deduplicates catalog events per product per request. |
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
context. A read of a shop returns the shop's own row when that row exists, else the global row. This
mirrors Magento's default + website scopes (`magento:docs/ARCHITECTURE.md` "Multi-website").
`Config\Settings` is the one place that reads and writes the module's keys this way.

PrestaShop's `Configuration` behaves as follows on 8.2.8 and 9.2.0 (facts file 1.5 and 3.1):

1. A shop row that holds `NULL` does not fall back. A read returns `''`, and `hasKey()` for that shop
   is true. The group row, then the global row, apply only when the shop has no row.
2. A write of `null` and then a write of a value to the same key inside one request loses the second
   write: the database keeps `NULL`.
3. `deleteFromContext()` does nothing in the "All shops" context.
4. `updateValue($key, $value, false, null, 0)` inside a shop or group context writes a group row. A
   global write therefore uses `updateGlobalValue()`, and a shop write passes both the shop group id
   and the shop id (as `Config\Settings::set()` does).
5. While multishop is off, PrestaShop ignores the shop id in `Configuration::get()` and
   `updateValue()`: the shop and the global scope hold one value. This holds also where the `shop`
   table has more than one shop (multistore was on once; facts file 6.9): a value written for a
   second shop overwrites the global row, `Shop::getShops()` still lists every shop, `hasKey()`
   still sees a shop's row that no `get()` reads, and the storefront still serves every shop.

PrestaShop drops its loaded configuration in two more places (read from code on 8.0.0 and 9.2.0,
facts file part 6): `deleteByName()` of any key, and `resetStaticCache()`. After either, `hasKey()`
reads nothing until `Configuration::get()` or `loadConfiguration()` loads the configuration again.

**[D]** Consequences:

- **The settings store (`Config\Settings`).** `Settings::get()` loads the configuration
  again when the request holds none, so a read after a `deleteByName()` in the same request still
  finds the stored value. `Settings::set()` writes a global row with `updateGlobalValue()` and a shop
  row with the shop group id and the shop id. `Settings::removeShopValue($key, $idShop)` deletes the
  shop's row with `Configuration::deleteFromGivenContext($key, $idShopGroup, $idShop)`, whatever the
  admin's shop context; the shop then reads the global value. It never writes `NULL`, and it never
  uses `deleteFromContext()`. The writer never writes `NULL` and then a value to one key in one
  request. `getJson()` and `setJson()` read and write a JSON value (an unreadable value reads as
  `[]`). `reload()` is the one way to load the configuration again (`resetStaticCache()`, then
  `loadConfiguration()`). `storedValue($key, $idShop)` reads the row stored now at exactly that
  scope with one SQL query, past the configuration that the request loaded, so it also sees a value
  that another request wrote after this one started.
- **The mapper (Story 1.3).** A shop row that holds `NULL` is that shop's effective value (off). It
  does not inherit the global value (section 3.3). With multistore off it plans the global scope
  alone, whatever number of shops the store holds.
- **The upgrade (Story 1.7).** The migration writes global rows with `updateGlobalValue()` and shop
  rows with both ids, never `NULL`, and a shop row only while multistore is on. It deletes the legacy keys at every scope with one SQL `DELETE`
  inside the transaction that also marks the schema current (section 3.7). After a direct SQL write,
  it calls `Configuration::resetStaticCache()` and then `loadConfiguration()`.

**[D]** Every key the module writes or reads is listed in `Install\Uninstaller::KEYS` (section 11.4).
The table below holds its 3.0.0 keys and the two kept tokens; section 2.4 holds the other legacy
keys. "Not written yet" marks a key that the code names but no code on `v3`
writes.

| Key | Scope | Content |
|---|---|---|
| `SMAILY_CONNECT_SCHEMA_VERSION` | global | The schema version the install or the migration completed. Only `Install\Schema` reads and writes it. While it differs from `Install\Schema::VERSION`, the runtime runs only what section 3.7 names. |
| `SMAILY_CONNECT_SETUP_STATE` | global | `new` or `upgraded`, written by the migration; `done`, written when the Initial setup is finished (`Config\AdminSettings`, section 10). A fresh install stores none, which reads as a new store. |
| `SMAILY_CONNECT_INVITATION_DISMISSED_AT` | global | The Unix time at which an admin dismissed the invitation to create a Smaily account; the invitation stays away for 90 days, for every admin (`Config\AdminSettings`). |
| `SMAILY_CONNECT_MIGRATION` | global | JSON `{"from": "<previous version>", "status": "pending"\|"failed"\|"done", "done": ["<step>", …], "failure": {"step": "<step>", "error": "<error class>"}\|null}` (section 3.7). |
| `SMAILY_CONNECT_NOTICES` | global | JSON list `[{"id": "<12 hex>", "code": "<code>", "params": {…}}]` in the order the notices came; `Config\MigrationNotices` adds a code with the same parameters once, and `dismiss($id)` removes it (section 3.6). |
| `SMAILY_CONNECT_SEPARATE_STORES` | global | `0` one store in several versions, `1` separate stores. |
| `SMAILY_CONNECT_LANGUAGE_MODE` | global or shop | `single`, `a`, `b`, `c`. |
| `SMAILY_CONNECT_FALLBACK_LANGUAGE` | global or shop | The fallback language's ISO code. `Scope\StoreLayout` reads it and writes it from the Connect step and Settings > Connection. |
| `SMAILY_CONNECT_CONTACTS_ENABLED`, `_CONTACTS_CONFIRMED` | global or shop | `1` = on. Contact sync is active for a shop only when both read `1` (`ContactsSettings::syncActive()`); a shop with nothing stored syncs nothing (section 6.4). The upgrade writes `_CONFIRMED = 1` only where it carried an active 2.x sync over. |
| `SMAILY_CONNECT_CONTACTS_MODE` | global or shop | `subscribers`, `all_customers` or `checkout_optin` (section 6.2). Any other value reads as `subscribers`. The upgrade writes `subscribers`. |
| `SMAILY_CONNECT_CONTACTS_GUESTS` | global or shop | `1` = "include guests" (section 6.2). The upgrade writes `1`. |
| `SMAILY_CONNECT_CHECKOUT_CHECKBOX` | global or shop | `1` = the module's own checkout box (section 6.1). The upgrade writes `0`; nothing stored (a new install) reads as on. The Contacts panel writes it, and `Contacts\Capture\CheckoutBoxSetting` reads it for the box. |
| `SMAILY_CONNECT_CONTACTS_FIELDS` | global or shop | Comma list of the contact fields to send, `store` first, then the known names of `first_name,last_name,birthday,website` that the legacy list ticked, in that order. The code reads only the four names; it sends `store` always. |
| `SMAILY_CONNECT_RECONCILE_CURSORS` | global | JSON object: the reconcile's cursor per Smaily account (section 6.5). |
| `SMAILY_CONNECT_WELCOME_ENABLED`, `_FIRST_ORDER_ENABLED`, `_CART_ENABLED`, `_CART_DELAY_MINUTES` | global or shop | Automations section. Workflows live in the mapping table. The cart delay is saved from 10 to 1435 minutes and reads 30 where none is stored, as in Magento: a cart older than 24 hours is never reminded, so a longer delay would remind nobody. A stored value below 10 reads 10. The upgrade writes 1435 for a 2.x delay above it (section 3.3), so no code stores a longer delay. |
| `SMAILY_CONNECT_WELCOME_DISCOUNT_ENABLED`, `_WELCOME_DISCOUNT_RULE`, `_WELCOME_DISCOUNT_DAYS`, and the same three for `_FIRST_ORDER_DISCOUNT_` and `_CART_DISCOUNT_` | global or shop | Each store automation's discount code (section 7): `1` = "Send a discount code" is on; the id of the cart rule that the codes are copies of; the days a code is valid, saved from 1 to 365 and read as 7 where none is stored. They belong to the Automations section: a shop's own automations hold them (`ShopOverrides`). |
| `SMAILY_CONNECT_RSS_CATEGORY`, `_RSS_LIMIT`, `_RSS_SORT_BY`, `_RSS_SORT_ORDER` | global | The RSS URL builder's defaults: the category id (`0` = all products), the number of products (1 to 250), the sort field and `desc` or `asc`. The migration writes each only when the legacy value is valid. `Config\RssSettings` reads them, and `Admin\Settings\RssPanel` writes them from Settings > RSS; a value that is not stored, or that the feed does not take, reads as the feed's own default (50 products, `date_upd`, `desc`). The feed reads only its URL's parameters, never these keys. |
| `SMAILY_CONNECT_TICK_TOKEN` | global | The tick URL's token, 32 hex characters. The upgrade creates it (section 3.7); on a fresh install the Dashboard's "Show the cron line" creates it (section 4.3). A request to a door never creates a token. |
| `SMAILY_CONNECT_TICK_STATE` | global | JSON tick state (section 4.3). |
| `SMAILY_CONNECT_FALLBACK_TICK` | global | Section 4. Not written yet. |
| `SMAILY_CONNECT_CONSENT_CONFIRMED` | global or shop | `1` once the merchant confirmed that the store's cookie banner covers Smaily tracking; absent or `0` = not confirmed (section 9). Not written yet. |
| `SMAILY_CONNECT_HEALTH`, `_REPORT_STATE` | global | Section 11. Not written yet. |
| `SMAILY_CONNECT_UPDATE_CHECK` | global | JSON `{"version", "download_url", "release_url", "checked_at", "dismissed"}`: the latest release that the daily check found, and the newest version that the merchant dismissed (section 11.5). |
| `SMAILY_CUSTOMER_CRON_TOKEN`, `SMAILY_CART_CRON_TOKEN` | as stored | **Kept with their legacy names.** They are the URL contract in merchants' crontabs. A door reads its token at the request's shop, then its shop group, then the global scope, as 2.x did. A token that no scope holds is created at the global scope on its first read. A token stored empty keeps its door closed; the migration replaces it (section 3.4). |

### 2.2 Tables

**[D]** All tables use `_DB_PREFIX_` + the names below, `ENGINE=_MYSQL_ENGINE_`, `utf8mb4` with the
collation `utf8mb4_general_ci`. Story 1.1 creates every table in `Install\Schema`, so later stories
change no installer code. A later column change is a new upgrade script that raises
`Install\Schema::VERSION`, never an edit of an applied one.

Column rules of `Install\Schema`:

- `created_at` and `updated_at` are `NOT NULL` without a default: the code writes them.
- `trigger` and `cursor` are reserved words in MySQL and MariaDB. Every query writes them in
  backticks.
- **[D]** (orchestrator, 2026-11-20) `language` is `CHAR(2) NOT NULL DEFAULT ''` in
  `smaily_connect_account` and `smaily_connect_automation_mapping`. `''` means "no language": the
  shop's account, or the default account. A MySQL or MariaDB UNIQUE key treats two `NULL` values as
  different, so a nullable `language` lets two rows with "no language" exist for one shop.
  `Install\Schema` holds this since Story 1.4.
- In the queue tables, `entity_id` and `next_retry_at` are nullable.

| Table | Purpose | Columns (level of detail for review) | Indexes |
|---|---|---|---|
| `smaily_connect_event_queue` | Marketing events to Smaily: `contact.sync`, `automation.welcome`, `automation.first_order`, `automation.abandoned_cart`; stage 5 adds `engine.identity_merge` and `engine.profiling_consent` | `id` BIGINT PK, `id_shop` INT, `event_type` VARCHAR(48), `entity_id` VARCHAR(64) NULL (address, or its keyed hash when longer, as `magento:Model/Queue/ContactEntity`), `language` CHAR(2) NULL, `payload` MEDIUMTEXT JSON, `status` VARCHAR(16) (`pending`, `processing`, `sent`, `failed`, `skipped`), `attempts` TINYINT, `next_retry_at` DATETIME NULL, `claim_token` CHAR(32) NULL, `last_error` VARCHAR(255) NULL, `last_request` MEDIUMTEXT NULL, `last_response` TEXT NULL, `created_at`, `updated_at`, `sent_at` NULL | (`status`,`next_retry_at`), (`entity_id`,`event_type`,`status`), (`created_at`) |
| `smaily_connect_ingest_queue` | Engine ingest: `catalog`, `catalog_remove`, `customers`, `orders` | `id` BIGINT PK, `id_connection` INT, `domain` VARCHAR(16), `entity_key` VARCHAR(80), `payload` MEDIUMTEXT NULL (a catalog row is a marker; the payload is built at flush, see `woo:docs/LESSONS.md` §2.24), the status, attempt, claim and error columns as above, `last_request`, `last_response`, `created_at`, `updated_at`, `sent_at` | (`domain`,`status`,`next_retry_at`), (`entity_key`,`domain`,`status`), (`created_at`) |
| `smaily_connect_job` | Imports: contacts import, catalog import, history import, daily refresh. Story 2.4 writes the types `contacts_import` and `contacts_refresh` (section 6.5). | `id` PK, `type` VARCHAR(32), `id_shop` INT, `id_connection` INT NULL, `status` (`queued`,`running`,`done`,`failed`,`cancelled`), `cursor` VARCHAR(255), `counts` TEXT JSON, `import_id` VARCHAR(64) NULL, `started_at`, `updated_at`, `finished_at`, `last_error` | (`status`,`type`) |
| `smaily_connect_account` | Smaily accounts per shop and language | `id` PK, `id_shop` INT (0 = all shops), `language` CHAR(2) NOT NULL DEFAULT '' (`''` = the shop's account, or the fallback account in mode A), `subdomain` VARCHAR(63), `username` VARCHAR(128), `password_enc` TEXT NOT NULL, `date_add`, `date_upd` | UNIQUE (`id_shop`,`language`) |
| `smaily_connect_automation_mapping` | (shop, trigger, language, account) → workflow | `id` PK, `id_shop` INT (0 = all shops), `trigger` VARCHAR(32) (`welcome`,`first_order`,`abandoned_cart`), `language` CHAR(2) NOT NULL DEFAULT '' (`''` = no language), `account_key` VARCHAR(16) (`default` or a language), `workflow_id` INT NULL (NULL with `optin_trigger=1` = the 2.x generic opt-in, decision 5; NULL with `optin_trigger=0` at a shop = "no workflow here", which hides the shared row of the same key from that shop, sections 5.2 and 10.9), `optin_trigger` TINYINT, `is_default_fallback` TINYINT, `date_upd` | UNIQUE (`id_shop`,`trigger`,`language`,`account_key`) |
| `smaily_connect_cart` | Abandoned-cart tracker and reminder state (section 7) | `id_cart` INT PK, `id_shop` INT, `id_customer` INT NULL, `email` VARCHAR(255) NULL, `id_lang` INT, `status` VARCHAR(16) (`open`, `mailed`, `skipped` are the values that `Automation\AbandonedCart\CartTracker` writes), `last_activity_at` DATETIME, `mailed_at` DATETIME NULL, `checkout_optin` TINYINT, `event_id` BIGINT NULL, `skip_reason` VARCHAR(64) NULL | (`status`,`last_activity_at`), (`email`) |
| `smaily_connect_connection` | Campaign Intelligence connections | `id` PK, `id_shop` INT (0 = installation-wide), `smaily_subdomain` VARCHAR(63), `tenant_id` CHAR(36), `api_key_enc` TEXT NOT NULL, `settings` MEDIUMTEXT NOT NULL JSON (tenant name, base URL, engine version, endpoints map, `config` incl. `history` and `connection.prefix`, `issued_at`, `refused_at`, browse switch), `date_add`, `date_upd` | UNIQUE (`id_shop`) |
| `smaily_connect_order_attribution` | Email-click attribution per order | `id_order` INT PK, `rec_id` CHAR(36) NULL, `context` VARCHAR(64) NULL, `visitor_token` VARCHAR(64) NULL, `created_at` | — |
| `smaily_connect_privacy` | Durable profiling opt-out record (contract §10; `magento:` parity audit R1) | `address_key` CHAR(64) PK (keyed hash of the trimmed lower-case address), `opted_out` TINYINT, `changed_at`, `source` VARCHAR(16) | — |

**Rationale for an account table instead of language-scoped configuration.** PrestaShop's
language-scoped configuration keys by `id_lang`, which differs between installations; an account is
three values that change together; and the admin must list which shops hold own accounts. A table
answers all three with one query.

**[D]** No separate log table. The Log screen reads the two queue tables and the job table, as
Magento's unified Log does (`magento:Controller/Adminhtml/Log/*`).

**The event queue's columns** (`Queue\EventQueue`, Story 1.5):

- Every time is UTC.
- `updated_at` changes when a flush claims the row and at every outcome.
- A row in `processing` whose `updated_at` is older than 10 minutes belongs to a flush that died. The
  next flush returns it to `pending`.
- `attempts` counts failed attempts. A row that a handler gives back (`Pending`) spends no attempt.
- `last_error` holds the reason of the last failure or skip, cut to 255 characters: a permanent
  refusal as `permanent_http_<code>: <message>` or `permanent_envelope_203: <message>`, where the
  message is the client's own text (the status or the code); a handler's own reason; or, for a thrown
  error, its class, file, line and code. It never holds the error's message, a request body or the
  remote server's text. A sent row has no `last_error`.
- `last_request` and `last_response` hold what the latest attempt sent for the row and what came
  back, for the Log's Details (Story 3.4, section 10.8). `last_request` is the row's own part of the
  request body as JSON: one contact of a batched contact sync, never the contacts posted with it.
  `last_response` is `{"http_status": <code>, "body": <the answer's JSON>}`, without any `message` of
  the answer, because Smaily's text can name a contact; an answer that is not JSON, or one longer
  than the column, keeps its status only. An attempt that got no answer keeps the answer of an
  earlier attempt. A row for which nothing was sent has neither. The contact's data is stored as it
  was sent (section 15); a value under a key that looks like a secret is stored as `[redacted]`
  (`Log\PayloadRedactor`). A handler tells the flusher what it sent through `Queue\ExchangeSource`,
  and `EventQueue::recordExchange()` writes it under the row's claim. The same columns of the ingest
  queue are written by Story 5.2.
- A row that the Log's "Send again" queued carries the record `_resend` in its payload (section
  10.8).
- `entity_id` of a contact's row (a contact sync or an automation) comes from
  `Queue\ContactEntity::of()`, Magento's rule: an address of at most 64 characters is the entity
  as the row's writer holds it (a contact sync stores it trimmed and in lower case); a longer
  address is its keyed hash, 64 hex characters, HMAC-SHA256 over `smaily-connect-address|` and the
  address, trimmed and in lower case, with the shop's `_NEW_COOKIE_KEY_` (section 2.3). Every
  spelling of a long address has the same entity, so the rows of one contact match each other
  (section 10.8). The hash changes when `parameters.php` gets a new key; rows written before and
  after that no longer match.

**The cart tracker after an upgrade** (`smaily_connect_cart`, Story 1.7). The migration copies every
`smaily_cart` row that names a cart (`id_cart` not `NULL`) with `INSERT IGNORE`: `status` =
`mailed`, `mailed_at` and `last_activity_at` = `date_sent`, `id_customer` as stored, `email` `NULL`,
`id_shop` and `id_lang` from the cart, or the default shop and the default language when the cart no
longer exists. A row without `id_cart` names no cart and is skipped. A cart that `smaily_cart` records
more than once gets one row, with the latest `date_sent` (section 3.5). `smaily_cart` itself stays
until uninstall (section 2.4).

### 2.3 Secrets

**[D]** The Smaily API password and the engine API key are stored encrypted with PrestaShop's
`PhpEncryptionEngine` (defuse/php-encryption, bundled in core) keyed by `_NEW_COOKIE_KEY_`.
`Smaily\Crypto` is the one place that encrypts and decrypts. It builds a `PhpEncryptionEngine` of its
own for each call. The plain `PhpEncryption` class keeps one engine for the whole PHP process, so a
`PhpEncryption` built with another key would change the key of the shop's own cookie encryption (read
from code on 8.0.0 and 9.2.0, facts file part 6). `PhpEncryption` and `_NEW_COOKIE_KEY_` round-trip a
value on 8.2.8 and 9.2.0, and a value still decrypts after the shop domain changes (facts file 1.4).
The key comes from `app/config/parameters.php`; a new `parameters.php` (a reinstall, or a move that
regenerates it) breaks decryption.

- A value that does not decrypt (for example after a move to a server with a new cookie key) reads as
  "no credentials": `Crypto::decrypt()` returns `null`, and `Smaily\AccountStore` gives the account an
  empty password. The admin shows "Reconnect the account". It never throws into a hook.
- No secret enters a log line, a queue row, the Log screen or the installation report.

### 2.4 What happens to each legacy key and table

| Legacy item | 3.0.0 |
|---|---|
| `SMAILY_SUBDOMAIN`, `SMAILY_USERNAME` | Migrated into `smaily_connect_account`, then deleted. |
| `SMAILY_PASSWORD` | Encrypted into `smaily_connect_account`. Deleted only after a read-back decrypts to the same value. |
| `SMAILY_ENABLE_CUSTOMER_SYNC`, `SMAILY_ENABLE_CRON` (1.6.x), `SMAILY_SYNCRONIZE_ADDITIONAL`, `SMAILY_OPTIN_ENABLED`, `SMAILY_OPTIN_AUTORESPONDER`, `SMAILY_ENABLE_ABANDONED_CART`, `SMAILY_CART_AUTORESPONDER`, `SMAILY_ABANDONED_CART_TIME` | Migrated (section 3), then deleted. |
| `SMAILY_CART_SYNCRONIZE_ADDITIONAL` | Read once for the rule of section 3.3 (2.x stopped on a list that is not JSON), then deleted. Its selection is not migrated: the reminder always carries every field (section 7). |
| `SMAILY_RSS_CATEGORY_ID`, `_LIMIT`, `_SORT_BY`, `_SORT_ORDER` | Migrated to `SMAILY_CONNECT_RSS_*`, then deleted. |
| `SMAILY_CUSTOMER_CRON_TOKEN`, `SMAILY_CART_CRON_TOKEN` | **Kept and read.** Every token row that is empty or `NULL`, and the global row when none exists, gets a new random token (32 hex characters) at its own scope, with a `cron_token_replaced` notice each (section 3.4). A non-empty token never changes. |
| Table `smaily_cart` | Rows copied into `smaily_connect_cart` as `mailed` (section 2.2). **[D]** The upgrade leaves the table untouched; uninstall drops it (section 11.4). A later release drops it on upgrade, so until then a manual reinstall of 2.x does not resend reminders. |
| `override/modules/ps_emailsubscription/ps_emailsubscription.php` | `Migration\LegacyOverride` deletes it only when its SHA-256 equals the one hash in `LegacyOverride::SHIPPED_HASHES`: every 2.x tag from 2.0.0 to 2.2.0 shipped the same file. It then removes the folder when the folder is empty. Any other content stays, with the notice `override_left_in_place`. PrestaShop loads a module override by its path on each request and keeps no class index of it, so deleting the file is enough (facts file part 6). |
| `NW_VERIFICATION_EMAIL`, `NW_CONFIRMATION_EMAIL`, `NW_VOUCHER_CODE` | Not touched. 3.0.0 never writes them. 2.x never kept the earlier values, so no code can restore them: where a 2.x save had the opt-in on, the migration leaves the notice `emailsubscription_emails_changed` (2.x switched off `ps_emailsubscription`'s verification and confirmation emails and cleared its voucher). Settings > Contacts shows a note when `ps_emailsubscription`'s own emails are on while the welcome automation is on. |
| Hooks registered by 1.6.x (`footerBefore`, `leftColumn`, `rightColumn`, `backOfficeHeader`, `actionFrontControllerSetMedia`) or 2.x | The migration registers the 3.0.0 list, then unregisters every hook that `Install\HookList` does not name. |
| The hidden admin tab `AdminSmailyforprestashopAjax` of 1.6.x | Deleted through PrestaShop's `Tab` object, which also removes its authorization roles (migration step `tab`). |
| The 2.x configuration page's tab `SmailyForPrestaShopConfiguration` | PrestaShop created it from the 2.x `config/routes.yml`. The 2.x page and its route are gone (Story 3.3), and no 3.0.0 code names the tab: whether an upgraded store keeps its row is not verified (section 14, row 15). |

---

## 3. The legacy → 3.0.0 migration

### 3.1 Shape

**[D]** Two parts, as in Magento (`magento:Model/Migration/LegacyConfigMapper.php`,
`magento:Setup/Patch/Data/MigrateLegacyConfig.php`):

1. `Migration\LegacyReader` reads raw state from the tables, not from PrestaShop's configuration
   cache: every legacy `SMAILY_*` row of `configuration` with its (`id_shop_group`, `id_shop`), the
   shops and their groups, the groups that share customers, whether multistore is on, the default
   shop, the number of `smaily_cart` rows, and the previous module version from `module.version`. It
   writes nothing.
2. `Migration\LegacyConfigMapper::map(LegacyState): MigrationPlan` is pure: no PrestaShop class, no
   database, no clock, no randomness. It returns the settings per scope, the account rows, the mapping
   rows, the token rows to replace, whether to copy the reminded carts, the legacy keys to delete and
   the notices. Unit tests run it on every rule and on the fixtures in `tests/fixtures/legacy/`.
3. `Migration\Upgrade300::runPending()` applies the plan (section 3.7). `Migration\LegacyOverride`
   removes the 2.x override file (section 2.4). `upgrade/upgrade-3.0.0.php` calls only `runPending()`.

### 3.2 Inputs the mapper accepts

| Input | 2.x format | 1.6.x format |
|---|---|---|
| Field lists | JSON map `{"first_name": true, …}` | PHP-serialized list `["first_name", …]`. Read with `unserialize($v, ['allowed_classes' => false])`; a list of contact fields that does not decode is an empty list plus the notice `field_list_unreadable`. |
| Contact sync switch | `SMAILY_ENABLE_CUSTOMER_SYNC` | `SMAILY_ENABLE_CRON` |
| Booleans | PHP truthiness: `'1'` on; `'0'`, `NULL`, `''`, absent off. A switched-off 2.x setting is `NULL`, not `'0'` (section 0). | `'1'` on, anything else off. |
| Subdomain | As typed. | Only `[a-zA-Z0-9]`. |
| Previous code version | `2.0.0`–`2.2.0` | `1.x` (a version that starts with `1.`) |

**[D]** The mapper normalises the subdomain for the account row: trimmed, lower case, the first label
when the value is a URL or a `*.sendsmaily.net` host; hyphens are kept. It compares accounts by the
normalised value (the engine compares `smaily_subdomain` trimmed and case-insensitively, contract §1).
A value that 1.6.x stripped is kept as it is: the mapper cannot restore a removed character.

### 3.3 Inference rules

**[D]** The mapper first computes, per shop, the **effective legacy value** of each key exactly as the
previous code resolved it in that shop's context:

1. With multistore off, it reads only the global row (PrestaShop ignored the shop then), and it
   takes no row of a shop or a shop group, also not a cron token's.
2. With multistore on, it reads the shop row when it exists, else the shop-group row when it exists,
   else the global row.
3. A row that exists wins whatever it holds. A shop or group row that holds `NULL` or `''` reads as
   `''` (off) and does not fall back to the global row (facts file 1.5; section 2.1).

It then derives what that code **actually sent** in that shop, and switches on only that. This
answers the class of Magento's release-candidate defects, which came from inherited values switching
features on at scopes that never sent them (`magento:docs/UPGRADING.md` "What migrates
automatically"). The observed 2.2.0 behaviour confirms the need: after an "All shops" save, a shop with
its own rows keeps its older values (`tests/fixtures/legacy/README.md` "Multistore").

The previous code ran its two crons in one shop's context. The mapper assumes the default shop
(`PS_SHOP_DEFAULT`): the cron URL in the crontab decides the shop context, and the module cannot see
the crontab. The contact sync of 2.x pushed every shop's subscribers through that context's account;
the cart cron reminded the carts of the default shop and of the shops in its group when the group
shares customers. Hooks (the welcome opt-in) ran in each shop's own context.

| 3.0.0 setting | On at a scope when | Reason codes when off |
|---|---|---|
| Account | Subdomain, username and password are all non-empty at that scope. | — |
| Contact sync | All of these: the default shop's switch was on (2.x `SMAILY_ENABLE_CUSTOMER_SYNC` truthy; 1.x `SMAILY_ENABLE_CRON` = `'1'`); the default shop had an account; the scope's account equals the default shop's. | `switch_not_read` (2.x code with only the 1.6.x switch on); `not_default_shop` (the default shop's switch off, this shop's on); `no_account`; `other_account`. |
| Contact sync settings | Where contact sync stays on, `_CONTACTS_FIELDS` comes from the default shop's field list; elsewhere from the scope's own. `_CONTACTS_CONFIRMED` equals `_CONTACTS_ENABLED`. | — |
| Consent mode | Always `subscribers` with guests on, checkout checkbox off. | 2.x synced `customer.newsletter=1` plus active `emailsubscription` rows; this is the narrowest mode that covers it. |
| Welcome | `SMAILY_OPTIN_ENABLED` on in the scope's own context, an account at the scope, and a workflow id that is empty or a positive integer. | `no_account`; `no_workflow` (a workflow value that is not a positive integer). |
| First order | Off. | Did not exist. |
| Abandoned cart | All of these, read at the default shop: `SMAILY_ENABLE_ABANDONED_CART` on; an account; `SMAILY_CART_AUTORESPONDER` a positive integer; for 2.x code, `SMAILY_CART_SYNCRONIZE_ADDITIONAL` is a JSON map. Also: the cart cron reached the scope's carts (the default shop, or a shop in the default shop's group when that group shares customers), and the scope's account equals the default shop's. | `not_default_shop`; `no_account`; `no_workflow`; `field_list_not_json` (2.x stopped with a `TypeError` on such a list, section 0); `carts_not_reached`; `other_account`. |
| Abandoned cart settings | Where the feature stays on, `_CART_DELAY_MINUTES` comes from the default shop; elsewhere from the scope's own. The legacy minutes stay as stored from 10 to 1435: 15, 60 or 600 keep their value. A value from 1 to 9 becomes 10; an empty one becomes 15, which 2.x installed and showed. A delay above 1435 minutes becomes 1435, with the notice `cart_delay_capped`: 2.x reminded such a cart, and 3.0.0 reminds no cart older than 24 hours, so the longer delay would remind nobody (owner, 2026-11-21; section 15). The 2.x field selection is not migrated (section 7). | — |
| Mapping rows | Welcome: the chosen workflow id, or the generic opt-in trigger row (`workflow_id` `NULL`, `optin_trigger` 1) where the welcome stays on without one (decision 5). Abandoned cart: the chosen workflow id. A chosen workflow id is seeded also where the feature stays off, so the merchant finds it when he switches the feature on. | — |
| Language mode | `single`. | 2.x had one account. |
| Shop layout | Separate stores when no global account exists and at least two shops hold different own subdomains. Otherwise one store. | Magento `WebsiteOverrides::holdsSeparateAccounts()`. |
| Setup state | `upgraded` when any account exists, else `new`. | Section 10. |

**[D]** Where the settings land:

- **One store.** The global scope gets the global settings. A shop gets an own row only for a key whose
  value differs from the global one, an own account row only when its account differs from the global
  one, and an own mapping row when it has an own account or its workflow differs.
- **Separate stores.** Every shop gets every setting, its account and its mapping rows at its own
  scope; the global scope keeps only the layout, the setup state and the RSS keys.
- **Multistore off.** One store at the global scope, however many shops the `shop` table holds
  (multistore was on once and a shop was added). The plan covers the default shop alone, which the
  global scope stands for: no shop gets a setting, an account or a mapping row, no notice names
  another shop, and a token row of a shop or a shop group is neither read nor replaced. PrestaShop
  leaves no other way: a setting written for a second shop lands on the global row (section 2.1,
  item 5). The plan reads the default shop and does not merge the shops, because under 2.x every
  shop read the same global rows; the shops differed only in whose carts the cart cron reached,
  and the store's one value is that of the shop the cron ran in.

  Before this rule the plan gave a second shop `_CART_ENABLED = 0` (its carts were not reached);
  that write switched the reminder off for the whole store, and `verify` then failed on every run
  (observed on 8.2.8 and 9.2.0). A development build that stopped this way finishes on its next
  run; its abandoned-cart switch stays off until the merchant switches it on in Settings.

  **Limit.** 3.0.0 runs every shop under that one value: the abandoned-cart sweep reminds the
  carts of every shop that the table holds, where the 2.x cron reminded the carts of the shop whose
  URL the crontab named. A second shop that still takes orders with multistore off therefore gets
  reminders that 2.x did not send. Contact sync is not wider: 2.x pushed every shop's subscribers.

**[D]** The multishop edge (orchestrator, under the rule "never widen the audience"; the owner may
overturn it): a shop whose own Smaily account differs from the default shop's gets contact sync and
abandoned cart off after the upgrade, with a notice (`other_account`). 2.x sent that shop's contacts
through the default shop's account, and 3.0.0 does not move them to another account silently.

**Notices** (`MigrationPlan::NOTICE_*`; `MigrationNotices::OVERRIDE_LEFT_IN_PLACE`), each with its
parameters:

| Code | When | Parameters |
|---|---|---|
| `settings_migrated` | Any account existed. | — |
| `contact_sync_left_off`, `welcome_left_off`, `abandoned_cart_left_off` | A feature stays off at a scope for a reason above. A reason that lies at the default shop is told once, for the default shop. | `id_shop`, `reason` |
| `cart_delay_capped` | A legacy cart delay above 1435 minutes was written as 1435 at any scope. Told once. | — |
| `field_list_unreadable` | The legacy list of contact fields (`SMAILY_SYNCRONIZE_ADDITIONAL`) is neither JSON nor PHP-serialized. The cart's list raises no notice: its selection is not migrated (section 2.4). | `key` |
| `cron_token_replaced` | One per token row that the migration replaced. | `key`, `id_shop_group`, `id_shop` |
| `cron_shop_assumed` | Multistore on, more than one shop, and a cron feature on at the default shop. | `id_shop` |
| `newsletter_block_removed` | The previous code was 1.x. | — |
| `emailsubscription_emails_changed` | A 2.x save had the opt-in on (section 2.4). | — |
| `override_left_in_place` | The override file is not the 2.x copy (section 2.4). | `path` |

**[D]** Every empty, `NULL` or missing legacy cron token row gets a new token and a
`cron_token_replaced` notice: an empty row at any scope, and the global row when no global row exists.

### 3.4 Continuity of URLs

| URL | 3.0.0 |
|---|---|
| `…?fc=module&module=smailyforprestashop&controller=SmailyCustomerCron&token=<customer token>` | A door to the tick (section 4.3). Answers `text/plain`. |
| `…controller=SmailyCartCron&token=<cart token>` | Same. |
| `…controller=tick&token=<SMAILY_CONNECT_TICK_TOKEN>` | New in 3.0.0. Same. The upgrade creates its token (section 3.7). |
| `…controller=SmailyRssFeed&limit=&sort_by=&sort_order=&category_id=` | The 2.x code (`Controller\RssFeedController`, `Model\RssFeedProductsCollection`), still wired. Story 3.3 kept it as it is and pinned its output and its parameters with `tests/Integration/Rss/RssFeedTest.php`. New parameters are additive only. |
| Friendly URL forms of the three | Unchanged: the controller names stay. |

**[D]** A request whose token is empty or wrong, or a door that has no stored token or an empty one,
is refused with HTTP 403 and the 2.x answer `Access denied!`. The comparison is `hash_equals()`. A
door only compares: a refused request writes nothing (`TickSettings::storedToken()`), so the two
cron URLs of a fresh install, which never had a token, stay closed. The upgrade gives an
empty legacy token a new random value and adds a notice. This closes the 2.x hole in section 0. It
changes the URL of the stores that it affects; those stores had an unauthenticated cron URL. The
notice names the one cron line of the Dashboard's Background tasks card (section 4.3) and says that
it replaces both old crontab lines; no screen lists the two legacy URLs.

**What the cron URLs do on `v3` today.** A 2.x crontab runs the tick, and the tick runs the jobs of
section 4.1. For a shop whose contact sync is active, this covers the work of the 2.x customer cron:
the reconcile takes Smaily's unsubscribes into the store, the daily refresh sends the mode's
audience to Smaily (section 6.5), and the queue flush sends the store changes that the capture hooks
queued (section 6.3). For a shop whose abandoned-cart automation is on, it covers the 2.x cart cron:
the sweep queues the due reminders, and the queue flush sends them (section 7). The upgrade guide
(Story 6.2) describes what the cron URLs do in 3.0.0.

### 3.5 Already-reminded state

**[D]** Every `smaily_cart` row that names a cart becomes a `smaily_connect_cart` row with
`status = mailed`, `mailed_at = date_sent` (section 2.2). A cart that has a legacy row is never
reminded again. A customer whose legacy reminder is younger than 24 hours is not reminded for another
cart in that time: the sweep's rule of one reminder per address per 24 hours counts a 2.x row by its
customer, because the row names no address (section 7, "The abandoned-cart sweep"). This keeps
"nobody gets a duplicate reminder because of the upgrade" (Magento UPGRADING "What is cleaned up").

The previous code can record one cart more than once. Its cart cron selects the carts that have no
`smaily_cart` row, one cart per customer, and writes a row after each reminder that Smaily accepted;
the table has no unique key on the cart, and nothing locks a run. Two cron requests that overlap can
therefore both select a cart before either records it, and both remind and record it (2.2.0
`Model\AbandonedCartCollection::getCarts()` and `Controller\AbandonedCartController::send()`; 1.6.2
`SmailyCartCron` the same way). The migrated row takes the latest of those send times, so the 24
hours count from the last reminder that 2.x sent.

### 3.6 Admin notice

**[D]** The migration writes its notices to `SMAILY_CONNECT_NOTICES` through `Config\MigrationNotices`
as codes with parameters (section 3.3); the admin owns the wording. As built in Story 3.1:

- `Admin\NoticeMessages` holds the sentence of each code. Every module admin page shows the notices
  in its shell (`Admin\BaseController`, `views/templates/admin/layout/page.html.twig`), each with a
  button that dismisses it for every admin (`Admin\Api\DismissNotice`).
- While the upgrade is not finished, the module pages show a banner with the button "Finish
  upgrade" (`Admin\Api\FinishUpgrade`, section 3.7) in place of the notices: the notices tell what
  the finished upgrade did.
- Every other back-office page shows one line with a link to the Dashboard while the upgrade is not
  finished, or while a notice is not dismissed (`Admin\BackOfficeNotice`, hook
  `displayBackOfficeHeader`, registered to run also before the upgrade), to an employee who may
  open the Dashboard.

### 3.7 Failure behaviour

**What PrestaShop does with a failing upgrade script** (observed on 8.2.8 and 9.2.0, the same code in
8.0.0; facts file 1.6):

- A `false` return still stores the **new** version and disables the module. PrestaShop never offers
  the upgrade again, because the stored version equals the version of the files.
- A thrown exception keeps the old version. A zip upload then disables the module; the CLI does not.
- Files copied by FTP are not upgraded until the admin clicks Upgrade in the Module Manager or runs
  `bin/console prestashop:module upgrade`.

So PrestaShop gives a failed upgrade no second run, and the module finishes the migration by itself.

**[D]** (orchestrator, 2026-11-20) The upgrade script never fails. As built in Story 1.7:

1. `upgrade_module_3_0_0()` calls only `Upgrade300::runPending()`, logs the error class of anything
   that escapes, and always returns `true`.
2. `runPending()` is self-sufficient: it also works when PrestaShop never ran the upgrade script
   (files copied by FTP). It returns `true` at once when the schema is current. Otherwise it takes
   `GET_LOCK('<db>_smaily_migration', 0)`; a second caller that cannot take the lock returns `false`
   at once, without work. Under the lock it reloads the configuration, checks the schema again, and
   runs the steps, unless the store has nothing to migrate (below).
3. The steps run in `Upgrade300::STEPS` order: `tables`, `hooks`, `menu`, `notices`, `settings`,
   `accounts`, `mappings`, `tokens`, `tick_token`, `carts`, `override`, `tab` (the 1.6.x hidden tab,
   section 2.4), `verify`, `finish`. `tables` (`CREATE TABLE IF NOT EXISTS`) and `hooks` run on every
   call. Every other step is skipped once it succeeded. `tick_token` creates the tick URL's token
   unless one is stored, so the Dashboard has the cron line of an upgraded store at once (section
   4.3); it changes no legacy token.
4. `verify` checks that every account row decrypts to its legacy password and that every setting holds
   one row in the table at its scope. `settings` writes a shop's row only while multistore is on,
   and stops otherwise: PrestaShop would write the global row in its place (section 2.1, item 5).
5. `finish` deletes the legacy keys (not the two tokens) at every scope and calls
   `Schema::markCurrent()` in one database transaction. A failure rolls both back.
6. A failed step stops the run. `SMAILY_CONNECT_MIGRATION` then records `status` `failed` and
   `failure` as the step name and the error class, never the error message (section 2.1). The legacy
   keys stay, so every run starts from the same input, and the runtime stays inert.
7. The scheduler job `migration` (interval 0, budget 10 s, runs before the upgrade) calls
   `runPending()` while the schema is not current. Every tick runs it: a cron URL, the tick URL and the
   fallback tick of page traffic, whose two listeners run before the upgrade (section 1.2).
8. The admin's "Finish upgrade" button (`Admin\Api\FinishUpgrade`, Story 3.1) calls `runPending()`
   too; section 3.6 describes the banner and the notices.

**[D]** A run never resets a store in use (2026-11-21). `runPending()` runs whenever the schema
version is missing, also on a store that is set up, so it is safe to run at any time:

- **Nothing to migrate.** A store whose migration completed (`status` `done`), or a store without a
  progress record for which PrestaShop records a 3.x version of the module and which holds no legacy
  key but the two cron tokens (3.0.0 installed it), has only lost its schema version. The run creates
  the missing tables, registers the hooks and stores the schema version again. It applies no plan and
  writes nothing else: no setting, mapping row, token, notice or progress record. A plan made on such
  a store would hold the settings of a new store, and applying it reset a store that had finished its
  Initial setup (observed on 9.2.0).
- **Where the plan is applied** (a first run, or the next run of an unfinished upgrade): `settings`
  writes a key only where its scope holds no row, and `mappings` adds a row only where the table
  holds none for that shop, trigger, language and account, so what a merchant saved while the upgrade
  was not finished stays, and `verify` does not compare a setting's value with the plan. `tokens`
  changes only an empty or missing token; `tick_token` creates a token only where none is stored;
  `carts` adds a row only for a cart that has none; `notices` stores a notice once per code and
  parameters; `tables`, `hooks`, `menu`, `override` and `tab` are idempotent. `accounts` is the
  exception: it saves the legacy account again, and `verify` insists on the legacy password before
  `finish` deletes its plain-text copy. The legacy keys exist only until the first `finish`, so no
  account of a store in use is written; an account that a merchant saves while the upgrade is not
  finished is replaced, or stops the run at `verify`.

**[D]** The schema-version guard. `Install\Schema::isCurrent()` compares the stored global
`SMAILY_CONNECT_SCHEMA_VERSION` with `Install\Schema::VERSION`, not with the module version.
`Schema::isCurrent()` and `Schema::markCurrent()` are the only code that reads and writes that key.
While the schema is not current, `Hook\Dispatcher` runs only the listeners registered to run before the
upgrade (section 1.2), and the tick runs only the jobs registered that way (section 4.3). The guard also
covers files copied over FTP before PrestaShop ran the upgrade (`woo:docs/LESSONS.md` §2.31: new code
on the old schema).

**Demonstrated** (Story 1.7 and the stage 1 integration pass, 2026-11-20):

- Genuine 2.2.0 and 1.6.2 stores, built from the git tags and loaded with the fixtures, upgraded to
  3.0.0 through the Module Manager upload and through the console on PrestaShop 8.0.0 and 9.2.0;
  multistore with own rows on 9.2.0 and multistore with one shared setup on 8.0.0.
- On 8.0.0, 8.2.8 and 9.2.0: a copy of the 3.0.0 files by FTP was migrated by a cron URL request,
  and a migration stopped by an injected failure was completed by a storefront request (the fallback
  tick).
- On 8.2.8 and 9.2.0 (2026-11-21): a genuine 2.2.0 store, multistore with own rows at shop 2 and
  two languages, was upgraded through the console and then set up (setup done, consent mode, an
  automation on, the layout answered). With its schema version removed, and again with its progress
  record removed too, a storefront request stored the version again and changed no other row.

- On 8.2.8 and 9.2.0 (2026-11-21): a genuine 2.2.0 store with multistore off and a second shop
  (`2.2.0-multistore-off-two-shops.sql`), with the 2.x abandoned cart on, was upgraded through the
  console behind the egress guard. The migration ended `done`, the global abandoned-cart switch
  stayed on, shop 2 got no row, the Dashboard opened, and a cart that was due got its reminder
  through the migrated account and workflow, against the Smaily mock.

**Limits:**

- An FTP copy over 2.2.0 on a store without a cron line stays inert until the admin clicks Upgrade:
  2.2.0 registered no page hook, so no fallback tick runs before the upgrade registers the 3.0.0 hooks.
  The admin's "Finish upgrade" button (section 3.6) runs the same migration.
- A zip upload unpacks over the old module folder (read from code, facts file part 6), so files that
  2.x had and 3.0.0 does not stay on disk. 3.0.0 does not load them.

### 3.8 Zip upgrade and marketplace upgrade

| Path | What PrestaShop does | 3.0.0 |
|---|---|---|
| Module Manager → Upload a module (zip of 3.0.0 over 2.x) | Unpacks the zip over the old folder and runs the upgrade scripts newer than the stored version. Observed on 8.2.8 and 9.2.0 (facts file 1.6). | Same migration. Demonstrated from 2.2.0 and 1.6.2 on 8.0.0 and 9.2.0 (3.7). |
| Module Manager → Upgrade (marketplace) | Same. | Same. |
| `bin/console prestashop:module upgrade smailyforprestashop` | Same, after the files are in place. | Same migration. Demonstrated on 8.0.0 and 9.2.0. |
| Files copied by FTP | Runs nothing by itself. The Module Manager lists "Upgrade available"; the upgrade runs when the admin clicks Upgrade or runs the CLI. | The runtime is inert until the migration completes; the next tick from a cron URL, or from page traffic where the old version registered a page hook, completes it (3.7). |
| The upgrade script returns `false` | Stores the new version, disables the module, and never offers the upgrade again. | Never happens: the script returns `true` (3.7). |
| The upgrade script throws | Keeps the old version; a zip upload disables the module, the CLI does not. | Never happens: the script catches every error (3.7). |
| Uninstall 2.x, install 3.0.0 | 2.x uninstall deletes all `SMAILY_*` keys and `smaily_cart`. | A new install. The user guide warns against this path ("Upgrading from version 2.x"). |

---

## 4. Scheduling without a platform scheduler

PrestaShop has no scheduler. Woo has Action Scheduler and Magento has cron; the module must bring
its own tick.

### 4.1 Jobs

A job is a `Scheduler\Job`: a name, an interval, a time budget per run, the work, and whether it runs
before the upgrade has finished. A provider registers it as a listener of `SchedulerProvider::JOBS`
(section 1.2). The tick runs the jobs in registration order, that is the order of
`Services::PROVIDERS` and, within a provider, the order of its calls. A job counts as due
`Job::GRACE_SECONDS` (5 s) before its interval has passed, so that a cron line that fires a little
early does not skip a whole interval.

Registered on `v3` today:

| Job | Registered by | Interval | Budget | Runs before the upgrade | Does |
|---|---|---|---|---|---|
| `queue_flush` | `SchedulerProvider` | 60 s | 20 s | no | Calls `Flusher::flush()` until it claims 0 rows or the budget ends. |
| `queue_janitor` | `SchedulerProvider` | daily (86 400 s) | 10 s | no | Section 11.3. |
| `contacts_reconcile` | `ContactReconcileProvider` | 15 min (900 s) | 15 s | no | `Contacts\Reconciler::run()`: Smaily's consent changes into the store (section 6.5). |
| `contacts_refresh` | `ContactReconcileProvider` | daily (86 400 s) | 5 s | no | `ContactImport::scheduleRefresh()`: queues the daily refresh as a job row (section 6.5). |
| `contacts_import` | `ContactReconcileProvider` | 60 s | 15 s | no | `ContactImport::run()`: sends pages of the first import and of the daily refresh (section 6.5). |
| `abandoned_cart` | `AutomationProvider`, through `Automation\AbandonedCart\AbandonedCartAutomation` | 5 min (300 s) | 10 s | no | `AbandonedCartSweeper::sweep()`: queues the due reminders, which the next queue flush sends (section 7). |
| `migration` | `MigrationProvider` | 0 (every tick) | 10 s | yes | Calls `Upgrade300::runPending()` while the schema is not current (section 3.7). |
| `update_check` | `UpdateProvider` | daily (86 400 s) | 10 s | no | Asks GitHub for the latest release (section 11.5). |

The tick therefore runs them in this order: `queue_flush`, `queue_janitor`, `contacts_reconcile`,
`contacts_refresh`, `contacts_import`, `abandoned_cart`, `migration`, `update_check`. Two integration
tests pin this full list (section 12).

Planned (the Story that builds each registers it):

| Job | Interval | Time budget per run | Sibling | Story |
|---|---|---|---|---|
| Ingest queue flush | 1 min | 25 s | `FlushIngestQueue` | 5.2 |
| Import chunk (catalog, history) | 1 min | 15 s | `BackfillTick` | 5.2, 5.3 |
| Health check | 15 min | 5 s | `HealthCheck` | 5.1 |
| Catalog manifest (§3c) | daily, 03:30 shop time | as import chunks | `SendCatalogManifest` | 5.2 |
| Installation report | due check every tick | 5 s | `SendInstallationReport` | 6.1 |

### 4.2 Options

| Option | For | Against |
|---|---|---|
| A. Token-protected cron URL as the single tick | Already in merchants' crontabs; precise; runs outside shopper requests. | Many merchants never set a crontab; 2.x merchants may call it daily, not every minute. |
| B. Traffic-triggered fallback tick | Works with no setup. | Runs only when visitors come; must not slow a page; needs locks. |
| C. `ps_cronjobs` module (`actionCronJob`) | Some stores have it. | Its finest frequency is hourly (verified in the sources of `ps_cronjobs` 1.4.0, facts file 1.7); it still needs a crontab or a web cron. |
| D. Hosted pinger (a Smaily service calls the tick URL) | No merchant setup. | Needs Smaily infrastructure and the store's tick token; not in any agreed scope. |

### 4.3 Design

**[D]** (decision 1, owner 2026-11-20) A + B, with A as the primary tick. As built in Story 1.6:

1. **One tick, many doors.** `Scheduler\Door` serves three URLs, each behind its own token: the new
   `controller=tick&token=<SMAILY_CONNECT_TICK_TOKEN>` and the two legacy cron URLs (section 3.4).
   Every door calls `Scheduler\Tick::run($door, $budget)` with the budget
   `min(50, max_execution_time − 5)` seconds (50 s when there is no limit, at least 1 s). The three
   front controllers share `Scheduler\DoorFrontController`; each names only its door. Each door
   answers `text/plain` with one of these lines:
   - HTTP 403 `Access denied!` (the 2.x answer) for an empty or wrong token, or a door with no
     stored token or an empty one; the door reads the stored token and never creates one;
   - `OK: ran <job>, <job>.` or `OK: no job was due.`;
   - `Busy: another tick is running.`;
   - `Waiting: the upgrade of the module has not finished.` while the schema is still not current
     when the tick ends.

   The tick reads the schema state again after its jobs ran. A tick whose `migration` job completes
   the upgrade therefore answers `OK: ran migration.`; the other jobs wait for the next tick.
   `TickSettings::newToken()` (32 hex characters) is the one generator of the door tokens.
   `TickSettings::token()` creates a missing token; only the upgrade's `tick_token` step and the
   Dashboard's "Show the cron line" call it, for the tick URL.
2. **The tick.** `Tick::run()` takes the lock, writes the tick time and source to the tick state, and
   runs each due job in order. Each job gets its own budget, cut to what is left of the tick's; a job
   that cannot start before the tick's budget ends waits for the next tick. The tick records a job as
   run **before** the job starts, so a job that kills the request waits its interval like any other. A
   job that throws fails alone; the tick logs its name, error class, file name and line. While the
   schema is not current, only the jobs registered to run before the upgrade run.
3. **Traffic fallback** (`Scheduler\TrafficTick`). Listeners of `actionFrontControllerSetMedia` and
   `actionAdminControllerSetMedia`, registered to run also before the upgrade, read
   `SMAILY_CONNECT_TICK_STATE` from the configuration that the request loaded (no query). When the
   last tick is 60 s old or older, the listener registers a shutdown function. At shutdown it writes
   PrestaShop's cookie and calls `fastcgi_finish_request()` (or `litespeed_finish_request()`), so the
   response is already sent, and runs the tick with a 10 s budget. The cookie comes first because
   PrestaShop writes the session of a login only when the request ends, after the shutdown functions
   (facts file 6.8); the contact capture's flush sends its response the same way
   (`TrafficTick::finishFunction()`). Under the lock the tick reads the stored state again and does
   nothing when another tick ran in the last 60 s. Where neither finish function exists, nothing
   runs, and the Dashboard says that a cron line is needed. Verified under php-fpm on 8.2.8 and
   9.2.0: the client receives the whole page before the shutdown work ends, and `Db` still answers
   after `fastcgi_finish_request()` (facts file 3.2). `litespeed_finish_request()` is not verified.
   Measured in Story 1.6: a page's time to last byte does not grow when the tick is stale (9.2.0:
   0.054 s fresh, 0.049 s stale; 8.2.8: 0.132 s fresh, 0.099 s stale). A second tick started during
   a tick answers `Busy` in 0.03–0.13 s.
4. **Enqueue flush** (`Contacts\Capture\ContactEvents`, built in Story 2.3). A request whose
   capture wrote at least one `contact.sync` row flushes one batch of the event queue
   (`Flusher::flush()`, at most 200 rows) at its own shutdown, whatever the 60 s gate says: it
   writes the rows, sends the response through `TrafficTick::finishFunction()`, releases the PHP
   session, takes the tick's lock and flushes. The batch is the queue's oldest due rows of every
   event type, so an `automation.welcome` row that the same request queued goes with it unless
   older rows fill the batch. 2.x sent the opt-in inside the request; this keeps the welcome email
   about as fast without making the shopper wait. The
   flush does not run while a tick holds the lock, and nothing is flushed where no finish function
   exists: those rows go with the next tick. A request that queued only an automation row (a first
   order in a shop without contact sync) waits for the next tick too.
5. **Concurrency.** The tick takes `GET_LOCK('<db>_smaily_tick', 0)` (`Scheduler\Lock`; the name is
   the shop's database name and the purpose, within 64 characters). A second tick that cannot take it
   returns at once (verified through `Db` on MariaDB, facts file 3.3). The migration takes a lock of
   its own name (section 3.7). `SKIP LOCKED` needs MySQL 8 or MariaDB 10.6, and PrestaShop 8 supports
   older servers, so `Queue\EventQueue::claim()` claims rows in three statements:
   1. It reads the ids of due `pending` rows, oldest first, without a lock.
   2. It runs `UPDATE … FORCE INDEX (PRIMARY) SET status = 'processing', claim_token = <new token>
      WHERE id IN (…) AND status = 'pending'` (the due condition repeated).
   3. It reads back the rows that carry its token.

   The update that returns stale claims (`requeueStale()`) has the same form and the same
   `FORCE INDEX (PRIMARY)`. **Reason:** when fewer rows are due than ids are named, the optimizer
   walked the status index instead of the primary key. That scan locked index entries of rows that
   the claim never named, also entries that another flush was changing while it closed its own rows,
   so two flushes could lock in opposite orders. With the primary key forced, every locking statement
   of the queue reaches only the rows it names, in ascending id order. **Evidence:** the deadlock that
   `ConcurrentFlushTest` showed once in 21 runs was not reproduced before the fix (0 in about 410
   runs). The cause was found by analysis and by a partial replay of the SQL. After the fix the test
   passed 110 of 110 runs on 8.2.8 and 55 of 55 on 9.2.0, on MariaDB. MySQL is not tested.

   The second of two concurrent flushes can claim fewer rows than are due, even 0. An outcome is
   recorded only on a row that still carries the token it was claimed with. A claim older than 10 min
   returns to `pending` (the process died). **[D]** SQL on the queue tables names its rows by primary
   key; no statement locks rows through a secondary index. A read through a secondary index takes no
   lock: the claim's read of due ids, and the read of a contact's later rows (`Log\ResendGuard`,
   `Contacts\StaleSubscription`, section 6.4), which is a plain `SELECT` through
   (`entity_id`, `event_type`, `status`) and changes nothing.
6. **One poison row never owns the pass** (`woo:docs/LESSONS.md` §2.18). `Queue\Flusher` hands the
   claimed rows of one event type to that type's handler as one batch. When the handler throws for
   the batch, the flusher hands each row of that batch to the handler again alone, so only the row
   that throws fails. A handler therefore reports a failure of the wire (an HTTP error, a refusal) as
   that row's outcome, and throws only on a fault of its own.
7. **Drain.** The job `queue_flush` calls `Flusher::flush()` in a loop until it returns 0 or the job's
   deadline passes. One call claims at most `Flusher::BATCH_SIZE` (200) rows.
8. C and D are not built in 3.0.0. D stays a possible later addition (section 14).

**The tick state** (`SMAILY_CONNECT_TICK_STATE`, global, `Scheduler\TickState`) is JSON with Unix
times:

    {"last": {"at": 1763640000, "source": "tick"},
     "sources": {"tick": 1763640000, "customer_cron": 1763639000, "traffic": 1763638000},
     "jobs": {"queue_flush": 1763640000, "queue_janitor": 1763600000}}

A source is a door (`tick`, `customer_cron`, `cart_cron`) or `traffic`. An absent or unreadable value
holds no tick.

**Using the event queue** (Story 1.5, for later Stories):

- **Enqueue.** `EventQueue::enqueue(string $eventType, int $idShop, array $payload, ?string $entityId
  = null, ?string $language = null): int` stores a `pending` row (the payload as JSON) and returns its
  id. The service id is `QueueProvider::EVENT_QUEUE`.
- **Change a row's payload.** `EventQueue::updatePayload(array $row, array $payload): bool`
  replaces the stored payload of a claimed row and changes nothing else of it. A handler uses it to
  keep what it made for the row before the send (a discount code, section 7), so that a retry and
  the Log's "Send again" send the same. It writes by primary key and only for the claim the row was
  read with, as every outcome does: it answers `false`, and writes nothing, when the claim went
  stale and another flush took the row. The handler then stops without a send.
- **Handle.** A Story registers the handler of its event type in its own provider:
  `$services->set(QueueProvider::handlerId('<type>'), fn (Services $s) => new MyHandler(…))`. The
  handler implements `Queue\HandlerInterface::handle(array $events): array` and returns one outcome
  per row id: `true` (sent), a `Skipped` (closed with a reason), a `Pending` (given back, no attempt
  spent), a `Failure`, or a `\Throwable` (read by `Failure::of()`). A row without an outcome fails and
  takes the retry ladder. A row whose event type no handler takes fails permanently.
- **Classify.** `Failure::of($error)` reads the verdict that the Smaily client's error type carries:
  a `RequestRefusedException` (a 4xx other than 429) and an `ApiException` whose `isPermanent()` is
  true (Smaily's code 203) park at once. The rule "which Smaily answers are permanent" lives only in
  the client's error types (`Smaily\Exception\*`); the queue keeps no copy of it. `Failure::http()`
  does the same for a status that a handler reads itself. Any other failure takes the ladder of
  60 s, 5 min, 15 min and 1 h and parks after 5 attempts (`Queue\RetryPolicy`). A 429 waits the
  delay it asked for, at most 6 h.

**Dashboard.** A "Background tasks" card shows the last tick time and whether it came from the cron
URL or from shop visits. Its state comes from the tick state alone (`Admin\Dashboard\BackgroundTasks`):
green ("Running") when a cron URL ran a tick within 5 min; red ("Not running", "Background tasks are
not running; add the cron line.") when no tick ran for 15 min or none ever ran; amber ("Visitors
only", "Tasks run only when visitors come; add the cron line.") in between, when ticks run but only
from shop visits or from a cron URL that is not called every minute. In every state, green included,
an admin who may change the settings gets the button "Show the cron line", which asks
`Admin\Dashboard\CronLine` for the store's one cron line,
`* * * * * curl -fsS "<tick URL>" > /dev/null`; the page itself never carries the token, and the
line is not shown before the button is pressed. The line's address carries no language prefix
(`/module/smailyforprestashop/tick?token=…` with friendly URLs), so it is the same line for every
employee of a store with several languages; the URL answers with a prefix and without one. An employee without that right is told to ask an
administrator. The card lists only the tick URL: the two 2.x cron URLs keep working (section 3.4),
and the one line replaces both in a crontab. The Initial setup's Overview step shows one line,
"Background tasks last ran", with the time.

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
| Separate stores | Every section at the shop's scope; no global values. | One row set per shop. | One connection per shop (decision 7): each shop connects on its own with its own setup link. |

Shop groups carry no settings of their own (section 2.1). A shop group matters only for customer
sharing (5.3). The separate-stores layout is in the first release (owner, 2026-11-20).

**[D]** A configured store changes its layout in Settings (`Admin\Settings\LayoutChange`, Magento's
`StoreLayout::toSeparateStores()` and `toOneStore()`). The values that move are the three sections a
shop can hold as its own (`Admin\Settings\ShopOverrides`): the Smaily account (the language mode, the
fallback language, the account rows), the Contacts settings and the automations (their keys and the
mapping rows).

- **One store → separate stores.** Each shop gets, as its own, every shared value that it lacks; an
  account row is copied as stored, the password still encrypted. A shop on another Smaily account
  than the shared one gets no shared mapping row: it never read them (Magento CON-190). The shared
  values are then removed, so a shop added later starts with nothing set. Every shop reads what it
  read before.
- **Separate stores → one store** (owner, 2026-11-20: as Magento does). The values of the selected
  shop (the default shop with "All shops" selected) become the shared ones. An account per language
  is never shared: the shared mode `a` becomes `single`, and the shared account is the selected
  shop's account of no language. Then every shop's own values of the three sections are removed,
  whether or not they differ from the shared ones: its keys, all its account rows and its mapping
  rows. The page asks first, with Magento's text.
- **The mapping rows** move as Magento's `copyMappingRows()` moves them (owner, 2026-11-20). To
  separate stores, a shop gets each shared row that it holds no row for (same trigger, language and
  account key); a row it holds stays. To one store, the selected shop's rows of the account
  `default` join the shared rows that an upgrade can have left: its row wins over a shared row of
  the same key, and a shared row that it holds no row for stays. A row of a language's account is
  never shared, as that account is not. In both directions a copied row is a trigger's default
  fallback only where the target holds none of that kind (the rows of the account `default`, or the
  rows of the languages' accounts); to one store, a copied default fallback takes that place over.
  A shop's row of "no workflow" (section 10.9) is never copied: to one store, the promoted shop's
  "no workflow" removes the shared row of that key.
- **The contacts confirmation** (`SMAILY_CONNECT_CONTACTS_CONFIRMED`) is not an own value. Neither
  direction copies or removes it, as in Magento, so a shop that synced keeps syncing.
- Removing a value deletes its row through `Config\Settings`; no step writes `NULL` (section 2.1).

**[D]** The language axis follows Woo's multilingual modes (`woo:includes/Multilingual/Router.php`):
`single`; `a` one Smaily account per language with a fallback account; `b` one account, one workflow
per language; `c` one workflow that branches on the contact's `language` field. As in Magento, mode `a`
is always a shop's own setting (owner decision 2026-10-22 in Magento), and a store-wide save on a
multishop install does not offer it.

**[D]** A change of the language mode, by direction (`Admin\Setup\ConnectionPanel`,
`views/js/admin/panels.js`). As in Magento, mode `a` stores the fallback account also as the account
of no language, the accounts and the workflow rows of a mode that is left stay stored for a switch
back, and the Router and the pages read only the rows of the saved mode (section 5.2).

| Change | Accounts | Workflow rows | The page asks first |
|---|---|---|---|
| `single` ↔ `c`; `single` or `c` → `b` | unchanged | unchanged; `b` adds the per-language rows of the one account | no: nothing is dropped or replaced |
| `b` → `single` or `c` | unchanged | the per-language rows stop being read | yes, Magento's question |
| `single`, `b` or `c` → `a` | the fallback account takes the place of the account of no language | where the fallback account is another Smaily account, the rows of the account `default` are removed | yes: the question names the replaced account and its workflows (Magento asks only where `b` is left) |
| `a` → `single`, `b` or `c` | the account of no language stays the fallback account; the languages' accounts stop being read | the rows of the languages' accounts stop being read; no row of the account `default` survives from another account | yes, Magento's question |

**[D]** A workflow belongs to the Smaily account it was picked from, so no automation is posted to an
account with another account's workflow id. Where a Connection save puts another Smaily account
(another subdomain; another API user of the same subdomain lists the same workflows) in the place
of a stored account, the rows of that account's key that hold a workflow are removed, at the saved
scope and at every shop that sends that key through the scope's account
(`MappingRepository::removeWorkflows()`). The affected automations then send nothing: the Router
skips them as `no_workflow`, which the Log words, until the merchant picks a workflow of the new
account. The 2.x opt-in row and a shop's rows of "no workflow" name no workflow and stay. A save
that changes the mode asks first (the table above); a save that replaces an account in the same
mode says afterwards that the workflows of the replaced account were removed. Magento keeps such a
workflow id and posts it to the new account, where Smaily refuses it (a cross-repo observation,
not mirrored). The table has no column for the account a row was picked from, so the rows cannot
wait for the first account to come back: typed again, it needs its workflows picked again.

### 5.2 Resolution rules

| Question | Rule |
|---|---|
| Contact language | `customer.id_lang`; for an `emailsubscription` row its `id_lang`; for an order or cart its `id_lang`. Mapped to the two-letter ISO code of that language. |
| Contact shop | `customer.id_shop`; `emailsubscription.id_shop`; `orders.id_shop`; `cart.id_shop`. |
| Account for a contact | `Scope\AccountResolver::for(id_shop, language)` reads the shop's own rows first, then the global rows (`id_shop` 0) only in the one-store layout; a shop of separate stores never reads `id_shop` 0 or another shop's rows. Within those rows, mode `a` takes the row of the contact's language, else the row of the fallback language, else the `''` row; every other mode takes the `''` row only. An account whose password does not decrypt is still returned (section 2.3). |
| Scope of an event | `Scope\ScopeResolver::forRecord($record)` reads `id_shop` and `id_lang` of a Customer, Order or Cart object or a row of their tables or of `emailsubscription`; `resolve($idShop, $idLang)` takes them directly. It never reads the context of the request that processes the event later, such as a cron. The answer is a `Scope\Scope`: the shop, the language's two-letter ISO code (`''` when none), the account, and `setupShopId()`. |
| Setup shop of an event | `Scope::setupShopId()` is the shop itself in separate stores, else 0. It names the shop whose setup serves the event, which is the `id_shop` of the Campaign Intelligence connection that serves the event (0 = the installation-wide connection). |
| Customer account scope | `StoreLayout::customerAccountScope()`: `global` when every shop is in one shop group that shares customers, else `website` (section 5.3). |
| Workflow for (trigger, shop, language) | `Automation\Router`, the Magento rules: at each step the shop's own mapping rows first, then the `id_shop = 0` rows; mode `a` exact (trigger, language) row, else the `is_default_fallback` row, else the (trigger, fallback language) row; mode `b` exact row, else the `is_default_fallback` row, else the default row; `single`/`c` the default row (the row of no language). Only the rows of the mode's accounts are read. A shop's own row hides the shared row of the same language and account key, also where it says "no workflow" (`MappingRepository::setNone()`, Magento's stored `0` at a website's scope). No match is a terminal skip with a reason. As built, mode `a` also ends at the default row, which Magento's mode A never starts (section 14). The matched row's `account_key` travels with the workflow, and the handler posts through that account. A shop on another account than the global one reads only its own rows (Magento CON-190). |

**[D]** A single-shop install keeps its mode-`a` account rows at `id_shop` 0: the Initial setup and
Settings write a single shop's values at the global scope (`Admin\Setup\SetupScope::targetShop()`).
`AccountResolver` reads both scopes.

**[D]** A panel reads the scope it writes (`SetupScope::targetShop()`). In one store the Initial
setup shows and saves the values all shops share, whichever shop PrestaShop's selector names, as
Magento's wizard sets the whole store up (CON-179); its first step says so. A shop's own values are
read and saved under Settings only (`Admin\Settings\SettingsScope`). The setup once read the
selected shop and wrote the shared scope: Continue on the Contacts step then saved a shop's own
"All customers" as the mode of every shop. Magento's wizard also lists the websites that hold own
values and offers to return them to the shared ones; that list is not built.

**Wanted later:** `AccountStore::languages(int $idShop)`, the languages that hold an own account in a
shop, for parity with Magento CON-190 (the admin lists them).

### 5.3 Campaign Intelligence scope

- The contract has one engine store per Smaily account, lets one engine store serve several
  "websites" (contract §1 `store_config`, §3 `websites`, v1.21.0), and since v1.33.0 lets several
  connections join one engine store, each with its own connection prefix (contract §1 "Connection
  prefix").
- **[D]** (decision 7, owner 2026-11-20) Both layouts are in the first release. One store: one
  installation-wide connection that serves every shop. Separate stores: one connection per shop; each
  shop pastes its own setup link. The connection table is keyed by `id_shop` (0 = installation-wide),
  and every ingest row carries `id_connection`. Two separate-store shops on one Smaily account hold two
  connections to the same engine store.
- `store_config.customer_account_scope`: `global` when every shop is in one shop group that shares
  customers (`shop_group.share_customer = 1`), else `website`.
- PrestaShop product, order and customer ids are unique across all shops of one installation, so two
  connections of one installation never write the same key. The engine still scopes the nightly
  manifest (§3c), the removals (§3b) and the history progress (§25) by the connection prefix. While the
  engine assigns no prefixes (contract §1 "Status", 2026-11-17: every connection gets `""`), two
  unprefixed connections in one engine store each send a manifest that claims the whole store catalog,
  and each would remove the other's products. **[D]** The module therefore sends no nightly manifest
  for a connection whose `tenant_id` another connection of the installation shares while either holds
  the prefix `""`; the Dashboard says so. Live §3 rows and §3b removals still flow. Lifting this needs
  the engine team's answer (section 14).

### 5.4 What the upgrade infers

Section 3.3: language mode `single`; shop layout from the account rows; every 2.x setting at the
scope where its effective value differs. With multistore off: the global scope alone.

---

## 6. Consent mode on PrestaShop

### 6.1 Where opt-in lives

| Source | Meaning in 3.0.0 |
|---|---|
| `customer.newsletter` (+ `newsletter_date_add`, `ip_registration_newsletter`) | The marketing consent of a customer or a guest-checkout customer (`is_guest = 1`). |
| `customer.optin` | PrestaShop's own "partner offers" checkbox. Not marketing consent. The module neither creates nor reads it (2.x did not read it either). |
| `emailsubscription` (`ps_emailsubscription`): `email`, `id_shop`, `id_lang`, `active` | The consent of a newsletter-block subscriber who has no account. |
| Newsletter checkbox on registration and checkout | Added by `ps_emailsubscription` to the customer form; it writes `customer.newsletter`. Verified for the guest checkout form on 8.2.8 and 9.2.0 (facts file 1.8). |
| psgdpr | Export and erasure hooks (section 11). It holds no marketing consent. |

**[D]** Marketing consent lives in PrestaShop's own places: `customer.newsletter` and
`emailsubscription`. The module keeps no consent store of its own; it reads and writes those places.
(The `smaily_connect_privacy` table of section 2.2 records only a shopper's profiling opt-out, section
11.2.)

**[D]** Where `ps_emailsubscription` is not active, "Show a newsletter checkbox at checkout" (Contacts
setting, default on for new installs, off for upgrades) adds the box through
`additionalCustomerFormFields` and writes `customer.newsletter`. Where `ps_emailsubscription` is
active, the module adds no box. This is a visible difference from Magento and Woo, which add their own
checkout box: PrestaShop's box already exists in most stores, and two boxes confuse shoppers.

### 6.2 What each mode syncs

The modes are Woo's presets (`woo:docs/CONTACT_SYNC_MODES.md` §2), with Smaily as the consent
authority and `force_opt_in = false` on every automation trigger.

"Guest-checkout customer" below is a `customer` row with `is_guest = 1`. "Newsletter-block
subscriber" is an `emailsubscription` row, a subscriber without an account. "Include guests"
(`SMAILY_CONNECT_CONTACTS_GUESTS`) covers both; upgraded stores have it on.

| | Subscribers only (default; upgrades) | All customers | Checkout opt-in only |
|---|---|---|---|
| Live sync of a registered customer | At opt-in; at opt-out with `is_unsubscribed = 1`. | Every store event (register, profile change, order), whatever the consent. | Only a checkout in which the box was ticked. |
| Live sync of a guest-checkout customer | As a registered customer, and only when "include guests" is on (decision of 2026-11-20, WooCommerce's rule). | As a registered customer, and only when "include guests" is on (decision of 2026-11-20). | As a registered customer: a ticked box is sent whatever "include guests" says. |
| Newsletter-block subscribers | When "include guests" is on: a subscribe, and an unsubscribe with `is_unsubscribed = 1`. | When "include guests" is on: a subscribe only. | Never. |
| First import and daily refresh | Registered customers with `newsletter = 1`; guest-checkout customers with `newsletter = 1` and active `emailsubscription` rows when "include guests" is on. | Every registered customer; guest-checkout customers and active `emailsubscription` rows when "include guests" is on. | None. |
| Reconcile from Smaily | Both directions, with the first-run rule below: an unsubscribe sets `newsletter = 0` and `emailsubscription.active = 0` (as 2.x); a re-subscribe sets `newsletter = 1`. | None. | None. |
| `is_unsubscribed` on upsert | Sent only when consent changed in the store. | Never. | `0` with the tick. |
| Abandoned-cart reminder goes out when | The contact has consent: `newsletter = 1`, an active `emailsubscription` row, the checkout box ticked, or Smaily holds the contact as subscribed; and the Smaily status check before the send finds the contact not unsubscribed (decision 3, the siblings' rule; upgrades too). | Smaily does not hold the contact as unsubscribed. | The box was ticked, or Smaily holds the contact as subscribed. |

A customer who is soft-deleted (`customer.deleted = 1`) is never imported.

**[D]** (owner, Erkki, 2026-11-20) The abandoned-cart reminder applies no separate guest rule, as in
the sibling connectors. "Include guests" gates the contact sync only. Who is reminded follows the
consent mode through `ContactAudience::sendsAutomatedMarketing()`, the same for a registered
customer, a guest-checkout customer and an address typed at checkout.

**[D]** (owner, Erkki, 2026-11-20) The reconcile is two-way in subscribers-only mode. An unsubscribe
in Smaily sets `newsletter = 0` and `emailsubscription.active = 0`, as 2.x did. A re-subscribe in
Smaily is written back to the store: `newsletter` 0 → 1, as WooCommerce preset 2 does. It does not
set an inactive `emailsubscription` row active again: such a row can wait for the shopper's email
verification. The store mirrors Smaily; the Smaily audience does not grow.

**[D]** (owner, Erkki, 2026-11-20; the list-based form is the orchestrator's technical refinement)
The first reconcile of each Smaily account takes only unsubscribes. The owner's rule is "an upgrade
never marks anyone subscribed again". **Reason:** 2.x never sent a newsletter-block unsubscribe to
Smaily, so an opt-in in Smaily's action log can belong to a shopper who has unsubscribed in the store
since. The first run therefore:

1. reads the action log (`history.php`) to its end without applying any row, only to set the
   account's cursor at the log's end;
2. then reads Smaily's current unsubscriber list (`contact.php`, `list=2`, as 2.x did) and sets those
   addresses unsubscribed in the store;
3. marks nobody subscribed.

The log's end is read before the list. A change that Smaily logs after that end is replayed by the
next run, in both directions; an unsubscribe logged before that end is on the list, which is read
after it. So the first run never unsubscribes a contact that Smaily holds as subscribed now, and no
change falls between the two reads. From the second run on, the reconcile is two-way.

**[D]** (owner, Erkki, 2026-11-20) In all customers, a guest-checkout customer is sent only when
"include guests" is on. In subscribers only, a guest-checkout customer who ticked the box is sent only
when "include guests" is on (WooCommerce's rule). Checkout opt-in only sends a ticked box whatever
"include guests" says (this already matched WooCommerce). A registered customer is never gated by
"include guests". The capture sets the payload's `guest` field for a guest-checkout customer
(`Contacts\Capture\CustomerCapture`, section 6.4), so the live sync and the imports apply the same
gates.

**[D]** "Status sent only on change": a `contact.sync` row carries `is_unsubscribed` only when the
listener saw the consent value change. Automations and imports never send `is_unsubscribed = 0`.
Reconcile writes go straight to the tables with `Db`, so they fire no hook and enqueue nothing back to
Smaily.

### 6.3 Capture hooks

| Hook | Captures |
|---|---|
| `actionCustomerAccountAdd` | New customer or guest, its `newsletter` value, and whether the form was the checkout (`Context::getContext()->controller` is `order`). |
| `actionObjectCustomerUpdateBefore` / `…UpdateAfter` | The stored `newsletter` value before the save (read from the database), compared after the save. Covers front-office account edits, the admin form and the admin grid toggle (verified, facts file 1.9). On 8.2.8 the admin customer form has no newsletter field, so the grid toggle is the only admin control of the consent; on 9.2.0 the form has one. |
| `actionCustomerAccountUpdate` | Front-office account edits; deduplicated with the row above by (address, consent) within one request. |
| `actionNewsletterRegistrationBefore` | Newsletter-block unsubscribe (`action = '1'`). The hook fires on every submit of the block, before the block validates it, so the listener reads the stored state after the block wrote it (for example at shutdown) and enqueues only a real change. |
| `actionNewsletterRegistrationAfter` | Newsletter-block subscribe only (`action = '0'`), after the block stored it. With the verification email on (`NW_VERIFICATION_EMAIL = 1`), it fires later, from the verification link (`action = 0` as an integer), and not at the submit. |
| `actionValidateOrder` | Profile and consent at order time (all-customers mode), checkout opt-in. |

What the newsletter block does (`ps_emailsubscription` 2.8.2 on 8.2.8 and 3.0.0 on 9.2.0; facts file
1.10):

- `actionNewsletterRegistrationAfter` never fires on an unsubscribe, on an error (for example an
  address that is already subscribed), or at a subscribe while the verification email is on.
- Only `actionNewsletterRegistrationBefore` fires on an unsubscribe.
- The block changes `customer.newsletter` with raw SQL, so a customer who subscribes or unsubscribes in
  the block fires no customer hook.
- A guest's unsubscribe deletes the guest's `emailsubscription` row.

**[D]** Capture for Stories 2.3 and 4.2: a block subscribe comes from
`actionNewsletterRegistrationAfter`, and a block unsubscribe from `actionNewsletterRegistrationBefore`
with `action = '1'`, confirmed against the stored state. A guest's unsubscribe finds no row, which
confirms it. The welcome automation on a block subscribe follows `actionNewsletterRegistrationAfter`,
so with the verification email on it starts from the verification link. The reconcile from Smaily
(6.2) heals a change that no hook caught.

**As built** (Story 2.3, `Contacts\Capture\`; `ContactsProvider` registers the listeners):

- `CustomerCapture` records the events of a customer row: a new account, a save of the customer and
  an order. `NewsletterBlockCapture` records the block's subscribes and unsubscribes as above. An
  event belongs to the record's shop and language, never to the request's context; a soft-deleted
  customer is not a contact.
- `ContactEvents` holds the events of one request and writes them as `contact.sync` rows when the
  request ends: one row per shop and address, which keeps a consent change or a checkout that any
  of its events saw. A shop whose contact sync is not active gets no row. A listener of the events
  (the welcome automation, section 7) gets each merged event, that shop's too. Then the enqueue
  flush of section 4.3 runs.
- `CheckoutBox` adds the module's own newsletter box (section 6.1) through
  `additionalCustomerFormFields`, where `ps_emailsubscription` is not active and the setting is on.

### 6.4 Contact sync (as built in Story 2.2)

**Policy.** `Contacts\ContactSyncMode` holds a mode and "include guests". Its stored values are
`subscribers`, `all_customers` and `checkout_optin`; an unknown value reads as `subscribers`, so a bad
value never widens the audience. `Contacts\ContactAudience` answers every audience question of the
table in 6.2: the live sync (`syncs()`), the status a row carries (`unsubscribedStatus()`), the imports
(`importsCustomer()`, `importsNewsletterGuests()`) and the abandoned-cart reminder
(`sendsAutomatedMarketing()`, which applies no guest rule).
The live sync, the imports and the reminder ask this one object, so they never disagree.

**Active only when confirmed.** `ContactsSettings::syncActive($idShop)` is true only when
`SMAILY_CONNECT_CONTACTS_ENABLED` and `SMAILY_CONNECT_CONTACTS_CONFIRMED` both read `1` for the shop
(its own row, else the global row). A store with nothing stored syncs nothing.

**The `contact.sync` event.** The capture (section 6.3) enqueues it with
`EventQueue::enqueue('contact.sync', $idShop, $payload, …)`. The payload records what the store saw;
the handler reads the mode, the fields and the account when it sends the row:

    {"email": "<address>", "source": "customer" | "newsletter_block", "subscribed": true,
     "consent_changed": false, "checkout": false, "guest": false, "id_lang": 1,
     "customer": {"firstname": "…", "lastname": "…", "birthday": "…", "website": "…"}}

| Field | Required | Meaning |
|---|---|---|
| `email` | yes | The contact's address (not empty). |
| `source` | yes | `customer` for a `customer` row (a guest-checkout customer included); `newsletter_block` for an `emailsubscription` row. |
| `subscribed` | yes | The contact's consent in the store after the event. |
| `consent_changed` | yes | Whether the store saw this event change that consent. |
| `checkout` | no, default `false` | Whether the event is a checkout: a registration or a guest at the order step, or an order. |
| `guest` | no, default `false` | `true` for a guest-checkout customer (`is_guest = 1`). |
| `id_lang` | no, default `0` | The contact's language id; the handler resolves the shop, the language code and the account from it (section 5.2). |
| `customer` | no, default `{}` | The `customer` columns that the selected fields read; empty for a newsletter-block subscriber. |

A payload that does not fit this shape fails permanently with `malformed_payload`.

**What Smaily receives** (`Contacts\SubscriberPayloadBuilder`, Smaily's `contact.php`): `email`
(trimmed, lower case); `store`, the domain of the contact's shop as 2.x sent it; `language`, the ISO
code of the contact's language; the selected fields of `first_name`, `last_name`, `birthday` and
`website`; and `is_unsubscribed` (`1` or `0`) only when `unsubscribedStatus()` gives a status, that is
on a consent change. An empty value is left out, so Smaily keeps the field it has. A birthday that was
never entered (`0000-00-00`) counts as empty.

**Sending** (`Queue\Handler\ContactSyncHandler`). The rows of one flush batch go in one request per
Smaily account. A row that cannot go ends `skipped` with one of these reason codes, which never name
the contact:

| Reason code | When |
|---|---|
| `contact_sync_off` | The shop's contact sync is not active. |
| `not_in_audience` | The contact is outside the shop's audience (`syncs()` is false). |
| `stale_subscription` | The row would put a stale subscription into Smaily (below). |
| `no_account` | No account serves the row's shop and language. |
| `no_credentials` | The account's password does not decrypt or is empty. |

Smaily answers one code for a whole request. When it refuses a group of several contacts as invalid
data (code 203), the handler sends each contact of the group again in a request of its own, so only
the refused contact fails (Magento PRO-3753). Any other failure of the wire is the outcome of every row
of the group, and `Queue\Failure::of()` classifies it (section 4.3).

**A stale subscription is never sent** (`Contacts\StaleSubscription`). A row carries the consent
that the store saw when the row was queued, and rows do not always go in the order they were queued:
a row that failed waits on the retry ladder while a later row of the same contact is delivered.
Example: the contact subscribes and the row fails; the contact unsubscribes and that row is delivered
at once; the first row's retry would then mark the contact subscribed again. So the handler asks, at
every attempt and before anything is sent, which rows of its batch are stale, and closes those as
`skipped` with `stale_subscription`; the Log shows the reason as a sentence (section 10.8).

- A row is stale when it would mark the contact subscribed (its payload has `consent_changed` and
  `subscribed`) and a later row of the same shop and entity (section 2.2), with a consent change of
  its own, is `sent`. The later row may subscribe or unsubscribe: it is the contact's newer choice,
  and it was delivered.
- Rows match by shop and entity, whatever their source. A guest-checkout customer, a customer and a
  newsletter-block subscriber with one address are one Smaily contact, so a guest's ticked box is
  stale after a later delivered consent change of that address.
- A later row that is not delivered (`pending`, `processing`, `failed` or `skipped`) makes nothing
  stale. Two changes of one contact that wait together are claimed in id order and go in one
  request in that order, as before.
- **A row that unsubscribes is never stale** (the opposite order: an unsubscribe fails, a later
  subscription is delivered, the unsubscribe's retry goes and the contact ends unsubscribed in
  Smaily). Sent late it can only narrow who is subscribed, and the shopper subscribes again in the
  store. Skipping it would leave more contacts subscribed than today; that is the owner's call
  (section 14).
- The check is one `SELECT` per batch, only when the batch holds a row that subscribes, for all such
  rows together (section 4.3, item 5).
- **Siblings.** Neither sibling has this check for a contact sync: Magento and WooCommerce store
  `is_unsubscribed` in the row when it is queued and send the row as stored, in either order.
  Magento has the pattern for another consent row: its handler of a shopper's profiling choice
  closes a row that the shopper has since replaced as skipped, with a reason, so that an older
  answer never undoes a newer one (`magento:Model/Queue/Handler/ProfilingConsentHandler.php`). The
  check here follows that pattern for the direction that subscribes.
- The Log's "Send again" and "Retry" ask the same class (`Log\ResendGuard`, section 10.8), so a row
  that the Log offers to send again is not skipped as stale when it is sent.

### 6.5 Reconcile, daily refresh and first import (as built in Story 2.4)

`ContactReconcileProvider` registers the three jobs of section 4.1: `contacts_reconcile` (every 15 min,
15 s), `contacts_refresh` (daily, 5 s) and `contacts_import` (every 60 s, 15 s).

**Reconcile** (`Contacts\Reconciler`). The shops that take part are those whose contact sync is active
and whose mode is subscribers only. For each Smaily account that serves one of those shops in some
language, the reconcile reads the account's action log (`history.php`, the actions `optin`, `optout`,
`delete` and `complaint`) from its cursor on, in pages of 10 000 rows, oldest first. `optout`, `delete`
and `complaint` set `customer.newsletter = 0` and `emailsubscription.active = 0`; `optin` sets
`customer.newsletter = 1`. The first run of each account follows the first-run rule of 6.2; it reads
the unsubscriber list in pages of 1000.

- A change touches only rows of the shops that take part whose shop and language resolve to the
  account that reported it (`ScopeResolver`), so a shop is never changed by another shop's account.
- The writes go straight to the `customer` and `emailsubscription` tables with `Db`: they fire no
  hook and enqueue nothing.
- The cursor is stored after every chunk of 500 log rows and after every list page, so a run that its
  deadline stops goes on from there at the next tick.
- An account whose call fails is logged with the shop, the language and the failure reason, never a
  contact; the other accounts go on.
- **Known limit.** Smaily pages the unsubscriber list by page number. When the list shrinks between
  two ticks of one first run, one address can be skipped; that contact then stays as the store has
  it. 2.x had the same exposure.

**The cursors** (`SMAILY_CONNECT_RECONCILE_CURSORS`, global): a JSON object with one entry per
account, keyed `<account id_shop>:<account language>:<subdomain in lower case>`. An account's entry is
absent or has one of three shapes:

| Shape | Meaning |
|---|---|
| (no entry) | The first run has not started. |
| `{"seq_id": <n>, "first_run": true}` | The first run reads the action log; `<n>` is the last `seq_id` read. |
| `{"seq_id": <log end>, "first_run": true, "list_page": <p>}` | The first run has read the log to its end and reads the unsubscriber list from page `<p>` (the first page is 0). |
| `<n>` (a number) | The first run is done; `<n>` is the last `seq_id` read. A finished first run is never stored back as a first run. |

The reconcile reads the cursors from the table each time, because another request may have written
them after this one loaded the configuration.

**Imports** (`Contacts\Import\ContactImport`, `ContactSource`, `JobStore`). The first import and the
daily refresh are rows of `smaily_connect_job`:

| Column | Content |
|---|---|
| `type` | `contacts_import` (the first import, which the admin starts) or `contacts_refresh` (the daily refresh). |
| `id_shop` | One shop, or `0` = every shop. The refresh always uses `0`. |
| `status` | `queued`, `running`, `done`, `failed` or `cancelled`. |
| `cursor` | `customer:<id_customer>` or `newsletter_block:<id>`, the last record sent; `NULL` = the start. Customers come first, then the active `emailsubscription` rows, each in primary-key order. |
| `counts` | JSON `{"total", "sent", "failed", "skipped"}`: `total` is the audience when the run started; `skipped` counts audience contacts whose account is missing or has no credentials. |
| `last_error` | A reason code or a failure class, never a contact: `contact_sync_off`, `no_account`, `no_credentials`, or a `Failure` reason. |

- Every record is weighed by the settings of its own shop: a shop whose contact sync is not active
  sends nothing, and `ContactAudience` decides who is in the audience. A customer with
  `deleted = 1` is never read.
- The job `contacts_import` sends pages of 500 records, one request per account, until its deadline
  passes, and stores the cursor and the counts after each page. A group refused with code 203 goes
  again one contact per request. A permanent refusal counts the contacts as failed, and the run goes
  on. A temporary failure (a 5xx, a 429, the network) ends the run as `failed` before the page.
- No import row carries `is_unsubscribed`: an import passes no consent change, so it never
  re-subscribes anyone.
- The job `contacts_refresh` queues a refresh of every shop once a day. It runs whether or not a first
  import ran. It queues nothing while an import or a refresh is still `queued` or `running`, while no
  shop has an active contact sync, or while the audience is empty.

**The calls the admin uses** (`Services::instance()->get(ContactImport::class)`):

| Call | Does |
|---|---|
| `start(int $idShop = 0): int` | Starts the first import of one shop (`0` = every shop) and returns the job id. A `queued` or `running` import is left as it is. A `cancelled` or `failed` import is queued again and resumes from its cursor. After a `done` import, a start imports again from the first contact. With no active contact sync in its shops, the import is `done` at once with `last_error` `contact_sync_off`. |
| `stop(int $idShop = 0): bool` | Stops the import after the page it is sending (`cancelled`); `false` when none was `queued` or `running`. |
| `progress(int $idShop = 0, string $type = 'contacts_import'): ?array` | The newest run of that type and shop, or `null`: `{"id", "type", "id_shop", "status", "total", "sent", "failed", "skipped", "started_at", "updated_at", "finished_at", "last_error"}`; times are UTC. |

---

## 7. Event hook map

| Hook | Queue event(s) | Story |
|---|---|---|
| `actionCustomerAccountAdd` | `contact.sync`; `automation.welcome` (a storefront registration with `newsletter = 1`); engine customer row; engine `identity_merge` and `vs_` token at registration and guest checkout, where `actionAuthentication` does not fire | 2.3, 4.2, 5.2, 5.5 |
| `actionObjectCustomerUpdateBefore`, `actionObjectCustomerUpdateAfter`, `actionCustomerAccountUpdate` | `contact.sync` (with status only on change); `automation.welcome` on 0→1 in the storefront | 2.3, 4.2 |
| `actionObjectCustomerDeleteAfter` | Registered; no listener on `v3` (the plan was to mark the customer's tracker rows; open, section 13, "Queued clean-up"). The cart reminder reads no address of a deleted customer (`CartAddress`), and sends none to a cart whose customer is gone (`customer_deleted`, below). Nothing to Smaily: erasure goes through psgdpr. | — |
| `actionNewsletterRegistrationBefore` | `contact.sync` for a block unsubscribe (`action = '1'`), after the stored state confirms it (6.3). | 2.3 |
| `actionNewsletterRegistrationAfter` | `contact.sync` and `automation.welcome` for a block subscribe only, also when it fires from the verification link (6.3) | 2.3, 4.2 |
| `actionAuthentication` | Fires on login only (facts file part 2). Engine `identity_merge` (§7), customer row (§4 group c), `vs_` token for a shopper who logs in | 5.2, 5.5 |
| `actionCartSave` | Registered; no listener on `v3`. The sweep reads the cart's last change from `cart.date_upd` (below). | 4.3 |
| `actionValidateOrder` | `contact.sync` (profile and consent at order time); every reminder of that address that is not sent yet withdrawn (`skipped`, reason `ordered`); `automation.first_order`; attribution capture; engine order row | 2.3, 4.2, 4.3, 5.2, 5.5 |
| `actionOrderStatusPostUpdate`, `actionOrderEdited` | Engine order re-send (whole row) | 5.2 |
| `actionOrderSlipAdd` | Engine order re-send with `items[].returned_at` on fully refunded lines | 5.2 |
| `actionObjectProductAddAfter`, `actionObjectProductUpdateAfter`, `actionUpdateQuantity`, `actionObjectCombinationUpdateAfter`, `actionObjectSpecificPriceAddAfter` / `UpdateAfter` / `DeleteAfter` | Catalog marker for the product, one per product per request: a v2 product save fires `actionObjectProductUpdateAfter` 7 times, and a combination "Save list" fires `actionObjectCombinationUpdateAfter` for every row of the list (facts file part 2) | 5.2 |
| `actionObjectProductDeleteAfter`, `actionObjectCombinationDeleteAfter` | `catalog_remove` (§3b) | 5.2 |
| `actionObjectCategoryUpdateAfter` | Not registered (not in `Install\HookList`). A category rename reaches the engine through the next catalog import ("Run again"), as the nightly manifest does not carry names. | — |
| `actionObjectShopDeleteAfter` | §16 for a connection anchored on that shop | 5.1 |
| `actionFrontControllerSetMedia` | Fallback tick; the checkout capture script of the cart reminder (below); front JS and page context | 1.6, 4.3, 5.5 |
| `actionAdminControllerSetMedia`, `displayBackOfficeHeader` | Fallback tick; upgrade and health notices | 1.6, 3.1 |
| `additionalCustomerFormFields` | Own checkout box (6.1) | 2.3 |
| `displayCustomerAccount` | "Personalization" link (§10), only with a connection | 5.6 |
| `moduleRoutes` | Friendly URLs for relay, land, recommendations, personalization | 5.5 |
| `actionExportGDPRData`, `actionDeleteGDPRCustomer`, `registerGDPRConsent` | Export and erasure (section 11) | 5.6 |

`Install\HookList` registers the 32 hooks of this table, which excludes
`actionObjectCategoryUpdateAfter`; the main class has one one-line method for each. A row whose
Story is not done (section 13) names a hook that is registered and has no listener yet.

**The store automations as built** (Stories 4.1 to 4.3, `Automation\`). The queue holds one event
type per automation: `automation.welcome`, `automation.first_order` and `automation.abandoned_cart`
(section 2.2), and no shared trigger type. `Automation\TriggerSender` posts every trigger
to `autoresponder.php` through the account that `Automation\Router` matched (section 5.2), always
with `force_opt_in = false`.

- **Welcome** (`Welcome\WelcomeTrigger`). It listens to the contact events of the capture (section
  6.3) and queues one row for an event that gained the consent in a storefront request, where the
  welcome is on in the contact's shop and the shop's consent mode sends the contact. A consent that
  the back office, the reconcile, an import or the upgrade writes starts no welcome. Each storefront
  subscribe starts it, a re-subscribe too: there is no once-per-contact record. The contact sync
  itself need not be on.
- **First order** (`FirstOrder\FirstOrderTrigger`, `FirstOrderHandler`). `actionValidateOrder` queues
  one row for the first order of a registered customer (no earlier order, in any state); a
  guest-checkout customer never gets it. The handler checks the consent at send time with
  `Automation\MarketingConsent`, the check of the cart reminder.
- **Abandoned cart**: the sweep and the handler below.

**The abandoned-cart sweep** (`AbandonedCart\AbandonedCartSweeper`, the job `abandoned_cart` of
section 4.1, every 5 minutes). In each shop whose abandoned-cart automation is on, a cart is due a
reminder when all of these hold:

- it has products and no order;
- it has an address (`AbandonedCart\CartAddress`): the email of the cart's customer, registered or
  guest-checkout, unless that customer is deleted; else the address recorded on the cart's tracker
  row by the checkout capture (below);
- **its last change (`cart.date_upd`) lies between the shop's delay and 24 hours ago**
  (`CartTracker::WINDOW_SECONDS`, 86 400 s). The delay is `SMAILY_CONNECT_CART_DELAY_MINUTES`, 10 to
  1435 minutes (section 2.1). A cart whose last change is older than 24 hours is never picked up,
  which is why no delay of a day or more is stored and why the upgrade caps a longer 2.x delay
  (section 3.3). The window is Magento's; 2.x looked back 10 days;
- it has no reminder state yet: no tracker row (`smaily_connect_cart`), or an `open` row that holds
  only a recorded address. A cart with a `mailed` or `skipped` row is never reminded; the reminders
  that 2.x sent are `mailed` rows (section 3.5).

For a due cart the sweep writes the tracker row first, then queues one `automation.abandoned_cart`
event with the address, the template fields (below) and the marker field. One address gets at most
one reminder per 24 hours, whatever number of carts carry it: a cart whose address had a reminder
sent in that time, or has one in the queue that can still go, is closed as `recently_reminded` (a
2.x row blocks by its customer). Its event is still queued, so the Log shows the skip. A queued
reminder can go until 24 hours after its cart's last change (the send's `too_old` below), and it
blocks its address for that long: a reminder that failed and was parked (a refusal, or five failed
attempts) leaves its tracker row `open`, and that row holds the address back no longer than this.
As in Magento, where a failed reminder counts for 24 hours from its queueing, it is never a block
for good.

**The send** (`AbandonedCart\AbandonedCartHandler`) checks again when the row's turn comes, in this
order: the tracker row (an order withdrew the reminder, or the sweep closed it); the cart's
customer is gone (`customer_deleted`, below); the cart has an
order, or its address ordered since the cart's last change (`ordered`); the cart's last change is
more than 24 hours ago (`too_old`), so a row that waited on the retry ladder past the window is not
sent; the router's workflow and account (`automation_off`, `no_workflow`, `no_account`,
`no_credentials`); Smaily's status of the contact, read through that account
(`unsubscribed_in_smaily`; a read that fails sends nothing, and the row takes the retry ladder);
the consent of the shop's mode (`no_marketing_consent`, section 6.2). A reason never names the
contact.

**A deleted customer gets no reminder.** A cart that names a customer whose row is removed, or
flagged `deleted`, is not reminded (`CartAddress::customerGone()`), also not at the address that the
checkout capture recorded on the cart before the customer existed: without this rule the recorded
address stood in for the deleted customer's. The sweep closes such a cart as `customer_deleted`,
removes the recorded address from the tracker row (`CartTracker::forget()`) and queues its event
without the address, so the Log shows the skip with its sentence and no contact. The send asks
again, for a customer deleted after the reminder was queued, and does the same to the tracker row.
A cart that never had a customer is not meant: its recorded address is the only identity it ever
had, and it is reminded as before. `actionObjectCustomerDeleteAfter` stays without a listener.

**The checkout capture** (`AbandonedCart\Capture\`, front controller `capture`). On the checkout
page of a visitor who is no customer yet, in a shop whose abandoned-cart automation is on, the
script `views/js/front/checkout-capture.js` posts the address typed in the customer form, and
whether its newsletter box is ticked, to the endpoint. The endpoint records them on the tracker row
of the cart of the visitor's own cookie, and reads no cart, shop or customer that the request
names. The recorded address serves the cart's reminder only, under the reminder's consent rule: it
is not sent to Smaily as a contact and not written to a customer. `CartTracker::purge()`, which
every sweep runs, deletes a recorded address that served no reminder once its cart has not changed
for 24 hours, and clears the address of a closed cart 48 hours after the cart's last change. A cart
whose queued reminder did not go within those 24 hours (it failed and was parked, or it still
waits) loses its address then too; its tracker row stays, as the state of the queue row. The Log's
"Send again" of such a row needs no address from the tracker: the queue row's payload holds the
address and the fields, and the tracker row follows the new queue row. Within the 24 hours it
delivers the reminder; later the send closes it as `too_old`, as before.

The endpoint is open to visitors without an account, so it is a way to put an address that agreed
to nothing on a cart. What bounds it (the siblings: Magento's guest-cart route takes the cart's
masked id and counts requests in its cache; WooCommerce records the address inside its own
checkout request, behind WooCommerce's session nonce):

- **The token is the cart's own.** The endpoint takes a POST with `CaptureEndpoint::token()` only:
  an HMAC-SHA256 of the cart's id under the shop's `_COOKIE_KEY_`, compared in constant time. Only
  the checkout page of that cart prints it, so a caller must load the checkout page of every cart
  it writes to, and a token opens no other cart. PrestaShop's static front token
  (`Tools::getToken(false)`) is refused: it is one value for every visitor without an account.
- **One reminder per address per 24 hours**, whatever number of carts and visitors record the
  address: the per-address rule of the sweep above, read from the tracker rows
  (`CartTracker::blocking()`). The later carts are closed as `recently_reminded`, and the Log shows
  each with its sentence. The rule counts across all shops of the installation.
- At most 6 captures per visitor per minute, counted in the visitor's cookie. It slows a browser
  down; a caller that drops its cookie is not held by it.
- **At most 2000 addresses per hour for the installation**, every shop together
  (`CaptureEndpoint::HOURLY_LIMIT`; Magento's `MAX_WRITES_PER_HOUR`, also one count for the whole
  installation). Magento counts in its cache, in clock hours; PrestaShop has no shared cache, so
  the count is one query over rows that exist anyway (`CartTracker::recorded()`), over a rolling
  hour: the tracker's recorded addresses of the last hour that the sweep has not taken yet
  (`status` `open`, no `event_id`: the index on `status`, `last_activity_at`), plus the cart
  reminders queued in the last hour (the event queue's index on `created_at`). The second part is
  needed because the sweep replaces a row's `last_activity_at` with the cart's last change: the
  tracker alone no longer says when a taken address was recorded, and a cart older than an hour
  would leave the count at once. Each part stops counting at the limit. Every address recorded
  in the last hour is in one of the two parts, unless its cart was ordered or the sweep could not
  build its reminder (neither gets one), so no more than 2000 carts get an address in any hour. The count is wider than Magento's on one
  side: the reminders of customers' carts queued in that hour count too, so a store that queues
  cart reminders by the thousand an hour records fewer checkout addresses. A capture at the limit
  is answered as every other request (the empty JSON object, as Magento answers `false` to every
  refusal) and writes nothing, also not a new address on a cart that has one. Two requests that
  count at the same moment can both pass, as in Magento.
- **The merchant sees it in the Log**: the first refused capture of an hour adds one row, a
  skipped `automation.abandoned_cart` event of the refused cart's shop without a contact, with the
  reason `capture_limit` and its sentence (`EventQueue::note()`, under the lock `capture_limit`,
  so requests that arrive together add one row). Magento shows its merchant nothing.

Not built: Magento's 5 writes per cart. The tracker row has no counter, and a count needs a
column; a cart holds one address and gets one reminder, whatever number of times its address
changed. No limit counts the requests of one network address: the module stores no visitor
addresses.

What stays possible: a caller that loads a checkout page for each cart can have one reminder sent
to each address it names, once per 24 hours per address and to no more than 2000 addresses an
hour, where the shop's consent mode sends a reminder to that address ("All customers": any
address; the other two modes: with the newsletter box, which the caller sets). While such a
caller holds the count at the limit, the addresses of real shoppers are not recorded either.

**[D]** The abandoned-cart payload keeps the 2.x template fields byte-compatible: the 10 product slots
with `''` for unused slots (the empty values clear the previous cart in Smaily,
`woo:docs/LESSONS.md` §2.21), `over_10_products`, `is_abandoned_cart`, `force_opt_in = false`.
**[D]** (owner, 2026-11-20) The 2.x field picker is gone, as in the sibling connectors
(`magento:Model/AbandonedCart/PayloadBuilder.php`, `woo:includes/Smaily/CartPayloadBuilder.php`): it
is a dead switch on a fresh install (`woo:` §2.21), and 3.0.0 has no screen for it. Every reminder
carries every product field (name, description, sku, price, base_price, quantity) in all 10 slots,
and `first_name` and `last_name` when they are known; a name that is unknown or empty is left out,
because Smaily keeps an absent field and wipes an empty one. The payload equals, field by field, what
2.2.0 sends with every box ticked; a store that had fewer boxes ticked sends more after the upgrade.
No setting holds a selection, and the upgrade migrates none.

**[D]** Each automation writes its marker (`welcome_automation_at`, `first_order_automation_at`,
`abandoned_cart_automation_at`) as a contact field on the trigger, as the siblings do; the marker
is stamped when the event is queued (`Automation\Trigger::marker()`).

**Discount codes as built** (Story 4.4, `Discount\`; owner's decisions of 2026-11-20, section 15).
Each store automation can send one discount code with its email, as the Magento connector (its
CON-198) and the WooCommerce connector (its CON-199) do. The merchant makes the discount as a cart
rule in Catalog > Discounts; the module issues one code from it for each email.

- **Settings.** Per automation and per shop: the switch "Send a discount code", the cart rule and
  "Valid for (days)" (section 2.1), saved by the Automations panel (section 10.9).
- **Which cart rule can be the template** (`Discount\TemplateRules`). A cart rule that the merchant
  made: not one that this module issued or that comes from such a code (the marker, below), not
  limited to one customer (`id_customer`), not past its
  end date (the shop's time), with uses left (below), and usable in the shop (no shop restriction,
  or the shop among its shops). An enabled and a disabled rule alike. A rule limited to one customer is never a
  template, because a code is a copy for another shopper, and because the vouchers that PrestaShop
  makes itself are a customer's own: the voucher of a refund
  (`Adapter\Order\Refund\VoucherGenerator`), the discount and the free shipping that an employee
  gives an order or a back-office cart (`AddCartRuleToOrderHandler`, `AddProductToOrderHandler`,
  `UpdateCartDeliverySettingsHandler`), and the remainder of a voucher that was spent in part
  (`PaymentModule::validateOrder()`). Each carries the `id_customer` of its order or cart, with one
  exception: the remainder voucher of a guest's order has `id_customer` 0 and nothing else that
  tells it from a rule of the merchant, so the picker lists it, under the name of the rule it
  remains of. The remainder of one of the module's own codes is never listed: it carries the
  marker. **PrestaShop differs from the siblings here:** an enabled cart rule without a code applies
  to every cart by itself, and anyone who knows the code of an enabled rule can use it. So the
  merchant keeps the template disabled, or gives it a code that is not shared (and not
  highlighted); the panel says so. A code is always issued enabled.
- **Issuing** (`Discount\CodeIssuer`). A copy of the template as PrestaShop itself copies a rule
  (the remainder voucher of `PaymentModule`): the main row, then `CartRule::copyConditions()` for
  the shop, carrier, group, country, combination and product restrictions. The discount and every
  condition are the template's. The copy differs in this: a random code of 10 upper-case letters
  and digits without 0, O, 1 and I, checked unique; the name "Smaily Connect – <the template's
  name>" in every language; the description `smaily-connect-issued: code=<the code>
  template=<id> trigger=<automation>`; `quantity` 1 and `quantity_per_user` 1; `highlight` 0, so an untied code
  is never advertised in a cart; `active` 1; `id_customer` (below). On PrestaShop 9 the copy's
  `total_quantity` is 1.
- **PrestaShop 9.2's discount types.** 9.2.0 gives a cart rule a type (`cart_rule.id_cart_rule_type`,
  one of the five rows of `cart_rule_type`) and the types it combines with
  (`cart_rule_compatible_types`); the new Discounts form writes both. They decide only while the
  feature flag `discount` is on, which it is not by default (`CartRule::checkValidity()` and
  `Cart::addCartRule()` ask `DiscountApplicationService`): two typed discounts combine when each
  names the other's type (`DiscountTypeRepository::areDiscountsCompatible()`), else the one of
  lower priority leaves the cart or the new one is refused. A rule without a type follows the
  older combination rows, which `copyConditions()` copies. The copy takes the template's type
  with the main row, but `CartRule::copyConditions()` (`classes/CartRule.php`, lines 288 to 342)
  does not copy `cart_rule_compatible_types`, as PrestaShop's own remainder voucher shows; its
  `DiscountDuplicator` does. A code without those rows would be narrower than its template, not
  wider: with the flag on it took a free-shipping discount out of the cart that its template
  combines with (observed on 9.2.0). `CodeIssuer` therefore copies the rows itself where the
  table exists; a copy that fails removes the code and fails the row. PrestaShop 8 has no such
  table and no type.
- **The marker.** No column and no table says that a cart rule is one of the module's codes: its
  description does, in two ways that differ on purpose.
  - *A code of the module* (`CodeIssuer::isIssued()`, `issued()`): the description starts with
    `smaily-connect-issued: code=` and the code it names is the rule's own `code`, compared byte
    by byte. Only such a rule is ever removed (the clean-up and the privacy operations below).
    When a code with partial use is not spent in full, PrestaShop makes a remainder voucher that
    copies the code's description and customer under a new code, `<the code>-2`
    (`PaymentModule::validateOrder()`). It does not name its own code, so it is not one of the
    module's: it holds the shopper's remaining credit, and nothing here selects or removes it.
  - *A rule that comes from a code* (`CodeIssuer::isMarked()`, `notMarked()`): the description
    starts with `smaily-connect-issued:` at all. Such a rule is never a template, the remainder
    voucher included.
  A cart rule of a development build before 3.0.0, whose description is `smaily-connect-issued:
  template=<id> trigger=<automation>` without a code, is on the safe side of both: never a
  template and never removed. Such rules exist in development shops only. A rule whose
  description the merchant rewrote is the merchant's from then on.
- **Validity.** Until 23:59:59 of the day N days after today, in the shop's time, and never after
  the template's own end date. The contact field carries that last valid day as `Y-m-d`; a
  template that ends at 00:00:00 leaves the day before. PrestaShop itself refuses the code after
  that, after its one use, and for another customer (`CartRule::checkValidity()`): the module adds
  no check, where Magento needed a plugin for the end date. The start date is one day before the
  code is made: PrestaShop 8 lists a customer's vouchers by the clock of the database server
  (`NOW()` in `CartRule::getCustomerCartRules()`), which can be hours behind the shop's, and nobody
  holds the code before the email goes. A code never starts before its template: where the
  template's start date is later than that, the code's start is the template's.
- **What the template's own fields mean for its codes.**
  - *Start date.* A rule can be picked before it starts. A code mailed before then is refused at
    the cart until the template's start ("This voucher is not valid yet") and works from then to
    its own last valid day. A template that starts only after the code would end gives no code.
  - *End date.* No code is valid after it, and a template past it gives no code.
  - *Total quantity.* A template whose quantity is 0 gives no code, and the picker does not list
    it. The codes do not spend the template's quantity: each code is a rule of its own with one
    use. PrestaShop 9 stores no quantity for a rule without a limit, which counts as uses left.
  - *Status.* Not read: the codes of a disabled template work, and each code is enabled.
- **Whose code it is** (owner, as WooCommerce ties its code to the address). The code of an address
  that has a customer account in the shop (not a guest-checkout row, not deleted; an account of
  the shop, or of its shop group where the group shares customers) carries that account's
  `id_customer`: only that customer, signed in, can use it, and the account's voucher page lists
  it. An address without an account gets an untied code, which whoever enters it can use, once.
- **When.** `Discount\AutomationDiscount::fields()` runs in both send paths once
  `Automation\TriggerSender` has the router's workflow, just before the post (the `$beforeSend`
  of `TriggerSender::send()`): for the welcome and the first order through
  `Queue\Handler\AutomationHandler`, for the reminder through `AbandonedCartHandler`. The
  consent checks of the first order and of the reminder come before it, so a skipped row makes no
  code; the router has already skipped a row whose automation is off.
- **Once per queue row.** The code and its last valid day go into the row's stored `fields`
  before the post (`EventQueue::updatePayload()`, section 4.3). An automatic retry and the Log's
  "Retry" find them there, and "Send again" copies the payload, so each sends the same code and
  makes no second rule. The same holds when a flush is so slow that its claim goes stale and a
  second flush takes the row (`EventQueue::requeueStale()`): the payload write belongs to the
  claim, so the flush that lost the row stores nothing, removes the code it made
  (`CodeIssuer::discard()`) and posts nothing, and the row gets the one code of the flush that
  holds it. A code whose payload write fails is removed the same way. What stays possible: a
  process that dies between making the code and storing it leaves one cart rule that no row names
  and nobody was sent. Nothing finds it earlier; the clean-up below removes it 30 days after its
  end date.
- **The contact fields** are the siblings': `welcome_coupon_code` and `welcome_coupon_expires_at`,
  `first_order_coupon_code` and `first_order_coupon_expires_at`, `abandoned_cart_coupon_code` and
  `abandoned_cart_coupon_expires_at` (`Automation\Trigger::COUPON_FIELDS`). No amount is sent.
  With the switch off the payload is what it was before.
- **No code.** A template that is deleted, limited to one customer, past its end date, without
  uses left, not usable in the row's shop, or that starts only after the code would end does not
  hold the email back: it goes without a code, and the row's payload keeps
  `"discount_notice": {"reason": "rule_unavailable", "rule": <id>}`, which the Log's Details show
  as a sentence (section 10.8). A row stored that way makes no code on a retry either; the next
  event does, once the template is fixed. An error while the code is made (PrestaShop refuses the
  copy, or another module's hook on a new cart rule throws) fails the row with nothing posted, and
  the row takes the retry ladder; the issuer removes a copy that was stored only in part. A read
  that fails for a moment (the template, its shops, the address's customer account) is such an
  error and never a notice: the row keeps no notice, and its retry makes the code. PrestaShop 8
  throws on a failed query by itself. PrestaShop 9.2.0 with debug mode off answers a failed query
  as `false`, which its object model and `Db::getValue()` hand on as "no such row" (facts file
  6.11), so `TemplateRules::usable()` counts the rows (a count always answers a row) and
  `AutomationDiscount` reads the customer account as a list, and each throws when the read
  failed. Without that, a failed read stored the notice, and the row, its retry and "Send again"
  all went without a code; a failed read of the customer account made the code of a customer
  with an account one that anyone could use.
- **Clean-up** (`Discount\IssuedCodes`, section 11.3). The daily janitor removes a code that no
  order carries once its end date is more than 30 days past. A used code stays. Used means an
  `order_cart_rule` row, also one that an employee removed from the order. A code that only sits
  in a cart is not used: the janitor's codes ended 30 days ago, so no cart can order with one, and
  `CartRule::delete()` takes the code out of the cart. A merchant's rule, a template and a
  remainder voucher are never selected: each query asks for a code of the module (the marker).
- **Privacy** (`IssuedCodes::ofCustomer()`, `ofAddress()`, `removeUnused()`). The codes of a
  customer account, and the codes that the automation rows of the event queue still hold for an
  address, each with its creation and end time and whether an order used it; and the removal of
  the unused ones, also of one that sits in the shopper's cart. A remainder voucher is not among
  them and keeps its value. Story 5.6 wires them into the export and the erasure; nothing calls
  them yet.
- **The Discounts list** shows the issued rules under their "Smaily Connect – …" name (owner's
  decision): it is not filtered.
- **Not built.** One working code per shopper and automation, sent again while it is unused
  (WooCommerce's rule): each event makes a new code here, as in Magento. A discount code for a
  Campaign Intelligence automation. The picker lists at most 200 rules, by name.

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
| §6c | Stock alerts: out of 3.0.0 (section 8.8, Linear CON-371). |
| §14, §17–§24 | Out of 3.0.0 (owner's list). |

**[D]** Endpoint URLs come from the stored endpoints map, with the fallback path constants for keys a
connection lacks, as in both siblings. Retry and timeouts: Magento's `Engine\Client` rules (contract
"Wire contracts" in `magento:docs/ARCHITECTURE.md`).

### 8.2 Ids and keys

A key has two parts, and the contract gives each to a different owner (contract §1 "Connection
prefix", §3 "Catalog identity"):

- **The connection prefix is the engine's.** The engine assigns it per connection (`c<N>-`, or `""`)
  and sends it as `config.connection.prefix` in the setup exchange and the ping. It is opaque: the
  module stores it with the connection, never parses, shows or derives it, and puts it in front of every
  key the contract names (§3 `sku` and `tags.product_id`, §3b `product_ids`, §3c `sku`, §5
  `external_order_id`, §6 `sku`). Today the engine assigns `""` to every connection (§1 "Status",
  2026-11-17).
- **The platform key is the connector's.** The contract requires the platform's stable internal id,
  namespaced by source (`shp-<variant_id>`, `woo-<id>`), never the merchant's SKU field. It names no
  PrestaShop namespace, and its fail-loud enforcement checks each sender's declared namespace pattern
  for connections without a prefix (PRO-1223, rolling out). `ps-` is therefore the module's proposal
  and needs the engine team's agreement before Story 5.2 ships.

**[D]** The module declares `capabilities: ["connection_prefix"]` from the first release and applies
the prefix everywhere, so the engine's later refusal of connectors without the capability (`409
plugin_update_required`) never reaches it. On a reconnect it sends `connection.previous_prefix`; a
connection that lost its prefix reads it from the ping.

| Id | 3.0.0 value |
|---|---|
| Catalog `sku`, §3c `sku`, §6 `sku`, order line `sku` | `<prefix>ps-<id_product>` for a product without combinations; `<prefix>ps-<id_product>-<id_product_attribute>` for each combination (decision 6: one row per combination). The platform key stays within 64 characters (contract "Base context"). PrestaShop's `reference` is optional and not unique, so it is never the key (send it as `tags.merchant_sku` only if an operator asks). One builder in `Engine\Payload` makes the key for every path (contract "Same key from every path"). `ps-` **[O]** needs the engine team's agreement (section 14). |
| `tags.product_id`, §3b `product_ids` | `<prefix><id_product>`: the bare parent id, as Shopify and Woo send it (contract §3 "Parent product id"). |
| `external_id` (catalog) | `<id_product>` or `<id_product>-<id_product_attribute>`, no prefix. |
| `external_order_id` | `<prefix><id_order>` |
| `customer_external_id` | `<id_customer>`, no prefix (the contract prefixes no customer id). |
| `website` / `websites` key | **[D]** The shop id as a string (`"1"`). The contract settles this: the connector names its own stable website code, 1–64 characters, compared exactly as sent (contract "Base context", v1.21.0). PrestaShop shops have no code; the id is stable. Sent only by a connection that serves 2+ shops. |
| History `import_id` | `ps-<YYYY-MM-DD>-<job id>` (the connector's own id, §25). |

Why one row per combination: the contract's `sku` is the variant-level key, and a §6c stock-alert
request names one variant (section 8.8). One row per product would need a key change, and a key change
leaves stale rows that only a manual engine purge removes (contract §3 "Lifecycle").

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

### 8.8 Out of 3.0.0: stock alerts (§6c)

Contract §6c (since v1.35.0) lets a shopper ask to be told when an out-of-stock product is back. It
is **not in 3.0.0**. The engine side is built and, since 2026-11-20, switched on (contract §1,
"Stock alerts"); the work on this module is tracked as Linear CON-371
(the PrestaShop specifics are in the engine repository's `docs/DRAFT_contract_1.35.0_stock_alerts.md`
§L). 3.0.0 builds no "Notify me" button, no relay route for it, no request table and no
`ps_emailalerts` handling.

**[D]** 3.0.0 must not block it. These parts of the design already carry what §6c needs, and a Story
that changes one of them keeps it:

- one catalog row per combination, each with its own `in_stock` (8.2), because a request names one
  variant;
- every stock change (`actionUpdateQuantity`, combination and product saves) re-sends the row through
  the ingest queue, and the nightly manifest carries `in_stock` (section 7), because the engine sends
  the alert on the restock that the catalog reports;
- one key builder with the connection prefix (8.2), because a request carries the §3 `sku` exactly;
- the ingest queue's `domain` column is free text, so a `stock_alerts` domain needs no schema change;
- the storefront pattern of 8.4 and 8.7 (a server relay, the same page HTML for every visitor,
  per-visitor state after load) and the `displayCustomerAccount` link, which a later "Stock alerts"
  page joins;
- privacy export and erasure (section 11.2) read the module's tables through one place that a later
  request table can join.

---

## 9. Cookie consent for tracking and recommendations

PrestaShop has no consent API: no equivalent of the WP Consent API or Magento's cookie restriction
mode. Browse tracking and the recommendations call set or read non-essential cookies, so they need the
visitor's prior consent under ePrivacy rules.

### 9.1 Design

**[D]** (decision 2, owner 2026-11-20) A setting that the merchant confirms, plus the developer hook:

1. **The setting.** Settings > Intelligence carries a switch
   "My store's cookie banner covers Smaily tracking" (`SMAILY_CONNECT_CONSENT_CONFIRMED`, global or
   per shop under separate stores). It is **off** on every new install and every upgrade (2.x had no
   tracking, so an upgrade widens nothing). While it is off, the module runs no browse tracking, makes
   no recommendations call and sets no tracking cookie.
2. **The merchant's responsibility.** When the merchant switches it on, the module treats every
   visitor of that shop as having consented to Smaily tracking and recommendations. The help text says
   plainly that the merchant carries this responsibility: the store's cookie banner must ask for this
   tracking, and the merchant confirms that it does. The exact EN and ET wording is aligned with the
   sibling connectors in Stage 3 (Stories 3.3 and 3.5).
3. **The developer hook.** `window.smailyConnect.consentOverride()` stays, as in Magento
   (`magento:view/frontend/web/js/consent.js`). Where a store defines it, its answer takes precedence
   over the setting: `true` means consent, any other answer means none. A store's developer can so
   wire the store's banner to the visitor's own choice, and switch tracking off for a visitor whom the
   setting would cover.
4. **The rule order** in `consent.js`: the override function when defined; else the setting; else no
   consent.
5. **Attribution capture** from email links (URL parameters → first-party cookies, section 8.5) is not
   gated on this consent, as in Magento, Woo and Shopify: it records the click on the merchant's own
   email (`magento:view/frontend/web/js/attribution.js`).
6. A shopper's profiling opt-out (§10, section 11.2) applies whatever the setting says.

No consent-cookie-name rule and no adapters for named consent modules are built.

### 9.2 Considered and not taken

- **Override only** (fail closed until a developer wires the banner): most stores would add nothing,
  and Campaign Intelligence would learn only from orders.
- **Adapters for named consent modules:** vendor code that breaks when the vendor changes; no adapter
  covers most stores.
- **A merchant-configured consent cookie rule** (cookie name + the value that means "accepted"): the
  agent-side recommendation, which kept the siblings' "explicit yes" rule. The owner chose the
  merchant-confirmed setting instead.

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

**[D]** (orchestrator, 2026-11-20) Menu: a tab "Smaily Connect" with four children (Dashboard,
Initial setup, Settings, Log), registered through the module's `$tabs`, under Customers
(`AdminParentCustomer`) on every version. Core 8.2.8 and 9.2.0 create no Marketing parent tab: other
marketing modules create and delete one, and `AdminParentCustomer` exists on both (facts file 1.11).
The module's "Configure" button opens the Dashboard, or the Initial setup while it is not finished.
PrestaShop gives a module's tab the one `name` of `$tabs` in every language and translates it from
the tab's `wording` only when a language is installed or updated afterwards, so each page of the
module gives the tabs their wording in its employee's language (`Admin\Menu::name()`): the menu,
the breadcrumb and the tab bar then read as the page does.
Each page needs the right to read its menu tab: an employee without it gets the refusal page (HTTP
403). Each of the four page controllers asks `BaseController::opens()` first, and reads nothing
that only the opened page shows for a page that does not open.

**[D]** Every JSON endpoint is refused when the shop selection changed under its page
(`Admin\Api\AbstractJsonAction`). PrestaShop keeps the selected shop in the employee's one cookie, so
a second browser tab, or a late answer of another back-office page, changes it while a page of the
module is open; a save meant for one shop then ran for "All shops" and replaced the setup all shops
share. Each page carries the selection it was drawn for (`all`, a shop group or a shop;
`AbstractJsonAction::pageScope()`), `common.js` sends it with every request in the header
`X-Smaily-Scope`, and a request whose header differs from the selection it runs in answers 409
`scope_changed`: "The shop selected at the top of the page has changed since this page was opened.
Reload the page and try again." The header is only compared. The shop that a request reads and
writes is still the one of the request itself, so a forged header changes nothing but the refusal.

**[D]** The base controller stays `FrameworkBundleAdminController` (section 1.5). On 9.x,
`Context::getContext()->controller` inside a Symfony admin page is a `LegacyControllerContext` proxy,
so the module never detects the back office by the controller's class (facts file 1.9). On 8.2.8 a
controller's container is a service locator without `translator`, so the controllers word everything
through PrestaShop's context translator, `Admin\BaseController::translator()` (facts file 6.6).

### 10.2 Screen map

| PrestaShop screen | Magento source | Notes |
|---|---|---|
| Dashboard | `Controller/Adminhtml/Dashboard/Index.php`, `view/adminhtml/templates/dashboard/index.phtml`, `docs/internal/ADMIN_UI_TARGET_SPEC.md` §2.1 | Adds the Background tasks card (section 4). |
| Initial setup: Connect → Contacts → Automations → Intelligence → Overview | `Controller/Adminhtml/Wizard/Index.php`, `templates/wizard/index.phtml`, `templates/panel/{connection,subscribers,automations,intelligence,store-shape}.phtml`, `Api/SaveStep.php` | Forced for `SETUP_STATE = new`; prefilled and not blocking for `upgraded`. The store-layout question comes first on a multishop install while the layout is unknown. Invitation banner for a store without an account: `Controller/Adminhtml/Invitation`, `templates/panel/smaily-invitation-banner.phtml`. |
| Settings: Connection, Contacts, Automations, Intelligence, RSS | `Controller/Adminhtml/Settings/Index.php`, `templates/settings/index.phtml`, the same panels, `templates/config/{engine-automations,rss-builder}.phtml` | One writer for setup and Settings (Magento `WizardStepSaver`). |
| Log | `Controller/Adminhtml/Log/{Index,Details,MassRetry,Resend}.php`, `templates/log/*.phtml` | One list over both queues and the import jobs. As built: section 10.8. |

### 10.3 Visible differences that PrestaShop forces

| Difference | Why |
|---|---|
| The shop is chosen with PrestaShop's own shop-context selector in the header, not a picker on the page. Each tab carries the Magento switch "Use its own … on this shop". | PrestaShop admins already pick the shop there; a second picker gives two answers. |
| Settings > Connection: Test connection sits inside each account card, and the connection's status is asked of Smaily after the page has loaded. | An account per language shows one card per language, and each is tested on its own; a slow Smaily never holds the page. |
| Settings > RSS has no on/off switch. The builder keeps the 2.x feed parameters (`category_id`, `limit`, `sort_by`, `sort_order`) and the 2.x sort fields (`date_add`, `date_upd`, `name`, `price`, `id_product`). | The feed URL is a 2.x contract in merchants' Smaily templates (CLAUDE.md rule 2): it always answers, and it reads only its URL's parameters. |
| Settings > Contacts asks before a save that changes who is synced: the page names what the new mode, "include guests" or switching sync on sends from now on, and the merchant confirms. | Consent lives in PrestaShop (section 6.1), and a change of audience is the merchant's decision (wording approved by the owner, 2026-11-20). |
| Page frame, toolbar and buttons are PrestaShop's. | Platform shell. |
| The Log is a Twig table with filters, not a native grid. | A PrestaShop Grid component needs Symfony grid definitions that differ between 8 and 9; a plain table is one code path. |
| The Log lists the import jobs among the queue rows, with their own statuses (Queued, Running, Done, Failed, Cancelled) and their number of contacts as the Entity. Its empty list reads "No records found", PrestaShop's own words. | The module has no separate import screen for them; approved by the owner (2026-11-20). |
| The Log sorts by Created only, newest first. Only a failed event row has a checkbox, and "Select all (N)" takes every row of the filter. Details open in the module's own side panel, and the questions before "Send again" and "Retry" in its own dialog. | A plain table has no column sorting; only a failed row can be retried; PrestaShop 8 and 9 share no modal that a module's page can rely on. |
| No checkout checkbox where `ps_emailsubscription` shows one (6.1). | Avoids two boxes. |
| The Automations tab and step are drawn for the saved language mode and list the workflows of the saved Smaily account. A Connection save that changes the mode, or that replaces a Smaily account whose workflows went with it (section 5.1), loads the page again (the Initial setup opens again on step 2). Magento redraws both live from what is typed. | One page load is simpler than a second copy of every mode's rows in the page; nothing unsaved decides a workflow. |
| The abandoned-cart notes name PrestaShop's "Customer follow-up" module as an example of another tool, and say that the automation receives the cart contents, without Magento's recovery link. | The 2.x payload, which 3.0.0 keeps (section 7), carries no link. |
| A workflow list that cannot be loaded shows Magento's sentence ("Could not load the workflow list: …") with the module's own sentence for the reason, not Smaily's text. | `Automation\WorkflowList` never hands on Smaily's text: it answers a reason code, and `Admin\Setup\AutomationsPanel` words each code. |
| The Automations tab has no "Edit design", no transactional emails and no Campaign Intelligence card, and in mode `a` no notice of the languages without a workflow. | Out of 3.0.0, or a later Story (5.4). |
| The discount block of a card lists cart rules, enabled and disabled alike, and tells the merchant to keep the rule disabled or to give it a code that is not shared. Magento lists rules with "Use Auto Generation". | A PrestaShop cart rule has one code or none: an enabled rule without a code applies to every cart, and its code works for anyone (section 7). |
| The cron line and Background tasks card. | Section 4. |

### 10.4 Wording and translations

**[D]** EN source strings are copied from `magento:i18n/en_US.csv`, with "website" changed to "shop"
and "store view" to "language". ET strings come from `magento:i18n/et_EE.csv`, then from Woo's
`languages/` where Magento has none. Domains: `Modules.Smailyforprestashop.Admin` for the back office,
`Modules.Smailyforprestashop.Shop` for the storefront. Stories write EN strings in code and do not edit
XLIFF files; one translation sweep per stage (Stories 3.5 and 6.2) writes
`translations/et-EE/*.xlf`, so no two workers edit one XLIFF file. Stories 3.4 and 4.1 wrote the
Estonian units of their own screens.

**As built (2026-11-21).** Every admin string has an Estonian target. A change that adds a string
appends its unit to `translations/et-EE/ModulesSmailyforprestashopAdmin.et-EE.xlf` (the id is the
MD5 of the source) in the same commit. The target is Magento's or Woo's for the same English, with
"veebisait" changed to "pood" and "poevaade" to "keel"; else it uses the terms below, in short
sentence-case imperatives without "teie". Product names stay as they are. A sentence with a
placeholder is worded so that the placeholder needs no case ending ("pood: %shop%").

| English | Estonian | From |
|---|---|---|
| Dashboard, Initial setup, Settings, Log | Töölaud, Algseadistus, Seaded, Logi | Magento |
| Automations, workflow, trigger | Automaatikad, töövoog, päästik | Magento |
| subscriber, unsubscribe, consent, opt-in | tellija, loobuma, nõusolek, (uudiskirjaga) liituma | Magento |
| contact sync, initial contact import | kontaktide sünkroonimine, esmane kontaktide import | Magento |
| abandoned cart, abandoned cart reminder | hüljatud ostukorv, ostukorvi meeldetuletus | Magento |
| discount code, cart price rule | sooduskood, ostukorvi hinnareegel | Magento |
| shop, language (Magento: website, store view) | pood, keel | this module |
| default fallback, default fallback account | vaikimisi varuvalik, vaikimisi varukonto | Magento |
| credentials, subdomain, token | kasutajaandmed, alamdomeen, võti | Magento |
| Background tasks, cron line | taustatööd, cron-rida | this module |
| upgrade, payload, queue, guest, checkout | uuendus, andmesisu, järjekord, külaline, kassa | Magento |

Ten strings of the Log are PrestaShop's own ("Search", "Reset", "Select all", "Cancel", "Close",
"From", "To", "Previous", "Next", "No records found", domains `Admin.Actions` and `Admin.Global`):
their Estonian comes from PrestaShop's language pack, not from the module. The Smaily client's own
message of a failure is stored in English and shows as stored (section 10.8).

### 10.5 Logos

**[D]** The admin screens use the new Smaily Connect and Campaign Intelligence logos (Linear GMS-66,
package `Smaily_Connect_and_CI_Logos_Motion_2026-11-05.zip`, approved by the owner on 2026-11-20; read
its `Motion_handoff_EN.txt` first). Story 3.1 put three of its files into `src/views/img/`: the
Connect lockup, its entrance animation and the Campaign Intelligence icon.

- **Where.** The Connect lockup (S symbol + wordmark) where the module names itself, for example the
  page header of the Dashboard and the Initial setup. The boxed Campaign Intelligence icon where the
  module shows Intelligence, for example the Intelligence tab and step.
- **Motion.** The entrance animation (Connect about 620 ms; Campaign Intelligence about 850 ms) plays
  once, at the entry point only. Everywhere else the logo is static, and under `prefers-reduced-motion:
  reduce` it is static everywhere. The animation is never tied to a connection, sync or sending
  state: real state shows as text.
- **Delivery.** The package's motion SVGs carry their own CSS; its `.css` files are an alternative, not
  an addition. Inline copies on one page get prefixed SVG ids. The Campaign Intelligence textures are
  embedded image data: where the back office's Content Security Policy refuses them, the module adapts
  the delivery and never weakens the policy. A standalone logo has alternative text; a logo next to a
  heading that names the product is hidden from assistive technology.

### 10.6 Colours

**[D]** (owner, 2026-11-20, Linear CON-377) Every Smaily product uses the Campaign Intelligence
palette. The module's own components use these colours:

| Use | Colour |
|---|---|
| Primary (buttons, active states), with white text | `#5E3B72` (dark violet) |
| Small brand text | `#4A2D5C` |
| Tint (backgrounds of brand areas) | `#EFE5FB`, with the border `#E2D3F6` |
| Dark brand surfaces | `#2A1028` (plum) |
| Small accent only | `#E91E63` (magenta) |

Only the module's own components change colour. The back office keeps its native look: the page frame,
the toolbar and PrestaShop's own controls stay as PrestaShop draws them. The full palette, dark mode
included, is in Linear CON-377; Story 3.1 reads it there.

### 10.7 New-version notice

**[D]** (owner, 2026-11-20, Linear CON-380) The module pages show a notice when a newer release of
the module is out, with a link to the release page and to its zip, and a button that dismisses the
notice. A merchant who installed the module from a GitHub zip gets no update notification from
PrestaShop (`docs/MARKETPLACE.md`), so the module brings its own. The backend is built (section
11.5): the admin reads `UpdateNotice::current()` and calls `UpdateNotice::dismiss($version)`. The
shell of every module page shows the notice, with "Download", "What's new" and a button that
dismisses it (`Admin\Api\DismissUpdate`); it is held back while the upgrade is not finished. The
module never downloads or installs a release: the merchant does that.

### 10.8 The Log (as built in Story 3.4)

The Log is the tab "Log" of the Smaily Connect page. It reads the event queue, the ingest queue and the
job table (`Log\LogRows`; no table of its own, section 2.2) and follows Magento's Log
(`magento:Controller/Adminhtml/Log/*`, `Model/Log/*`, `view/adminhtml/templates/log/*.phtml`).

**The list.** The columns are Magento's, in its order: a checkbox, Source, Type, Entity, Status,
Attempts, Last Error, Created, Updated, Actions. The rows are newest first by creation, 20 to a page.
A filter sits under each heading that has one: Source, Type, Entity, Status, Last Error, and a day
range for Created and for Updated, in the shop's time zone. The page's address carries the filters
(`source`, `type`, `entity`, `status`, `last_error`, `created_from`, `created_to`, `updated_from`,
`updated_to`, `page`), so the Dashboard links to `status=failed`. No parameter is named `error` or
`conf`: PrestaShop's back office reads those two in every page address as its own (CLAUDE.md,
"Platform traps"). The shops are those of PrestaShop's shop selector: one shop, the shops of a
group, or all. An event row belongs to its shop, an ingest row to its connection's shop, an import
job to its shop, and a job of shop 0 (every shop) shows in every selection.

- **Source.** "Smaily" for an event row and for an import job without a connection; "Campaign
  Intelligence" for an ingest row and for a job that names a connection. No ingest row exists before
  stage 5, so the list shows "Smaily" only until then.
- **Status.** The stored status under Magento's names: `pending` → Pending, `processing` → Sending,
  `sent` → Sent, `failed` → Failed, `skipped` → Skipped. An abandoned-cart reminder that was closed
  because the shopper ordered (`skipped` with the reason `ordered`) shows as Withdrawn
  (`Log\LogStatus`). An import job shows Queued, Running, Done, Failed or Cancelled.
- **Entity.** `Admin\Dashboard\EntityLabel`, the Dashboard's rule: the entity as stored, a keyed hash
  cut to 12 characters. An import job shows its number of contacts.
- **Last Error.** The stored reason without its class prefix. Every reason code that a handler or
  an import can store shows as a sentence in the admin's language, and the filter finds a row by
  those words (`Log\FailureMessage`): Magento's sentence for the four skip reasons that Magento
  words (`no_marketing_consent`, `unsubscribed_in_smaily`, `recently_reminded`, `no_workflow`), and
  a sentence of the same form for the others (`contact_sync_off`, `not_in_audience`, `no_account`,
  `no_credentials`, `automation_off`, `unknown_trigger`, `too_old`, `already_reminded`,
  `not_tracked`, `payload_failed`, `customer_deleted` of section 7, and `stale_subscription` of
  section 6.4, which Magento does not have). The checkout capture's hourly limit (`capture_limit`,
  section 7), whose row stands for no event and was never pending, has a sentence of its own. A
  row with `malformed_payload` failed, and its sentence says so without "Skipped". An import job
  that left contacts out for `no_account` or `no_credentials` has a sentence of its own, as the job
  sent the other contacts. A code without a sentence, and the Smaily client's own message of a
  failure ("Smaily API request failed with HTTP 500"), show as stored.
- **The failed banner** shows the queue rows that were parked as failed in the last 24 hours, in the
  selected shops, and its button applies the filter `status=failed`, as Magento's does.

**Details** open in a side panel (`Admin\Log\Details`, `Admin\Log\LogView`), as Magento's drawer:
the row's id, type and status; Source, Entity, Attempts, Created, Updated; the attempts in order,
as far as the row stores them (`Log\AttemptHistory`); one line that says what happens next; the last
error with its failure class, or the reason of a skip; the payload as sent (`last_request`), or the
queued payload while nothing was sent; the last answer (`last_response`); and "Copy payload". An
import job shows its summary, its progress line and its last error.

A row whose email went without its discount code (section 7) says so in one sentence above the
attempts: "Without a discount code: cart price rule N gave no code for this shop (it was deleted,
is past its end date, starts after the code would end, has no uses left, is limited to one
customer, or is not for this shop). The email goes without one." The row itself reads as sent; the
list shows nothing more.

**[D]** (owner, 2026-11-20) Details show the request and the answer in full to the admin, the
contact's data included, without secrets, as Magento does: the Log is an admin's debugging tool
behind its own permission. `Log\PayloadRedactor` replaces every value under a key that looks like a
secret (password, token, API key, authorization, signature, credential) with `[redacted]`, before
the queue stores an exchange and again when Details show a payload (section 2.2).

**"Send again" and "Retry"** are two actions, as in Magento:

- **Send again** (one row, `Log\Resend`) queues a new `pending` row with the same event type, shop,
  entity, language and payload. The failed row stays untouched as history. The new row's payload
  records the decision under the reserved key `_resend`: `{"of": <failed row's id>, "by": <the
  employee>, "at": <UTC time>}`. Every handler reads only the keys of its own payload, so the record
  never reaches Smaily. The tracker row of an abandoned cart follows the new row, because only the
  queue row that the tracker names sends the reminder.
- **Retry** (the ticked rows, or every row that the filter selects; `Log\Retry`,
  `EventQueue::retry()`) returns failed event rows to `pending` in place, with `attempts` 0. A second
  retry finds the row pending and changes nothing. The answer names the rows queued and the rows
  left alone.

`Log\ResendGuard` decides both, and the list shows "Send again" only on a row that it clears. It
refuses a row that did not fail; a withdrawn reminder; a row whose entity is `[erased]` (section
11.2); a row that a later delivered row of the same event type, shop and contact superseded; and a
row whose event already waits in the queue again as a later `pending` or `processing` row. The
first four answer Magento's sentences. The last one has no sentence: "Send again" then queues
nothing and answers as the first request did, so a second click has no effect. A contact sync
repeats a state and is not superseded by a later sync, with one exception that Magento does not
have: a row that would put a stale subscription into Smaily is held back, with a sentence of its
own ("A later change of this contact's newsletter subscription already reached Smaily; sending this
one again would undo it."). `Contacts\StaleSubscription`
decides that for the guard and for the queue's handler alike (section 6.4), so the automatic retry
and the two manual actions follow one rule. A row that unsubscribes is never held back. A row
without an entity is not compared with later rows; a contact's row always has one (section 2.2).

**Rights.** The page needs the right to read the Log's menu tab. Details need the same right; "Send
again" and "Retry" need the right to change it. Each endpoint also needs the page's token and the
shop selection the page was drawn for (`Admin\Api\AbstractJsonAction`, section 10.1).

**Not built.** "Send again" and "Retry" of ingest rows (Story 5.2 builds that queue); the "Privacy
request" form of section 11.2 (Story 5.6); sorting by another column than Created.

### 10.9 The Automations panel (as built in Story 4.1)

Settings > Automations and step 3 of the Initial setup show one panel
(`views/templates/admin/panel/automations.html.twig`), read and saved by one class,
`Admin\Setup\AutomationsPanel`, as Magento's `panel/automations.phtml` and
`WizardStepSaver::saveAutomations()`. Settings reaches it through `Admin\Settings\TabSaver` and
`Api\Settings\SaveTab` (`tab: automations`), the setup through `Api\Setup\SaveStep`
(`step: automations`).

**The panel.** One card per store automation (Welcome, First order, Abandoned cart) with the
Off/Active pill, "Enabled" and, while the card is on, "Smaily Workflow"; the abandoned cart also has
"Wait (minutes)". A switched-off card hides its settings and keeps their values. In the language
modes `a` and `b` the card "Per-language workflows" follows, one table per automation that is on:
Language, Workflow, Default fallback, and the row "None". In mode `a` a card has no workflow of its
own: it belongs to no language's account. The panel is drawn for the saved mode (section 10.3).

**The discount block** (Story 4.4, section 7) is the last block of a card that is on, as in
Magento: "Send a discount code", and while it is on "Cart price rule" and "Valid for (days)", with
Magento's two notes and a third one that tells the merchant to keep the rule itself disabled or
coded. The picker's list comes from `Admin\Api\DiscountRules` with the workflow lists (one route
per page, the right to read its menu tab; `{"own": bool}` answers `{"rules": [{"id", "name"}]}`):
the cart rules that can be a template in the shop that the panel saves for, and for the values all
shops share the rules that every shop can use. A stored rule that the list no longer offers shows
as "Pick a rule". While the card and the switch are on, a save without a rule, or with days outside
1 to 365, is refused at the field; with either off the block is hidden, and a value at fault is
left unsaved and refuses nothing.

**The workflow lists** come live from Smaily through `Admin\Api\Workflows` (one route per page,
the right to read its menu tab): `{"account": "default"|<language>, "own": bool}` answers the list
of the stored account that the rows of that account key send through
(`Automation\Router::accountFor()`, `Automation\WorkflowList`). Nothing is cached; "Refresh
workflows" asks again. A language of mode `a` without its own account has an empty list.

**What a save writes.** The switches and the delay are the keys of section 2.1; a workflow is a
mapping row (section 2.2) at the scope's shop: the row of no language under the account `default`
for a card, a row per language under `default` (mode `b`) or under the language (mode `a`) for the
table, at most one of them the automation's default fallback. A save replaces only the rows of the
shop's mode; the other mode's rows stay for a switch back (Magento CON-188). A row of a language
that the shop no longer has is removed. The panel reads the rows that the Router reads
(`Router::rowShops()`): a shop's own row before a shared one, and only its own rows for a shop on
another Smaily account.

**What a save checks** (nothing is written while a field is at fault):

- The delay is a whole number from 10 to 1435 while the abandoned cart is on. With the card off
  the field is hidden: a value outside the range is then left unsaved and refuses nothing.
- A newly picked workflow must be in the live list of the account that sends it; one list read per
  account and save. An id that the list does not hold, and any new pick while the list cannot be
  loaded, is a field error.
- A stored workflow stays where the page posts it unchanged, also when the list no longer offers
  it or cannot be loaded; the page shows it as "Workflow #N (not in your Smaily list — kept)". It
  also stays where the page posts none while the list does not offer it (Magento PRO-1286).
  Cleared to "-- Not Selected --" while the list offers it, the row goes.
- An automation switched on without a workflow is saved, as in Magento. It sends nothing: the
  Router skips it as `no_workflow`, which the Log shows.
- While Smaily cannot be reached, the save therefore stands for the switches, the delay and every
  unchanged workflow, as in Magento.

**The 2.x opt-in trigger** (decision 5). The migrated welcome row without a workflow shows in the
Welcome card as "Smaily's opt-in trigger (from 2.x)". It stays while it is posted unchanged and
keeps sending. Once another workflow is saved, the option is gone; no save can pick it again.

**A shop's own automations** (multishop, one store). The switch "Use its own automations on this
shop" follows the Connection and Contacts switches (`ShopOverrides`, section 5.1): ticked, the save
writes the four keys and the rows the page shows at the shop; unticked on a shop that holds own
values, the save only removes them. A shop with its own automations sends what its page shows: a
shared workflow that the page posts unchanged becomes the shop's own row, and a workflow cleared to
"-- Not Selected --" is stored at the shop as a row of "no workflow" where a shared row of that key
exists (`MappingRepository::setNone()`: `workflow_id` NULL, `optin_trigger` 0). The Router reads
that row in place of the shared one, so the shop starts nothing for it (`no_workflow` in the Log),
while the shops on the shared setup still send. It is Magento's rule: a website's own workflow
value `0` hides the store-wide one. The rows inherit key by key, so a shop that an upgrade gave one
own value keeps reading the shared workflows it never replaced. The shared scope holds no such row:
there a cleared workflow is removed. A shop in mode `a`, or on another Smaily account than the
shared one, always keeps its own automations (`SettingsScope::automationsScope()`, Magento CON-190):
its switch shows ticked and fixed, and while it holds no workflow of its own, the page shows "Pick
this shop's workflows". In separate stores the switch is absent and every shop saves its own.

**Not built.** Magento's notice of the languages without a workflow in mode `a`; "Edit design",
transactional emails and the Campaign Intelligence card (out of 3.0.0, or Story 5.4).

---

## 11. Installation report, privacy, retention, uninstall, new-version check

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
| Erasure (psgdpr `actionDeleteGDPRCustomer`) | Deletes local rows that hold the address or its keyed hash; marks tracker rows `erased`; queues §9 per connection as a durable row with retries. Smaily contacts are not deleted: Smaily owns them (sibling rule). Since Story 3.4 a queue row also holds the contact's data in `last_request` and `last_response`, and the Log shows it in full (section 10.8): the erasure must clear the stored payloads and the entity of every queue row of the address that it keeps, as Shopify replaces them with "[erased]". The entity of such a row is `[erased]` (`Log\ResendGuard::ERASED_ENTITY`), which the Log refuses to send again. |
| Profiling opt-out (§10) | My Account "Personalization" page, shown only with a connection; writes the local record first, then queues §10; the newest choice wins; a marketing unsubscribe also stops profiling (Magento parity audit R1–R3). |
| Discount codes (section 7) | Story 5.6 adds them to both requests with `Discount\IssuedCodes`: the export lists the codes of the customer account (`ofCustomer()`) and the codes that the address's automation rows of the event queue still hold (`ofAddress()`, read before the erasure clears those rows); the erasure removes the unused ones (`removeUnused()`). A used code stays with its order. The operations exist and are tested (Story 4.4); nothing calls them yet. |
| Without psgdpr | The same two operations run from the Log screen's "Privacy request" form for an address. |
| New-version check | The module asks GitHub for the latest version once a day and sends no store data (section 11.5). The merchant guide carries this sentence. |

### 11.3 Retention

**[D]** Magento's janitor periods: sent and skipped queue rows 30 days, failed rows 90 days, tracker
rows in a terminal state 30 days, finished jobs 90 days. Engine-side retention follows contract §8;
the user guide carries the privacy-policy template of `woo:docs/DATA_MODEL_GDPR.md`.

As built: the job `queue_janitor` (`Scheduler\QueueJanitor`) runs daily. It deletes `sent` and
`skipped` rows of the event queue 30 days and `failed` rows 90 days after their `updated_at`, in
batches of 1000 by primary key (`EventQueue::deleteClosed()`), until none is left or its deadline
passes. It never deletes a `pending` or `processing` row. It then deletes the finished jobs of the
job table (`done`, `failed`, `cancelled`) 90 days after their `updated_at`, in the same batches
(`JobStore::deleteFinished()`); it never deletes a `queued` or `running` job. The card of the first
import (Settings > Contacts, the Initial setup) reads the newest import job, so it offers "Start
import" again once that job is 90 days old; the daily refresh does not read it. Magento's janitor
does not sweep its job table. Last, it removes the discount codes of the store automations that no
order carries, once their end date is more than 30 days past (`Discount\IssuedCodes`, as the
WooCommerce connector), 200 codes a batch, each through `CartRule::delete()`, until none is left
or its deadline passes; a used code and every cart rule that the merchant made stay. The Story
that builds the ingest queue (5.2) adds its table to the sweep.

The cart tracker (Story 4.3) is written and not swept: its closed rows stay until uninstall. The
30 days above are not applied to it yet, because they meet another rule: the sweep knows that a
cart was reminded from its tracker row alone (section 7), so a cart whose row is gone is due again
when the shopper changes it, and section 3.5 says that a cart that 2.x reminded is never reminded
again. Magento deletes a closed row after 30 days and reminds such a cart again. Which rule holds
is the owner's decision (section 13, "Queued clean-up"). The tracker's own `CartTracker::purge()`
removes only the addresses: it deletes a recorded address that served no reminder, and clears the
address of a closed cart and of a cart whose queued reminder did not go (section 7). The janitor never reads 2.x's `smaily_cart`.

### 11.4 Uninstall

**[D]** (decision 8, 2026-11-20) Uninstall and Reset remove all module data, as 2.x does and as PrestaShop
merchants expect. "Disable" runs nothing (`woo:docs/LESSONS.md` §2.27: the tick, the fallback and the
hooks all stop because the module is not loaded).

As built (Story 1.7), the main class's `uninstall()` calls `Install\Uninstaller::uninstall()` and then
`parent::uninstall()`. The uninstaller:

1. Runs every listener of `Uninstaller::BEFORE_REMOVAL`, while every key and table still exists. A
   listener that throws is logged by its error class and stops nothing. Stories 5.1 and 6.1 register
   the engine disconnect (§16 per connection, one 3 s attempt each) and the `deactivated` report
   there; no listener exists yet.
2. Removes the 2.x override file under the rule of section 2.4.
3. Deletes every key of `Uninstaller::KEYS` at every scope with `deleteByName()`. The list is
   explicit, legacy keys included, because the legacy prefix `SMAILY_` also matches the new one. A
   unit test (`tests/Unit/Install/UninstallerTest.php`) fails when a `SMAILY_CONNECT_*` key that the
   module code names is missing from the list. **[D]** Every Story that adds a key adds it to
   `Uninstaller::KEYS` in the same commit.
4. Drops every table of `Install\Schema` and the legacy `smaily_cart`.

Demonstrated on 8.0.0, 8.2.8 and 9.2.0: a console uninstall leaves 0 module keys and 0 module tables,
after a fresh install and after an upgrade.

The discount codes that the module issued (section 7) are PrestaShop's cart rules and stay after an
uninstall, as the Magento and WooCommerce connectors leave theirs: shoppers hold them in emails
that were sent. Nothing removes the unused ones afterwards; the merchant deletes them in Catalog >
Discounts, where their names start with "Smaily Connect".

### 11.5 New-version check

**[D]** (owner, 2026-11-20, Linear CON-380: "GitHub now") The module learns the latest version from
its official GitHub repository. A notice that Smaily writes and delivers with the answer to the
installation report can come later; the subscription team was asked (Linear SUB-28).

As built (`src/src/Update/`, `Config\UpdateSettings`, `UpdateProvider`):

- **The job.** `update_check` runs daily (86 400 s) with a 10 s budget. It calls
  `LatestRelease::fetch()` and records the answer with `UpdateNotice::record()`. A failed check (no
  answer, an HTTP status other than 200, an unusable release) throws to the tick, which logs one line
  with the error class; the stored state stays. The tick records a job's run before the job starts, so
  a failed check waits a day like a successful one.
- **The request.** `GET https://api.github.com/repos/sendsmaily/smaily-prestashop-module/releases/latest`
  with the fixed User-Agent `SmailyForPrestaShop/<module version>` and `Accept:
  application/vnd.github+json`. It carries no store data: no shop address or name, no account, no
  cookie. The request may take 5 s as a whole (`timeout` and `max_duration`), so a slow GitHub does not
  hold the tick. GitHub's "latest release" is the newest release that is neither a draft nor a
  pre-release.
- **Validation.** The answer is taken whole or not at all. Its tag must be a plain version number
  (`3.0.1` or `v3.0.1`; not a release candidate). The release page and the zip URL must point into the
  official repository on `https://github.com/sendsmaily/smaily-prestashop-module/` or
  `https://api.github.com/repos/sendsmaily/smaily-prestashop-module/`, with no `..`, query, fragment,
  port or credentials (`LatestRelease::isOfficialUrl()`). The zip URL is the first `.zip` asset, else
  the release page.
- **The state** (`SMAILY_CONNECT_UPDATE_CHECK`, global): JSON
  `{"version": "3.0.1", "download_url": "…", "release_url": "…", "checked_at": <Unix time>,
  "dismissed": "3.0.1" | null}`. A check keeps the dismissal.
- **The admin's calls.** `UpdateNotice::current(): ?array` returns `{"version", "download_url",
  "release_url"}` only while the known release is newer than the installed module and newer than the
  dismissed version, and its URLs pass the validation again; it reads the state from the configuration
  that the request loaded (no HTTP call, no query). `UpdateNotice::dismiss(string $version)` closes the
  notice for that version and every older one, for every admin; a later release shows again. Both are
  reached through `Services::instance()->get(UpdateNotice::class)`.
- **Never.** The module never downloads or installs a release.

**Privacy text** (section 11.2 and the merchant guide): "The module asks GitHub for the latest version
once a day and sends no store data."

---

## 12. Testing and tooling

| Layer | What | Where |
|---|---|---|
| Unit | Pure logic: legacy mapper (every fixture), consent policy, audience, router, payload builders, retry ladder, failure classes, tick scheduling, report builder. At least one case per behaviour runs on the shipped defaults (`woo:docs/LESSONS.md` §2.21). | `tests/Unit/<Area>/` |
| Integration | PHPUnit inside the docker store against its real database: install, upgrade from each fixture in `tests/fixtures/legacy/` (2.x, 1.6.x leftovers, 1.6.x code, multishop), hooks → queue rows, tick, flush against mocks. A test seeds its own state and never relies on suite order (`woo:` §2.25). The dev shop does not install the module by itself, and the flashlight image restores its demo database whenever the shop container is recreated (`docs/DEV_ENVIRONMENT.md` sections 4 and 8), so the integration base installs the module and loads its fixture itself. | `tests/Integration/<Area>/` |
| Mocks | The Smaily API mock: `tests/Integration/Fixtures/SmailyMockServer.php` starts PHP's built-in server on `mock-smaily/router.php`. It answers the contact upsert, the autoresponder, the workflow list (`workflows.php`, by `trigger_type`), the unsubscriber list (`contact.php?list=2`, paged by page number) and the action log (`history.php`). Its transport sends `https://<subdomain>.sendsmaily.net` to the mock. **Known gap:** that transport passes any other host on to the real HTTP client, so a future test that runs the whole tick with it could reach a real outside service (clean-up item: make the transport refuse other hosts). The GitHub mock: `GithubMockServer.php` + `mock-github/router.php` stand in for `https://api.github.com` (section 11.5). The engine mock is to be ported from `woo:tests/Integration/Fixtures/RecEngineMockServer.php` + `mock-rec-engine/router.php` (Story 5.1). `subscription.smaily.com` resolves to 127.0.0.1 in the sandbox (Magento rule). | `tests/Integration/Fixtures/` A workflow of the mock's state can name a `subdomain`: it is then listed only to that account, and each recorded request names its `host`. |
| Walks | A walk of the admin screens in a dev shop runs behind the egress guard of `tests/egress-guard/`: the Smaily mock answers every `*.sendsmaily.net` host inside the shop container, and every other host is refused. It is loaded before the first admin request and checked with `check.sh` (`docs/DEV_ENVIRONMENT.md` section 4a). The suite expects a reset shop: it is not green on a shop that was walked by hand or got a second shop. | `tests/egress-guard/` |
| Whole-tick tests | **[D]** A test that runs the whole tick stubs the update check, so no test calls the real GitHub. Two tests pin the tick's full job list in order: `tests/Integration/Scheduler/SchedulerProviderTest.php` and `tests/Integration/Migration/PendingMigrationTest.php`. Every Story that registers a scheduler job edits both. | `tests/Integration/` |
| Dev shop side effects | Page traffic in a dev shop runs the fallback tick, and with it the daily update check against the real GitHub. Some integration test does not restore the cron tokens it found, so after a full suite run the dev shop refuses the token it had before (clean-up item). | — |
| Multistore | The trait `tests/Integration/MultistoreShop.php` turns the test shop into a multistore shop for one test: it switches multishop on, adds shops and shop groups, and puts everything back in `tearDown()`. The added rows carry only what `Shop` and `Configuration` read. | `tests/Integration/` |
| Subprocesses | Concurrency tests start PHP workers next to the test (`Queue/flush-worker.php`, `Migration/migration-worker.php`, `Scheduler/lock-holder.php`). A worker prints with `fwrite(STDOUT, …)`: the shop's bootstrap can buffer what `echo` prints. | `tests/Integration/<Area>/` |
| JavaScript | Planned with Story 5.5: the IIFE first-statement check for every file in `views/js/front/`, and the logic of `consent.js` tested with Node's built-in test runner (no npm packages), in a folder `tests/js/`. No JavaScript test exists today; `views/js/front/` holds one file, `checkout-capture.js`. | — |
| Contract check | Planned: CI compares `docs/RECENGINE_API_CONTRACT.md` with the engine repository's copy (the source of truth) and the installation-report contract with the subscription repository's copy (`gh api`, read token), and fails on any difference. Today `validate.yaml` has no such step, the engine contract copy is compared by hand with `cmp`, and no copy of the report contract is in this repository (Story 6.1 adds it). | — |
| Matrix | PrestaShop 8.0 (PHP 8.0), 8.2 (PHP 8.1), 9.0 and 9.2 (newest PHP each supports), flashlight images as `tests/ci/validate.sh` uses today. `validate.yaml` defines two lanes: 8.2.8 on PHP 8.0 (flashlight) and 9.2.0 on PHP 8.1 (official image); no run exists yet, because the fork's GitHub Actions are not enabled. `tests/ci/validate.sh` installs the release zip, prints 10 checks, and runs the unit and integration tests inside the shop (`docs/DEV_ENVIRONMENT.md`). | CI |
| Last verified counts | The in-shop suite: 1293 tests, 6245 assertions on PrestaShop 8.2.8 and on 9.2.0, each on a fresh shop, with the Automations tab on `v3` (2026-11-21). On the host: 1293 tests, 2012 assertions, 636 skipped. `tests/ci/validate.sh` last ran one commit set earlier, with the Log, the upgrade hardening and the Marketplace structural rules on `v3`: 10 of 10 checks on both flashlight images, with 1266 tests and 6101 assertions in the shop. 8.0.0 last ran at the stage 1 integration commit (278 tests, 1337 assertions). | — |
| Test runner | PHPUnit 9.6, the newest PHPUnit that runs on PHP 8.0, which the lowest lane needs. | `src/composer.json` |
| Static | php-cs-fixer (held at 3.52.1 by `src/composer.lock`; its finder covers `src/` only, not `tests/`), PHP 8.0 and 8.5 lint. PHPStan has a config (`src/phpstan.neon`) and a Composer script, but `src/composer.lock` installs no `phpstan` binary and CI does not run it: PHPStan does not run today. | CI |
| Release | `composer package` (`bin/package.sh`) builds the zip from the files that git tracks under `src/`, minus `src/.zipignore`, with the production `vendor/`; `tests/ci/validate.sh` installs it. `tests/Unit/Install/ConfigXmlTest.php` holds `src/config.xml` equal to the main class. Planned with Story 6.3: a check that the version is equal in the main class and `composer.json`, and an upgrade of the zip on the matrix. | `bin/package.sh`, CI |

---

## 13. Work breakdown

Each Story fits one worker dispatch. "Owns" lists the paths only that Story creates or edits; any
other path is read-only for it. "Parallel group" names Stories that may run at the same time (at most
three). Every Story also owns its tests under `tests/Unit/<Area>/` and `tests/Integration/<Area>/`.

**State on `v3`** (2026-11-21):

| Story | State | Commit areas on `v3` |
|---|---|---|
| 1.1 Skeleton | Done | `module:`, `tests:`, `tooling:` |
| 1.2 Platform facts | Done | `docs:` (the facts file) |
| 1.3 Legacy mapper | Done | `migration:` |
| 1.4 Smaily client, accounts, encryption | Done | `module:`, `tests:` |
| 1.5 Event queue | Done | `queue:` |
| 1.6 Scheduler, tick, cron URLs | Done | `scheduler:`, `queue:` |
| 1.7 Upgrade, install, uninstall | Done | `migration:`, `module:`, `tests:` |
| Stage 1 integration pass | Done | `config:`, `hook:`, `migration:`, `tests:` (the migration as a tick job, the fallback tick before the upgrade, one migration at a time, the 1.6.x tab) |
| Queue deadlock fix | Done | `queue:` (`FORCE INDEX (PRIMARY)`, section 4.3) |
| 2.1 Scope | Done | `scope:`, `config:` |
| 2.2 Consent policy and contact payload | Done, with the owner's consent decisions of 2026-11-20 (section 6.2) | `contacts:`, `queue:`, `config:`, `module:`, `tests:` |
| 1.8 Stage 1 clean-up | Done, except the deferred items below | `scheduler:`, `module:`, `config:`, `tooling:` |
| 2.4 Reconcile, daily refresh, first import | Done, with the owner's consent decisions of 2026-11-20 (section 6.2) | `contacts:`, `module:`, `tests:` |
| New-version check, backend (Linear CON-380) | Done (section 11.5) | `update:` |
| 2.3 Capture hooks | Done (section 6.3), with the enqueue flush (section 4.3) and the module's own newsletter box (section 6.1) | `contacts:`, `tests:` |
| 3.1 Admin shell | Done: the menu, the four pages' shell, the upgrade banner with "Finish upgrade", the notices (section 3.6), the logos and colours, the 8.2.8 translator (section 10.1) | `admin:`, `module:`, `migration:`, `runtime:`, `install:`, `tests:` |
| 3.2 Initial setup and Dashboard | Done for the steps Connect, Contacts and Overview and for the Dashboard. Story 4.1 built the Automations step; the Intelligence step waits for Story 5.3. | `admin:`, `scope:`, `config:`, `module:`, `tests:` |
| 3.3 Settings: Connection, Contacts, RSS, shop switches | Done, with the owner's decisions of 2026-11-20 on the layout change and the confirmations (sections 5.1 and 15). The RSS feed stays the 2.x classes; no `src/src/Rss/` was created (below). | `admin:`, `rss-feed:`, `module:`, `tests:` |
| 3.4 Log | Done (section 10.8), with the stale-subscription rule that the queue's handler shares (section 6.4) | `log:`, `queue:`, `admin:`, `customer-sync:`, `translations:` |
| 3.5 Translation sweep | Not started. The XLIFF holds 220 units: the 2.x units and the units that single commits added, among them the texts of the Log and of the Automations screens. | — |
| 4.1 Automation routing, mapping and the Automations tab | Done (section 10.9) | `automations:`, `automation:`, `admin:`, `tests:`, `docs:` |
| 4.2 Welcome and first order | Done (section 7); the Automations tab of Story 4.1 switches them on and picks their workflows. | `automation:`, `tests:` |
| 4.3 Abandoned cart | Done (section 7): the sweep, the send, the checkout capture, the delay's bounds and the upgrade's cap. Switched on in the Automations tab, as 4.2. | `automation:`, `abandoned-cart:`, `tests:` |
| 4.4 Discount codes in store automations | Done (section 7, "Discount codes as built"); the privacy export and erasure of the codes wait for Story 5.6 | `automation:`, `queue:`, `admin:`, `translations:`, `docs:` |
| New-version notice on the admin pages (CON-380) | Done (section 10.7) | `admin:` |
| Upgrade hardening | Done: the upgrade creates the tick token, and a migration that runs again keeps a store's settings (section 3.7) | `module:`, `migration:` |
| Marketplace technical rules (Linear CON-379) | The structural rules are done (licence notice, `index.php` files, `config.xml`, no superglobal). The rules that need the owner's decision are open (`docs/MARKETPLACE.md` section 6.3). | `tooling:`, `module:`, `abandoned-cart:`, `docs:` |
| Stages 5 and 6 (Stories 5.1 to 6.3) | Not started | — |

**Queued clean-up** (each a separate task):

1. The deferred part of Story 1.8 (below).
2. The Smaily mock's transport refuses every host other than `*.sendsmaily.net` (section 12).
3. The integration test that leaves other cron tokens behind restores the tokens it found (section 12).
4. The queue janitor also sweeps closed rows of `smaily_connect_cart`, once the owner has decided
   whether a cart may be reminded again after its row is gone (section 11.3).
5. `actionCartSave` and `actionObjectCustomerDeleteAfter` are registered and have no listener
   (section 7): either the listeners that the plan named, or the hooks leave `Install\HookList`.

The contract copy `docs/RECENGINE_API_CONTRACT.md` was re-synced three times on 2026-11-20 (`docs:`
commits). It is v1.36.0 now: 1.36.0 adds price groups (§3 `group_prices`, §4 `price_group`), §6c is
switched on at the engine, and the list of synchronized repositories names this repository.

**[D]** The main class (`src/smailyforprestashop.php`). After Story 1.1, only these Stories edit it,
each for its named wiring only, and each in a commit of its own:

| Story | Wiring |
|---|---|
| 1.7 | `uninstall()` delegates to `Install\Uninstaller` (done). |
| 2.3 | The hook method of `actionNewsletterRegistrationBefore` (section 7) (done). |
| 3.1 | The `$tabs` menu, and `getContent()` opens the Dashboard or the Initial setup (done). |
| 5.5 | `WidgetInterface` (section 8.7). |

These Stories never run in parallel with each other.

**[D]** The providers. `Runtime\Services::PROVIDERS` lists one provider per area, and each provider
has one owner:

| Provider | Owner | Note |
|---|---|---|
| `SmailyProvider` | 1.4 | |
| `QueueProvider` | 1.5 | Other Stories register their event handlers under `QueueProvider::handlerId('<type>')` in a provider of their own. |
| `SchedulerProvider` | 1.6 | |
| `MigrationProvider` | 1.7 | |
| `ScopeProvider` | 2.1 | |
| `ContactSyncProvider` | 2.2 | Registers `ContactsSettings`, `SubscriberPayloadBuilder` and `Queue\Handler\ContactSyncHandler` under `QueueProvider::handlerId('contact.sync')`. |
| `ContactsProvider` | 2.3 | The capture listeners (`CustomerCapture`, `NewsletterBlockCapture`, `ContactEvents`) and the module's own newsletter box. |
| `ContactReconcileProvider` | 2.4 | `Reconciler`, `ContactImport` and the jobs `contacts_reconcile`, `contacts_refresh`, `contacts_import`. |
| `AutomationProvider` | 4.1 | Leaves a registration point that 4.2 and 4.3 fill from their own files: `register()` calls the `register(Services)` method of one class per automation, which 4.1 creates empty in `src/src/Automation/Welcome/`, `FirstOrder/` and `AbandonedCart/`, and which 4.2 and 4.3 then own. As built: `AutomationProvider::AUTOMATIONS` lists `WelcomeAutomation`, `FirstOrderAutomation` and `AbandonedCartAutomation`. |
| `EngineProvider` | 5.1 | |
| `StorefrontProvider` | 5.5 | |
| `PrivacyProvider` | 5.6 | |
| `ReportProvider` | 6.1 | |
| `UpdateProvider` | CON-380 | `UpdateSettings`, `UpdateNotice`, `LatestRelease` and the job `update_check`. After `MigrationProvider` in `Services::PROVIDERS`. |
| `AdminProvider` | 3.1 | The line about the upgrade on the other back-office pages (`Admin\BackOfficeNotice`, section 3.6). Last in `Services::PROVIDERS`. |

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
     notice for each (decision 4).
  3. A shop that inherits a global value but never sent the feature gets the feature off at its scope.
  4. An empty legacy token maps to a new-token instruction and a notice.
  5. A `NULL` value maps as off, and a shop row that differs from the global row after an "All shops"
     save keeps the shop's value (section 0). A shop row that holds `NULL` is off for that shop and
     does not inherit the global value (section 2.1).
  6. A 2.x opt-in with no workflow maps to the generic opt-in trigger row (decision 5).

### Story 1.4 — Smaily client, transport, accounts and encryption

- **Goal.** One tested way to call Smaily with stored, encrypted accounts.
- **Owns.** `src/src/{Http,Smaily}/`, `src/src/Config/AccountSettings.php`, `src/src/Runtime/Provider/SmailyProvider.php`, `src/src/Install/Schema.php` (the two `language` columns of section 2.2 only); deletes `src/lib/`.
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
- **Owns.** `src/src/Scheduler/` (including the queue janitor of section 11.3, a scheduled job),
  `src/src/Config/TickSettings.php`, `src/controllers/front/{SmailyCustomerCron,SmailyCartCron,tick}.php`, `src/src/Runtime/Provider/SchedulerProvider.php`.
- **Depends on.** 1.5.
- **Acceptance.**
  1. The two legacy URLs with their tokens and the new tick URL each run the due jobs and answer
     `text/plain`.
  2. An empty or wrong token is refused.
  3. A storefront request with a stale tick returns its page before the tick work starts (measured
     time to last byte unchanged within 50 ms).
  4. Two ticks started at once: the second returns without work.
  5. The tick state records the time and source that the Dashboard needs.
  6. The tick drains the event queue with `Flusher::flush()` until it returns 0 or the budget ends.
  7. The janitor deletes rows older than the retention periods of section 11.3, and no other rows.

### Story 1.7 — Upgrade to 3.0.0, install and uninstall

- **Goal.** A 2.x or 1.6.x store upgrades in place and behaves as before.
- **Owns.** `src/upgrade/upgrade-3.0.0.php`, `src/src/Migration/{LegacyReader,Upgrade300}.php`, `src/src/Install/Uninstaller.php`, `src/overrides/` (removal), `src/src/Config/MigrationNotices.php`, `src/src/Runtime/Provider/MigrationProvider.php`, the main class's `uninstall()` wiring (section 13, "The main class").
- **Depends on.** 1.3, 1.4, 1.5.
- **Design.** The upgrade script never fails (section 3.7): it creates the tables, attempts the
  migration, and returns `true`; the tick and the "Finish upgrade" action run the migration again.
  The migration's configuration writes follow section 2.1.
- **Open point, settled.** The fallback tick's listeners run before the upgrade (section 1.2), and the
  tick job `migration` retries the migration (section 3.7). The admin notice and the "Finish upgrade"
  action came with Story 3.1 (section 3.6).
- **Acceptance.**
  1. Each fixture store upgrades through Module Manager upload on 8.0 and 9.2; the plan's settings,
     accounts, mapping rows and cart rows exist; legacy keys except the two tokens are gone.
  2. A failure injected at each step leaves the legacy keys in place and the runtime inert, and
     `upgrade_module_3_0_0()` still returns `true`. The next tick or the "Finish upgrade" action
     completes the migration.
  3. The plain-text password is absent from `configuration` after the upgrade.
  4. The override file is removed only when its hash is in the 2.x list.
  5. Uninstall removes every key and table of section 11.4.

**Stage 1 parallel groups.** 1.1 alone → {1.3, 1.4, 1.5}, with 1.2 any time → {1.6, 1.7}.

### Story 1.8 — Stage 1 clean-up (before stage 3)

- **Goal.** Close the small defects and the dead code that stage 1 left, with the accepted fixes of
  the stage 1 simplification review.
- **Owns.** The files that each item names.
- **Done.** The three door controllers share `Scheduler\DoorFrontController`; `Runtime\Log::failure()`
  is the one error-class logger; `Config\Settings::{getJson,setJson,reload,storedValue}` are the one
  way to read JSON, to reload the configuration and to read past its cache; `TickSettings::newToken()`
  generates the door tokens; the unused `Installer::uninstall()` is removed; the PSR-4 mapping of the
  deleted `lib/` folder is removed; a tick door that completes the migration answers
  `OK: ran migration.`; `tests/ci/validate.sh` leaves exactly one schema-version row; the docblock of
  `Install\Schema` names what runs before the upgrade.
- **Deferred, still open** (`Upgrade300` is also the code of the admin's "Finish upgrade"):
  1. `Migration\Upgrade300` still has its own configuration reload, its own JSON handling, raw
     configuration SQL, its own token generator, and inline SQL for the mapping rows.
  2. `Migration\LegacyOverride` is built in two places (`MigrationProvider` and the main class's
     `uninstall()`); register it once as a service.
- **Closed by Story 3.3.** The legacy RSS tab of the 2.x admin page passed `null` into the typed
  `RssFeedController::buildRssUrl()` on a fresh 3.0.0 install; that page is gone.
- **Legacy classes: what is gone and what stays.** Removed, because nothing reached them:
  `Controller/CustomerSyncController` and the subscriber classes of `Model/` (`Subscriber`,
  `SubscriberCollection`), which stage 2 replaced; `Controller/AbandonedCartController` and the cart
  classes of `Model/` (`AbandonedCart`, `AbandonedCartCollection`, `AbandonedCartProduct`,
  `SentCart`), which Story 4.3 replaced. The cart classes had stayed only for `LegacyPayloadTest`,
  which now compares the reminder with 2.2.0's payloads as recorded from the 2.2.0 tag
  (`tests/fixtures/legacy/README.md`, "The abandoned-cart payloads of 2.2.0"). Still wired: the RSS
  classes `Controller/RssFeedController` and `Model/RssFeedProductsCollection`, behind
  `controllers/front/SmailyRssFeed.php`; they stay until a Story ports the feed. The 2.x admin page
  (its controller, its forms, its templates and its script) went with Story 3.3. An upgrade by zip
  deletes no file, so a store that had 2.2.0 keeps 2.2.0's copies of the removed files on disk,
  unreachable there too (`docs/MARKETPLACE.md`, rule 33).

### Story 2.1 — Scope: shop layout, languages, account resolution

- **Goal.** One answer to "which shop, language and account" for every event.
- **Owns.** `src/src/Scope/`, `src/src/Runtime/Provider/ScopeProvider.php`, `src/src/Config/Settings.php`
  (adds "remove a shop's own value", section 2.1).
- **Depends on.** 1.4.
- **Acceptance.**
  1. Each rule of section 5.2 has a unit case for one shop, one store with several shops, and separate
     stores.
  2. Mode `a` resolves a language without an account to the fallback account.
  3. `customer_account_scope` reads `global` or `website` from the shop groups.
  4. Removing a shop's own value deletes the shop's row in every shop context, including "All shops",
     and the shop then reads the global value. No write stores `NULL`.

### Story 2.2 — Consent mode policy and contact payload

- **Goal.** One policy object decides who syncs and what a contact row carries.
- **Owns.** `src/src/Contacts/{ContactSyncMode,ContactAudience,SubscriberPayloadBuilder}.php`, `src/src/Config/ContactsSettings.php`, `src/src/Queue/Handler/ContactSyncHandler.php`, `src/src/Runtime/Provider/ContactSyncProvider.php` and its one line in `Runtime\Services::PROVIDERS` (section 13, "The providers").
- **Depends on.** 1.4, 1.5.
- **Acceptance.**
  1. Each cell of the table in 6.2 has a unit case.
  2. `is_unsubscribed` appears only on a consent change.
  3. A contact row carries `store` (shop domain, as 2.x), `language`, and only the selected fields; an
     empty value is left out.
  4. A 203 on a group re-sends each contact alone (Magento PRO-3753).
- **As built.** Section 6.4, with the guest rules of section 6.2 (decisions of 2026-11-20).

### Story 2.3 — Contact capture hooks

- **Goal.** Store changes reach the event queue for the right shop and language.
- **Owns.** `src/src/Contacts/Capture/`, `src/src/Runtime/Provider/ContactsProvider.php`, the entry
  `actionNewsletterRegistrationBefore` in `src/src/Install/HookList.php` and its hook method in the
  main class (section 7; one commit of its own), and the enqueue-time flush (section 4.3, item 4).
- **Note.** With debug mode on, PrestaShop throws when a module registers a hook that its main class
  has no method for (facts file part 6). The hook method and the `HookList` entry land together.
- **Depends on.** 2.1, 2.2.
- **Acceptance.**
  1. Registration, guest checkout, account edit, admin edit, grid toggle and newsletter-block
     subscribe/unsubscribe each enqueue the row of section 6.3 on 8.0 and 9.2. A block unsubscribe of
     a customer and of a guest each enqueue one row. A block subscribe with the verification email on
     enqueues at the verification link, not at the submit. A block submit that fails enqueues
     nothing.
  2. One request that fires two of these hooks enqueues one row.
  3. The own checkout box appears only where `ps_emailsubscription` is inactive and the setting is on.
  4. Every `contact.sync` row carries the payload of section 6.4; a guest-checkout customer's row
     carries `"guest": true`, so the guest gates of section 6.2 apply to the live sync.
- **Done.** As built: section 6.3, with the enqueue flush of section 4.3 and the fix that writes
  the cookie before a response is sent early (facts file 6.8). Walked over HTTP on 8.0.0 and 9.2.0
  against the Smaily mock: registration, guest checkout, account edit, newsletter block and orders.

### Story 2.4 — Reconcile, daily refresh and first import

- **Goal.** Smaily's unsubscribes reach the store, and existing contacts reach Smaily once.
- **Owns.** `src/src/Contacts/{Reconciler.php,Import/}`, `src/src/Runtime/Provider/ContactReconcileProvider.php` and its line in `Runtime\Services::PROVIDERS`.
- **Depends on.** 2.1, 2.2, 1.6.
- **As built.** Section 6.5. The first run of each account takes only unsubscribes, from Smaily's
  unsubscriber list (section 6.2, decision of 2026-11-20).
- **Acceptance.**
  1. In subscribers mode an unsubscribe in Smaily sets `newsletter = 0` and `emailsubscription.active = 0`,
     and a re-subscribe in Smaily sets `newsletter = 1` (section 6.2), within one reconcile run, and
     enqueues nothing.
  2. All-customers and checkout-opt-in modes reconcile nothing.
  3. The first import sends exactly the mode's audience and resumes from its cursor after a stop.
  4. No import row carries `is_unsubscribed = 0`.

**Stage 2 parallel groups.** {2.1, 2.2} → {2.3, 2.4}.

### Story 3.1 — Admin shell

- **Goal.** Menu, routes, base controller, JSON API base and notices that every screen uses. It also
  wires the admin "Finish upgrade" action to `Upgrade300::runPending()` (section 3.7) and renders the
  notices of `Config\MigrationNotices` (section 3.6), with the logos of section 10.5 (Linear GMS-66)
  and the colours of section 10.6 (Linear CON-377).
- **Owns.** `src/config/admin/services.yml`, `src/config/routes.yml`, `src/src/Admin/{BaseController.php,Api/AbstractJsonAction.php}`, `src/views/templates/admin/layout/`, `src/views/js/admin/common.js`, `src/views/css/admin.css`, `src/views/img/` (the logos), the main class's `$tabs` and `getContent()` (section 13, "The main class").
- **Depends on.** 1.1; the logo package of Linear GMS-66 (`Smaily_Connect_and_CI_Logos_Motion_2026-11-05.zip`), which this Story fetches into `src/views/img/` (section 10.5).
- **Acceptance.**
  1. The four menu entries sit under Customers (`AdminParentCustomer`, section 10.1), open on 8.0 and
     9.2 for an admin with module rights, and are refused without them.
  2. A new install redirects every module page to the Initial setup; an upgraded install does not.
  3. Migration notices show on module pages and as one line elsewhere until dismissed.
  4. The layout shows the logos by the rules of section 10.5; under `prefers-reduced-motion: reduce`
     no logo moves.
  5. The module's own components use the colours of section 10.6; the back office keeps its look.
  6. On a store whose migration is pending, the notice offers "Finish upgrade", and the action
     completes the migration.
- **Done.** As built: sections 3.6, 10.1, 10.5 and 10.7. Also `Admin\EntryController` (where
  "Configure" and the parent menu entry lead), `Admin\Menu` (the menu of an upgraded store),
  `Admin\Api\{FinishUpgrade,DismissNotice,DismissUpdate,DismissInvitation}`, the page an employee
  without rights gets, and the invitation for a store without a Smaily account.

### Story 3.2 — Initial setup and Dashboard

- **Goal.** Magento's five-step setup and Dashboard, step by step.
- **Owns.** `src/src/Admin/{Setup,Dashboard}/`, `src/views/templates/admin/{setup,dashboard,panel}/` (4.1 and 5.3 add the templates of the Automations and Intelligence steps), `src/views/js/admin/{setup,dashboard,panels}.js`, `src/src/Admin/Api/Setup/`.
- **Depends on.** 3.1, 2.1, 2.2.
- **Acceptance.**
  1. Step order, step names and wording equal Magento's EN strings.
  2. An upgraded store opens the setup prefilled with its migrated values.
  3. The store-layout question shows first on a multishop install while the layout is unknown.
  4. The Dashboard shows the Background tasks card in its three states.
- **Done.** The setup's steps Connect, Contacts and Overview, with the store-layout question, the
  first contact import and one saver for the setup and Settings (`Admin\Setup\SetupPanels`); the
  Dashboard's verdict, connection cards, tiles and Recent activity (`Admin\Dashboard\`, section
  4.3 for the Background tasks card). The Initial setup was walked in a browser on 8.0.0 and 9.2.0
  against the Smaily mock.
- **Left open.** The setup's Intelligence step (Story 5.3); Story 4.1 built the Automations step.
  The Dashboard's Campaign Intelligence and tracking cards always read "Not connected" and "Disabled",
  and it shows no Catalog items tile, until stage 5.

### Story 3.3 — Settings: Connection, Contacts, RSS, shop switches

- **Goal.** The Settings page with the tabs that exist after Stage 2.
- **Owns.** `src/src/Admin/Settings/`, `src/views/templates/admin/settings/{index,connection,contacts,rss}.html.twig`, `src/views/js/admin/settings.js`, `src/controllers/front/SmailyRssFeed.php`, `src/src/Config/RssSettings.php`. The plan also named a folder `src/src/Rss/` for a port of the feed, which was not created.
- **Depends on.** 3.1, 2.1, 2.2.
- **Acceptance.**
  1. Setup and Settings write through one saver; a value saved in one reads back in the other.
  2. "Use its own … on this shop" writes the shop's scope; unticked, the save deletes the shop's own
     rows through `Config\Settings` (section 2.1) and never writes `NULL`.
  3. The RSS URL with legacy parameters returns the same XML items as 2.2.0 for the same catalog.
  4. The password field never renders the stored password.
- **Done.** `src/src/Admin/Settings/` and `src/src/Admin/Api/Settings/` (section 1.1),
  the three tab templates, `settings.js`, `Config\RssSettings` and `Admin\Settings\RssPanel`; the
  layout change of section 5.1; the 2.x admin page deleted. Walked in a browser on 8.2.8 and 9.2.0
  against the Smaily mock: each tab saves and reads back, the layout changes once in each direction
  on a multishop store, and an employee without rights and a wrong page token are refused.
- **Left open.** The feed is not ported: it stays the 2.x classes `Controller\RssFeedController` and
  `Model\RssFeedProductsCollection`, with its output pinned by
  `tests/Integration/Rss/RssFeedTest.php` (acceptance 3 is met by that test, not by a port).

### Story 3.4 — Log

- **Goal.** One list of everything sent, failed or skipped, with retry.
- **Owns.** `src/src/Log/`, `src/src/Admin/Log/`, `src/views/templates/admin/log/`, `src/views/js/admin/log.js`.
  This includes the "retry failed rows" reset (`Log\Retry`), and the content of the queue columns
  `last_request` and `last_response`: this Story decides what they hold, never a secret, and adds
  the writes.
- **Depends on.** 3.1, 1.5.
- **Acceptance.**
  1. Rows of both queues and the jobs list together, filterable by status, type, shop and entity.
  2. Details show the request as sent and the last response in full to the admin, the contact's
     data included, without secrets, as Magento does (owner, 2026-11-20; section 15). This replaces
     "without secrets or personal data".
  3. Retry of a failed row returns it to `pending` once; mass retry works on a filtered set.
- **Done.** As built: section 10.8, and the queue columns in section 2.2. The Story also wrote the
  Estonian units of its texts (section 10.4 leaves them to the sweep otherwise).
- **Left open.** "Send again" and "Retry" of ingest rows wait for the ingest queue (Story 5.2). A
  reason code that Magento has no sentence for shows as stored.

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
- **Owns.** `src/src/Automation/{Router,MappingRepository,WorkflowMatch}.php`, `src/src/Config/AutomationSettings.php`, `src/views/templates/admin/{settings/automations,setup/automations}.html.twig`, `src/src/Admin/Api/{Workflows,SaveAutomations}.php`, `src/src/Runtime/Provider/AutomationProvider.php` with the registration point for 4.2 and 4.3 (section 13, "The providers").
- **Depends on.** 2.1, 3.2, 3.3.
- **Acceptance.**
  1. Each rule of section 5.2 (workflow row) has a unit case, including a shop on another account.
  2. A saved workflow id is checked against the same account's workflow list. The list is
     `Automation\WorkflowList`: Magento's call `GET workflows.php?trigger_type=form_submitted`, read
     as Magento reads it (`magento:Model/Client/SmailyClient.php`, `getAutomationWorkflows()`): the
     id, the `title` as the name (else `name`, else the id), no workflow that is switched off
     (`is_enabled` false), one list without pages. `POST autoresponder.php` starts only a "form
     submitted" workflow, so the list of `GET autoresponder.php` offers ids that Smaily refuses.
  3. A migrated generic opt-in row shows as "Smaily's opt-in trigger (from 2.x)" until another
     workflow is picked.
- **Done.** As built: section 10.9. The saves go through `Api\Settings\SaveTab` and
  `Api\Setup\SaveStep`, so no `Admin\Api\SaveAutomations` exists. With it: the layout change
  moves the mapping rows as Magento does (section 5.1), and "Test connection" asks Smaily with
  Magento's call, `GET workflows.php?trigger_type=form_submitted`. Walked in a browser on 8.2.8 and
  9.2.0 against the Smaily mock, behind the egress guard.
- **Left open.** In mode `a` the Router ends at the row of no language, which the panel hides and
  Magento never starts (section 14). The Initial setup reaches step 4 only in the browser: a
  reload of an unfinished setup opens the rail up to step 3.

### Story 4.2 — Welcome and first-order automations

- **Goal.** The two contact automations fire once per contact, through the right account.
- **Owns.** `src/src/Automation/{Welcome,FirstOrder}/`, `src/src/Queue/Handler/AutomationHandler.php`.
- **Depends on.** 4.1, 2.3.
- **Acceptance.**
  1. A subscribe fires welcome once; a resubscribe, an admin change or an import does not (Magento
     parity audit R6). With the block's verification email on, the welcome starts from the
     verification link (section 6.3).
  2. A first order fires first-order once per address; the trigger carries its `*_automation_at` field.
  3. Every trigger sends `force_opt_in = false`.
- **As built** (section 7). The welcome starts on a shopper's own consent in the storefront, each
  time: a re-subscribe starts it again, and no once-per-contact record exists (acceptance 1 says
  "once"). The first-order email goes for the first order of a registered customer, never for a
  guest-checkout customer, and only with marketing consent, checked at send time (owner,
  2026-11-20). `Automation\FirstOrder\FirstOrderHandler` wraps `Queue\Handler\AutomationHandler`.

### Story 4.3 — Abandoned cart

- **Goal.** Reminders with the 2.x template fields, never twice for one cart.
- **Owns.** `src/src/Automation/AbandonedCart/`.
- **Depends on.** 4.1, 1.6.
- **Acceptance.**
  1. The payload of a cart equals, field by field, the payload that 2.2.0 sends with every box of its
     field picker ticked, with the 10 slots and `over_10_products`; a cart whose shopper's names are
     not known carries no name field (section 7).
  2. A cart with a migrated `smaily_cart` row is never reminded.
  3. An order withdraws a pending reminder for that address.
  4. The consent gate of decision 3 skips with a reason that the Log shows.
- **Decided (owner, 2026-11-20).** The reminder applies no separate guest rule: who is reminded
  follows the consent mode through `ContactAudience::sendsAutomatedMarketing()` (section 6.2).
- **As built.** Section 7: the sweep with its 24-hour window, the send, and the checkout capture of
  a guest's address (`src/controllers/front/capture.php`, `src/views/js/front/checkout-capture.js`).

### Story 4.4 — Discount codes in store automations

- **Goal.** A store automation can send a discount code: a cart rule that the merchant made, with a
  validity in days, as the WooCommerce and Magento connectors do.
- **Owns.** `src/src/Discount/`, `src/src/Admin/Api/DiscountRules.php`, the discount block of
  `views/templates/admin/panel/automations.html.twig`.
- **Depends on.** The Automations tab (4.1) and the Log page (3.4) on `v3`.
- **As built.** Section 7, "Discount codes as built"; the settings in section 2.1, the panel in
  10.9, the Log's sentence in 10.8, the clean-up in 11.3, the uninstall in 11.4. Walked on 8.2.8
  and 9.2.0 against the Smaily mock, behind the egress guard: the three emails with their codes,
  a code used in a storefront order, refused the second time, after its last day and for another
  customer, the same code on a retry and on "Send again", an email without a code and the Log's
  sentence, and the settings of a second shop.
- **Left open.** The privacy export and erasure call `Discount\IssuedCodes` only once Story 5.6
  wires them (section 11.2). Each event makes a new code; WooCommerce sends an unused working
  code again instead.

**Stage 4 parallel groups.** 4.1 → {4.2, 4.3} → 4.4.

### Story 5.1 — Engine client and connections

- **Goal.** Connect, check and disconnect Campaign Intelligence per connection.
- **Owns.** `src/src/Engine/{Client,Connections,Connection,Setup,Disconnector,HealthCheck}.php`, `src/src/Config/EngineSettings.php`, `src/src/Runtime/Provider/EngineProvider.php`, `docs/RECENGINE_API_CONTRACT.md` (byte copy; replaced only by a new copy from the engine repository).
- **Depends on.** 1.4, 1.6, 2.1.
- **Acceptance.**
  1. A setup link from the mock stores an encrypted key and the endpoints map; a link of another
     Smaily account is refused before anything is stored.
  2. Ping and health check record `connected` / `refused` per connection.
  3. Uninstall sends §16 once per connection with a 3 s limit.
  4. The contract copy equals the engine repository's byte for byte.
  5. The setup exchange declares `connection_prefix`, stores `config.connection.prefix`, and sends
     `connection.previous_prefix` on a reconnect.

### Story 5.2 — Catalog, customers and orders ingest

- **Goal.** Live and import rows reach the engine on the contract's rules.
- **Owns.** `src/src/Engine/{Payload,Ingest}/`, `src/src/Engine/Capture/`.
- **Depends on.** 5.1.
- **Acceptance.**
  1. A product save, a stock change and a price change each produce one catalog row with gross
     prices and the key of section 8.2. The listener deduplicates per product per request: a v2
     product save (7 hook calls) and a combination "Save list" (one call per row) each produce one
     row per product (section 7).
  2. A deleted product produces a §3b removal; the nightly manifest lists only the connection's
     products.
  3. An order's amounts satisfy Σ line_total + shipping ≈ total_amount; a full refund sends
     `refunded`.
  4. Every key carries the connection prefix.
  5. No nightly manifest is sent for a connection that shares its `tenant_id` with another
     connection while either holds the prefix `""` (section 5.3).
  6. The engine `identity_merge` also runs at registration and at guest checkout, from
     `actionCustomerAccountAdd`, because `actionAuthentication` fires on login only (section 7).

### Story 5.3 — History import and the Intelligence tab

- **Goal.** First data load (§25) with progress, and the Intelligence screens.
- **Owns.** `src/src/Engine/History/`, `src/src/Admin/Api/{EngineExchange,EnginePing,BackfillState}.php`, `src/views/templates/admin/{settings/intelligence,setup/intelligence}.html.twig`.
- **Depends on.** 5.2, 3.2, 3.3.
- **Acceptance.**
  1. Orders go newest first: 18 months, then to 60 months; progress calls follow the §25 sender rules.
  2. "Run again" starts a new `import_id`.
  3. The Catalog, Customers and Orders cards show live progress.
  4. The Intelligence tab carries the consent setting of section 9, off by default, with help text
     that states the merchant's responsibility.

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
- **Owns.** `src/src/Storefront/`, `src/controllers/front/{relay,land,recommendations}.php`, `src/views/js/front/`, `src/views/templates/front/recommendations.tpl`, `tests/js/`, `src/src/Runtime/Provider/StorefrontProvider.php`, the main class's `WidgetInterface` (section 13, "The main class").
- **Depends on.** 5.1; decision 2 (section 9).
- **Acceptance.**
  1. Without consent no tracking cookie is set and no event leaves the browser. Consent is the
     store's `consentOverride()` answer where defined, else the merchant-confirmed setting; the
     setting is off on install and upgrade.
  2. A landing with `smaily_rec`/`smaily_ctx`/`smaily_vt` sets the three cookies server-side; the
     next order stores them in `smaily_connect_order_attribution`.
  3. The relay rejects client-sent identity fields and forwards with the server-side key.
  4. The widget renders the same HTML for every visitor; the block fills after `load`.
  5. Every front script passes the IIFE check; the scripts add no global other than
     `window.smailyConnect`.
  6. The `vs_` token links the visitor at login, at registration and at guest checkout (section 7).

### Story 5.6 — Privacy

- **Goal.** Export, erasure and profiling opt-out across store, queues and engine.
- **Owns.** `src/src/Privacy/`, `src/controllers/front/personalization.php`, `src/views/templates/front/personalization.tpl`, `src/src/Runtime/Provider/PrivacyProvider.php`.
- **Depends on.** 5.1.
- **Acceptance.**
  1. A psgdpr export returns local rows and the engine's §8 answer per connection.
  2. A psgdpr erasure removes local rows by address and keyed hash and queues §9 durably.
  3. The opt-out page shows only with a connection; the newest choice wins.
  4. No log line or stored reason contains the remote error text.
  5. An erasure leaves no queue row that the Log can show with the contact's data: the payload, the
     stored request and answer and the entity of every kept row of the address are cleared, and the
     entity reads `[erased]` (sections 10.8 and 11.2).

**Stage 5 parallel groups.** 5.1 → {5.2, 5.5, 5.6} → {5.3, 5.4}.

### Story 6.1 — Installation report

- **Goal.** Smaily's subscription service knows where 3.0.0 runs and how it is.
- **Owns.** `src/src/Report/`, `docs/contracts/installation-report-v1.md` (byte copy), `src/src/Runtime/Provider/ReportProvider.php`.
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
  1. UPGRADING.md lists every row of sections 2.4 and 3.3 in merchant words, and the cron line. It
     tells merchants that fewer abandoned-cart reminders go out than under 2.x (decision 3).
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
| 1 | PrestaShop platform key namespace `ps-` (section 8.2) | The contract names no PrestaShop namespace; under the fail-loud enforcement (PRO-1223) an undeclared pattern sends rows to `errors[]`. Per-combination rows (decided) multiply catalog size for stores with many combinations. | Engine team |
| 2 | PrestaShop in the §25 sender-rules heading | Answered in part by the contract re-sync of 2026-11-20: the list of synchronized copies names this repository. The §25 heading still reads "one list for WooCommerce, Shopify and Magento". | Engine team |
| 3 | Registry rows `connect_prestashop`, `prestashop`, and a shop field in the report | Until they exist the service rejects every report. | Owner → subscription team |
| 4 | Multi-currency orders | A shop that sells in several currencies sends orders in each; the engine assumes one currency per store. | Engine team |
| 5 | Which shop context ran the 2.x crons | The migration assumes the default shop; a store whose crontab named another shop's domain gets that shop's values wrong. | Pilot multishop store (spike in 6.3) |
| 6 | Fallback tick on hosts without `fastcgi_finish_request` | No fallback; the store depends on the cron line. php-fpm has the function (facts file 3.2); LiteSpeed is not verified. | Owner accepts |
| 7 | A hosted pinger (option D), after 3.0.0 | Removes the cron setup for every merchant; needs Smaily infrastructure. | Owner, later |
| 8 | The cookie key changes when a store moves servers | Stored secrets do not decrypt; the merchant reconnects. | Accepted cost |
| 9 | Hook coverage on 8.0 vs 9.x | Answered for 8.2.8 and 9.2.0 (facts file parts 1 and 2; sections 6.3 and 7). A missed hook loses an event silently; row 15 lists what stays unverified. | Story 1.2 (done) |
| 10 | `FrameworkBundleAdminController` and the Marketing parent tab on 9.x | Answered: the controller works and is deprecated since 9.0 (section 1.5); no core Marketing tab exists, so the menu sits under Customers (section 10.1). | Story 1.2 (done) |
| 12 | Merchants who disabled `ps_emailsubscription` emails through 2.x | 3.0.0 cannot restore the `NW_*` values (2.x kept no copy), and `NW_VOUCHER_CODE` is lost. | Accepted; a migration notice says so (2.4), and the upgrade guide of Story 6.2 will |
| 13 | 1.6.x stores lose the 1.6.x newsletter block | Own signup form is out of 3.0.0. | Accepted; a notice says so, and the upgrade guide of Story 6.2 will |
| 14 | Prefix assignment while two connections share one engine store (section 5.3) | Until the engine assigns prefixes, two separate-store shops on one Smaily account send no nightly manifest, so lost delete and stock events heal only on the next change. | Engine team |
| 15 | Platform facts not verified yet (facts file part 5) | 1. PrestaShop 8.0.x at runtime: verified for the in-shop test suite and for the upgrade walks (genuine 2.2.0 and 1.6.2 stores by upload and console, the FTP walk, the injected failure, the uninstall; section 3.7). The hook probes of the facts file did not run on 8.0.0. 2. MySQL: every run used MariaDB, so the queue's `FORCE INDEX` claim, `GET_LOCK` and the upgrade are untested on MySQL. 3. The official image `prestashop/prestashop:9.2.0-8.1`, which the CI lane uses, passed `validate.sh` with the skeleton and the event queue, not with any later code. 4. LiteSpeed (`litespeed_finish_request()`). 5. The 1.6.x hidden-tab removal: the 1.6.2 walks loaded the tab from the fixture, because 1.6.2 itself does not install in debug mode; no real 1.6.2 install ran it. 6. The hooks not exercised: `actionOrderEdited`, `actionObjectProductAddAfter` on the v2 page, `actionObjectCustomerDeleteAfter`, `actionObjectShopDeleteAfter`, `displayCustomerAccount`, the psgdpr hooks. 7. The back office in Estonian was viewed since: `Language::checkAndAddLanguage('et')` adds the language without a language pack, and the module's pages then show the Estonian targets that the XLIFF holds (facts file 6.7). What stays open is the Estonian target of every 3.0.0 string (Story 3.5). 8. Whether an upgraded store keeps the row of the 2.x tab `SmailyForPrestaShopConfiguration` (section 2.4). | The Story that relies on each item verifies it; the matrix (section 12) covers 8.0 |
| 16 | CI on `v3` | The fork's GitHub Actions are not enabled, so `validate.yaml` has never run; the local runs are the only evidence. | Owner (enable once in the fork's Actions tab) |
| 17 | A 2.x store after the upgrade | Answered by Stories 2.3 and 4.3: its crontab runs the tick, which covers the work of both 2.x crons (section 3.4). What a merchant must know (fewer reminders than under 2.x, the 24-hour window, the one cron line) waits for the upgrade guide. | Story 6.2 writes the upgrade guide |
| 18 | The guest gates of the live sync | Answered by Story 2.3: the capture sets the payload's `guest` field, so the live sync applies the decision of 2026-11-20 as the imports do (section 6.2). | Story 2.3 (done) |
| 20 | The unsubscriber list is paged by page number | When the list shrinks between two ticks of one first reconcile run, one address can be skipped; it then stays as the store has it (section 6.5). 2.x had the same exposure. | Accepted; recorded |
| 21 | PrestaShop Marketplace listing | The code follows the Marketplace technical rules from the start (Linear CON-379); the structural rules are met, and ten rules wait for the owner's decision, among them the GPL-3.0 licence, the public RSS feed address, the files of 2.2.0 that the upgrade leaves on disk, the integer casts in SQL and the read of 1.6.x serialized settings (`docs/MARKETPLACE.md` section 6.3). The listing itself is a separate business step. | Owner |
| 23 | A late unsubscribe after a newer subscription | A contact sync that unsubscribes is sent whenever its retry comes due, also after a later subscription of the same contact was delivered (section 6.4). The contact then ends unsubscribed in Smaily although the store's latest choice is subscribed, and in "Subscribers only" the reconcile takes that back to the store. The siblings send it the same way. Skipping such a row as stale would leave more contacts subscribed than today, so it is not done without a decision. | Owner |
| 24 | Mode `a` and the workflow of no language | In mode `a` the Router's last step is the row of no language under the account `default` (section 5.2). Magento's mode A never starts that workflow, and the Automations panel hides it in mode `a` (section 10.9), so a workflow that was picked before the store took an account per language can still start for a contact whose language has no row, through the fallback account. Removing the step sends fewer automations, which is a change of who receives one. | Owner |

Row 11 (the reconcile writes a re-subscribe in Smaily back to the store) and row 19 (the guest rule
and the abandoned-cart reminder) were decided on 2026-11-20, and row 22 (a 2.x cart delay of a day or
more) on 2026-11-21; they moved to section 15.

## 15. Decisions

All eight design-level decisions were taken on 2026-11-20. "Owner" is Erkki; "orchestrator" means
the orchestrator decided under the repository's upgrade-safety rule (CLAUDE.md rule 2).

| # | Question | Decided (2026-11-20) | By | Where | Stories |
|---|---|---|---|---|---|
| 1 | How background tasks run | **DECIDED:** the cron URL plus a fallback tick triggered by visitor traffic (option B). A hosted pinger is not in 3.0.0. | Owner | 4.3 | 1.6 |
| 2 | Cookie consent for tracking and recommendations | **DECIDED:** the merchant-confirmed setting. Off by default; on once the merchant confirms in Settings that the store's cookie banner covers this tracking, and the merchant carries that responsibility. `window.smailyConnect.consentOverride()` stays and, where defined, takes precedence. No consent-cookie rule, no vendor adapters. The owner chose this over the proposed cookie rule. | Owner | 9 | 5.3, 5.5 |
| 3 | Abandoned-cart audience after upgrade | **DECIDED:** the siblings' rule (option B): in subscribers-only mode only contacts with consent, with the Smaily status check before the send. UPGRADING.md tells merchants that fewer reminders go out than under 2.x. | Owner | 6.2, 3.3 | 4.3, 6.2 |
| 4 | Settings left behind by 1.6.x that 2.x never ran | **DECIDED:** switch on only what the running code actually sent, with an admin notice for each thing left off (option A). | Orchestrator | 3.3 | 1.3 |
| 5 | A 2.x opt-in with no workflow chosen | **DECIDED:** keeps firing the generic opt-in trigger for upgraded stores until the merchant picks a workflow (option A). | Orchestrator | 3.3 | 1.3, 4.1 |
| 6 | Product keys | **DECIDED** on the connector side: one row per combination (option A). The key format follows contract 1.35.0: the engine's connection prefix, then the platform key `ps-…`; the namespace `ps-` still needs the engine team (section 14, row 1). | Owner | 8.2 | 5.2 |
| 7 | Campaign Intelligence connections | **DECIDED:** one per separately set-up shop (option A); separate-store shops each connect on their own. | Owner | 5.1, 5.3 | 5.1 |
| 8 | Uninstall | **DECIDED:** removes all module data, after the engine disconnect and the deactivation report (option A). | Orchestrator | 11.4 | 1.7 |

Scope decisions of the same day (owner, 2026-11-20):

- The multishop "separate stores" layout is **in** the first release (section 5).
- The Linear Feature's gauge is the 14 first-release capabilities (CLAUDE.md, STATUS.md).
- Stock alerts (contract §6c) are out of 3.0.0, tracked as Linear CON-371 (section 8.8).

Later decisions of 2026-11-20:

| Question | Decided | By | Where | Stories |
|---|---|---|---|---|
| Does the reconcile write a re-subscribe in Smaily back to the store? | **DECIDED:** yes. In subscribers-only mode the reconcile is two-way: a re-subscribe in Smaily sets `customer.newsletter = 1` in the store. It does not set an inactive newsletter-block row active again. | Owner (Erkki) | 6.2 | 2.4 |
| What the first reconcile of a Smaily account applies | **DECIDED:** "an upgrade never marks anyone subscribed again". The first run takes its unsubscribes from Smaily's current unsubscriber list (`contact.php`, `list=2`, as 2.x did), applies nothing from the action log, reads the log only to set the cursor at its end (the log's end first, then the list), and marks nobody subscribed. From the next run on, the reconcile is two-way. Reason: 2.x never sent newsletter-block unsubscribes to Smaily. The list-based form is the orchestrator's technical refinement of the owner's rule; it also never unsubscribes a contact whom Smaily holds as subscribed now. | Owner (Erkki); orchestrator | 6.2, 6.5 | 2.4 |
| Guest-checkout customers in all customers | **DECIDED:** sent only when "include guests" is on. | Owner (Erkki) | 6.2 | 2.2, 2.4 |
| Guest-checkout customers in subscribers only | **DECIDED:** a guest-checkout customer who ticked the box is sent only when "include guests" is on (WooCommerce's rule). Checkout opt-in only sends a ticked box whatever that setting says (already WooCommerce's rule). Upgraded stores have "include guests" on. | Owner (Erkki) | 6.2 | 2.2, 2.4 |
| How the merchant learns of a new version | **DECIDED:** "GitHub now": the module asks the official GitHub repository for the latest release once a day and shows a notice in the admin. A notice that Smaily writes and delivers with the installation-report answer can be added later (asked of the subscription team, Linear SUB-28). | Owner (Erkki) | 10.7, 11.5 | CON-380 |
| PrestaShop Marketplace rules | **DECIDED:** the code follows the PrestaShop Marketplace technical rules from the start (Linear CON-379). The listing itself is a separate business step (`docs/MARKETPLACE.md`). | Owner (Erkki) | 14 (row 21) | CON-379 |
| A shop whose own Smaily account differs from the default shop's | **DECIDED** under the rule "never widen the audience": contact sync and abandoned cart stay off at that shop after the upgrade, with a notice (`other_account`). The owner may overturn it. | Orchestrator | 3.3 | 1.3 |
| Where consent lives | **DECIDED:** marketing consent lives in PrestaShop's own places (`customer.newsletter`, `emailsubscription`); the module keeps no consent store of its own. `customer.optin` is PrestaShop's "partner offers" checkbox; the module neither creates nor reads it. | Owner | 6.1 | 2.2, 2.3 |
| Colours of the admin screens | **DECIDED:** the Campaign Intelligence palette, for the module's own components only (Linear CON-377). | Owner | 10.6 | 3.1 |
| A contact's address on the admin screens | **DECIDED:** admin screens show a contact's address as the sibling connectors do; only a GDPR erasure removes it. The keyed hash that stands in for an address longer than the entity column shows as its first 12 characters and "…" (`magento:Model/Log/EntityLabel.php`). | Owner (Erkki) | 2.2, 11.2 | 3.2 |
| Separate stores → one store | **DECIDED:** separate stores → one store removes the other shops' own settings, as Magento does: the selected shop's values become the shared ones, and every shop's own Smaily account rows, Contacts values, automation values and mapping rows are removed, whether or not they differ. The contacts confirmation is never copied or removed. | Owner (Erkki) | 5.1 | 3.3 |
| Wording of the confirmations | **DECIDED:** the consent-mode and layout confirmations' wording is approved: the consent-mode confirmations as built, and the change to one store with Magento's text ("website" read as "shop"). | Owner (Erkki) | 10.3 | 3.3 |
| What the Log's Details show | **DECIDED:** The Log's Details show the request and response in full to the admin, without secrets, as Magento does. The queue stores them that way (section 2.2); only passwords, API keys and tokens are never stored and never shown. | Owner (Erkki) | 2.2, 10.8 | 3.4 |
| Discount codes in store automations | **DECIDED:** discount codes in store automations are in 3.0.0, as a Story of their own after the Automations tab and the Log page. "Edit design" stays out. | Owner (Erkki) | 13 | 4.4 |
| How a discount code is issued | **DECIDED:** each email gets one code that is a copy of a cart rule of the merchant, valid N days (1 to 365, default 7), single-use; a registered customer's code is tied to the customer account, a guest's code stays untied; a template may be disabled; an email whose template gives no code still goes, with a notice in the Log; the issued rules show in Catalog > Discounts as "Smaily Connect – <name>"; an unused code goes 30 days after its end date; an uninstall leaves the codes. | Owner (Erkki) | 7 | 4.4 |
| Which fields the abandoned-cart reminder carries | **DECIDED:** the abandoned-cart reminder always carries every field, as the siblings do: every product field in all 10 slots, and the shopper's names when known. No field picker; the 2.x selection is not migrated. | Owner (Erkki) | 7 | 4.3 |
| The bounds of the abandoned-cart delay | **DECIDED:** cart delay 10–1435 minutes, default 30, as Magento. An upgraded store keeps its delay up to 1435 minutes; a longer one is capped (below). | Owner (Erkki) | 2.1, 3.3 | 4.1, 4.3 |
| Does the abandoned-cart reminder apply the guest rule of the live sync? | **DECIDED:** the cart reminder applies no separate guest rule, as the siblings. Who is reminded follows the consent mode through `ContactAudience::sendsAutomatedMarketing()`; "include guests" gates the contact sync only. | Owner (Erkki) | 6.2 | 4.3 |
| The cron line of an upgraded store | **DECIDED:** an upgraded store gets one cron line; the upgrade creates its token. The Background tasks card offers that line in every state behind "Show the cron line", and the notice of a replaced legacy token points at it. The two 2.x cron URLs keep working with their tokens and are not listed. | Owner (Erkki) | 3.4, 3.7, 4.3 | 1.7, 3.2 |

Decisions of 2026-11-21:

- A 2.x cart delay above 1435 minutes is capped at 1435 with a notice (owner, 2026-11-21). The
  upgrade writes 1435 at every scope whose legacy delay was longer and leaves one notice,
  `cart_delay_capped` (section 3.3); a legacy delay of 1435 minutes or less is untouched.
- The checkout capture accepts at most 2000 addresses an hour for the installation, as Magento
  (2026-11-21; section 7, "The checkout capture").
