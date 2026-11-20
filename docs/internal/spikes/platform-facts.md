# Platform facts on PrestaShop 8 and 9 (Story 1.2)

This file answers every "(to verify)" item of `docs/ARCHITECTURE.md` and the extra items of the Story 1.2
brief. Each answer names the PrestaShop version it was observed on and the evidence. Where an answer
contradicts the architecture, the line **Change:** names the section to change.

## How it was observed

| | Shop A | Shop B |
|---|---|---|
| Image | `prestashop/prestashop-flashlight:8.2.8-8.0-nginx` | `prestashop/prestashop-flashlight:9.2.0-nginx` |
| PrestaShop / PHP | 8.2.8 / 8.0.30 (php-fpm, Alpine) | 9.2.0 / 8.5.12 (php-fpm, Alpine 3.23) |
| Database | MariaDB 12.3.3 (`mariadb:lts`) | the same |
| Symfony | `symfony/symfony` 4.4.51 | `symfony/*` 6.4 (`symfony/http-client` 6.4.46) |
| `ps_emailsubscription` | 2.8.2 | 3.0.0 |

Date: 2026-11-20. Own Compose project `smaily-presta-spike`, port 8180, demo database, module 2.2.0 from
`src/` installed with `bin/console prestashop:module install smailyforprestashop`. Two throwaway modules
outside the repository did the probing:

- a probe module that registered 60 hooks and wrote one JSON line per call (hook name, controller class,
  parameter keys, object class and id, `newsletter` value before and after the save);
- an upgrade probe `spikeupg` (1.0.0 → 1.1.0) whose `upgrade/upgrade-1.1.0.php` returns `true`, returns
  `false` or throws, depending on a flag file.

Admin actions ran in a browser against the demo admin; front-office actions ran with `curl` (registration,
login, the newsletter block, a full guest checkout paid with `ps_wirepayment`). Configuration, encryption,
locks and translations ran as PHP scripts that boot `config/config.inc.php`. All addresses and names were
invented. Both stacks, their volumes and their network were removed afterwards.

**PrestaShop 8.0.x was not run** (no image on the machine). The 8.0.0 answers below come from the 8.0.0
sources on GitHub (`composer.json`, `composer.lock`, class files) and say so. The matrix of section 12
should still run the install and upgrade tests on 8.0.

## 1. Answers to the "(to verify)" items

### 1.1 Symfony HttpClient on 8.0.0 (section 1.4)

**Confirmed.**

- 8.0.0 (source): `composer.json` requires `symfony/symfony ^4.4`; `composer.lock` pins `symfony/symfony`
  v4.4.44, which contains the HttpClient component, and `symfony/contracts` v1.1.12, which contains
  `Symfony\Contracts\HttpClient\HttpClientInterface`.
- 8.2.8 and 9.2.0 (observed): `class_exists('Symfony\Component\HttpClient\HttpClient')` and
  `interface_exists('Symfony\Contracts\HttpClient\HttpClientInterface')` are both `true`.
- Note for Story 1.4: 8.0 ships the contracts at v1, 9.2 at v3. Type the module's own code against the
  methods that both share (`request()`, `getStatusCode()`, `getContent(false)`, `toArray(false)`).

### 1.2 `FrameworkBundleAdminController` on 9.x (section 1.5)

**Confirmed, with a deprecation.**

- 8.2.8: the class exists, no `@deprecated`. `PrestaShopAdminController` does not exist.
- 9.2.0: the class exists. Its docblock says `@deprecated since 9.0 to be removed in future versions (10+
  at least, …)` in favour of `PrestaShopBundle\Controller\Admin\PrestaShopAdminController`, which exists
  only from 9.0 (it extends Symfony's `AbstractController` and gets its services through
  `getSubscribedServices()`).
- The 2.2.0 configuration page (`ModuleConfigurationController extends FrameworkBundleAdminController`,
  services in `config/admin/services.yml`) answered HTTP 200 with its form on 8.2.8 and on 9.2.0. On 9.2.0
  the log holds `User Deprecated` notices for `FrameworkBundleAdminController::setContainer()`; nothing
  breaks.
- So a module that supports 8.0–9.x keeps `FrameworkBundleAdminController` as the base of
  `Admin\BaseController`, as the decision says. A move to `PrestaShopAdminController` is possible only
  once 8.x is dropped.

### 1.3 Catalog capture on both product pages (section 1.5)

**Confirmed: both pages fire the ObjectModel hooks.** Details that matter for Story 5.2 are in part 2.

- 8.2.8, legacy page (`product_page_v2` switched off in `ps_feature_flag`): a save fired
  `actionObjectProductUpdateAfter` once, `actionProductUpdate` 4 times, `actionObjectCombinationUpdateAfter`
  and `actionProductAttributeUpdate` once per combination, `actionUpdateQuantity` for every stock row.
- 8.2.8 and 9.2.0, v2 page: a save fired `actionObjectProductUpdateAfter`, `actionProductSave` and
  `actionProductUpdate` **7 times each** (one per form section), plus `actionAfterUpdateProductFormHandler`
  once.
- On the 8.2.8 demo database the v2 page was switched on (`ps_feature_flag.product_page_v2` state 1).

### 1.4 `PhpEncryption` and `_NEW_COOKIE_KEY_` (section 2.3)

**Confirmed on 8.2.8 and 9.2.0; the class and the constant exist in the 8.0.0 source.**

- `new PhpEncryption(_NEW_COOKIE_KEY_)` round-trips a value (ciphertext 206 characters for a
  19-character input).
- The key is `parameters.new_cookie_key` of `app/config/parameters.php` (`config/bootstrap.php` defines
  the constant from it). It is not derived from the shop domain: after `PS_SHOP_DOMAIN` and
  `PS_SHOP_DOMAIN_SSL` changed, the same ciphertext still decrypted. A new `parameters.php` (a
  reinstall, or a move that regenerates it) is what breaks decryption.
- With a different key, `decrypt()` returns `false`; it does not throw. "A value that does not decrypt
  reads as no credentials" therefore needs a `false` check, not a `catch`.
- `PhpEncryption` is the class core uses for the customer cookie (`classes/Cookie.php`).

### 1.5 A shop row holding `NULL` (section 3.3)

**It does not fall back.** Observed identically on 8.2.8 and 9.2.0, multishop on, shop 2 in group 1:

| Rows | Read | Result |
|---|---|---|
| global `'G'`, shop 2 `NULL`, after a fresh load | `Configuration::get(K, null, 1, 2)` | `''` |
| the same | `Configuration::hasKey(K, null, null, 2)` | `true` |
| the same | adapter `get(K, null, ShopConstraint::shop(2))` | `''` |
| the same | `get(K, null, 1, 1)` (shop 1, no own row) | `'G'` |
| global `NULL` only | `get(K, null, 1, 2, 'x')` | `''` (not the default `'x'`) |
| global `'G'` only | `hasKey(K, null, null, 2)` / `get(K, null, null, 2)` | `false` / `'G'` |

Why: `Configuration::loadConfiguration()` turns a `NULL` value into `''`, and `hasKey()` is an
`isset()`. So a stored `NULL` row exists for every read after the load.

Two traps inside **one request** (both versions):

- After `updateValue(K, null)` at shop 2, `get()` at shop 2 returned the global `'G'` in the same request
  (the cache then holds a real `null`), but `''` in the next request.
- `updateGlobalValue(K, 'A')`, then `(K, null)`, then `(K, 'B')` in one request left the database row at
  `NULL` while `get()` returned `'B'`. The third write is lost: `hasKey()` is false for the cached `null`,
  and the "insert" branch finds the existing row id and inserts nothing.

**Change:** section 3.3 should state the resolution precisely: a shop row that exists wins even when it
holds `NULL` (read as `''`, i.e. off); the group row, then the global row, apply only when no row exists
at the narrower scope. Section 2.1's "A read resolves shop, then global (PrestaShop's own fallback)" holds
only when the shop has no row; to make a shop inherit again, the row must be deleted, not set to `NULL`.
Stories 1.3 (mapper), 1.7 (migration writes) and 2.1/3.3 (settings writer) are affected; the writer must
not write `NULL` and then a value to the same key in one request.

### 1.6 Zip upload over an installed module (section 3.8)

**Confirmed on 8.2.8 and 9.2.0.** Upload of `spikeupg` 1.1.0 over an installed 1.0.0 through the Module
Manager endpoint `/improve/modules/import`: `upgrade-1.1.0.php` ran (`sapi=fpm-fcgi`), the response was
`{"status":true,"upgraded":true,…}`, `ps_module.version` became `1.1.0`, `active` stayed 1. The code path
is `ModuleManager::install()` → already installed → `ModuleManager::upgrade($name, $zip)`.

CLI equivalent: `php bin/console prestashop:module upgrade <name>` after the files are in place (observed
on both). `prestashop:module install <name>` on an installed module takes the same `install()` →
`upgrade()` path (read from `ModuleManager::install()`, not run).

**What a failing upgrade script does** (observed on both versions, same result through the CLI and the
upload):

| Script | `ps_module.version` after | `active` after | Message |
|---|---|---|---|
| returns `true` | 1.1.0 | 1 | success |
| returns `false` | **1.1.0** | 0 | "Cannot upgrade module … this module has been turned off" (9.2.0 adds "No update has been applied") |
| throws | 1.0.0 | 1 (CLI) / 0 (upload) | the exception message; the new files stay on disk |

Cause (code, identical in 8.0.0, 8.2.8 and 9.2.0): `ModuleManager::upgradeMigration()` calls
`runUpgradeModule()`, which on a `false` result disables the module, and then calls
`Module::upgradeModuleVersion($name, $module->version)` **unconditionally**, so the stored version
becomes the new one anyway. The upgrade is then no longer offered (stored version = file version).

**Change:** section 3.7. "A failure returns `false` … PrestaShop then keeps the old version number and
offers the upgrade again" is wrong on 8.0–9.2: `false` stores the new version and disables the module.
Only a thrown exception keeps the old version (and then the upload path disables the module while the CLI
path does not). The retry has to come from the module itself, for example the existing
`SMAILY_CONNECT_SCHEMA_VERSION` guard plus a way for the admin to finish the migration, since PrestaShop
will not run `upgrade-3.0.0.php` a second time after a `false`. Story 1.7 is affected.

**Change:** section 3.8, row "Files copied by FTP". Opening Module Manager does not run the upgrade on
8.2.8 or 9.2.0 (`ps_module.version` stayed 1.0.0 after the module list loaded). The module appears under
Modules → "Module notifications" / updates as "Upgrade available v1.0.0 → v1.1.0" with an "Upgrade"
button; the upgrade runs when the admin clicks it or runs the CLI. The only callers of
`runUpgradeModule()` are `ModuleManager::upgradeMigration()` and `ModuleDataUpdater::upgrade()`. The
inert-runtime rule of 3.7 therefore covers this case for longer than the table implies.

### 1.7 `ps_cronjobs` finest frequency (section 4.2, option C)

**Confirmed: hourly**, from source only. The module is `PrestaShop/cronjobs` (v1.4.0, latest release). A
task's frequency has the fields `hour`, `day`, `month`, `day_of_week` (`-1` = every); there is no minute
field, and modules that hook `actionCronJob` return the same fields from `getCronFrequency()`. The module
is not installed in either demo image.

### 1.8 Newsletter box on the guest checkout form (section 6.1)

**Confirmed on 8.2.8 and 9.2.0.** The checkout's personal-information step shows `ps_emailsubscription`'s
`newsletter` checkbox to a guest. A guest checkout with the box ticked created the customer with
`is_guest = 1`, `newsletter = 1`; `actionObjectCustomerAddAfter` and `actionCustomerAccountAdd` fired
with controller class `OrderController` and `newCustomer->newsletter = '1'`. Without the box ticked:
`newsletter = 0`.

### 1.9 Admin grid newsletter toggle (section 6.3)

**Confirmed on 8.2.8 and 9.2.0.** A click on the Newsletter switch of the Customers grid
(`/sell/customers/{id}/toggle-newsletter-subscription`) fired `actionObjectCustomerUpdateBefore` (stored
value 0, object value `true`) and `actionObjectCustomerUpdateAfter` (stored value 1). It fired no
`actionCustomerAccountUpdate`. On 8.2.8 it also fired `actionCustomerBeforeUpdateGroup`.

Related, not in the document:

- **8.2.8: the admin customer edit form has no newsletter field** (`CustomerType` has none); saving the
  form fires `actionObjectCustomerUpdateBefore/After` and `actionAfterUpdateCustomerFormHandler` with the
  value unchanged. On 9.2.0 the form has `customer[is_newsletter_subscribed]`; switching it off and saving
  fired the same hooks with the value moving 1 → 0. So on 8.x the grid toggle is the only admin control
  of the consent.
- On 9.2.0, `Context::getContext()->controller` inside a Symfony admin page is a
  `LegacyControllerContext` proxy, not an `AdminController`. Do not detect the back office by its class.

### 1.10 `actionNewsletterRegistrationAfter` and its `action` parameter (section 6.3)

**The parameter is confirmed; the coverage is not.** Observed with the same result on 8.2.8
(`ps_emailsubscription` 2.8.2) and 9.2.0 (3.0.0), through the block's endpoint
`index.php?fc=module&module=ps_emailsubscription&controller=subscription`:

| Shopper action | `…RegistrationBefore` | `…RegistrationAfter` | Stored result |
|---|---|---|---|
| Subscribe, new address | `action = '0'` | `action = '0'` | `emailsubscription` row, `active = 1` |
| Subscribe, address already subscribed | `'0'` | **not fired** | error message |
| Subscribe, verification email on (`NW_VERIFICATION_EMAIL = 1`) | `'0'` | **not fired** | row `active = 0` |
| … then the verification link (`controller=verification&token=…`) | not fired | `action = 0` (int), `hookName = null` | `active = 1` |
| Unsubscribe, guest | `action = '1'` | **not fired** | row **deleted** |
| Subscribe, address of a customer | `'0'` | `'0'` | `customer.newsletter = 1` by raw SQL |
| Unsubscribe, address of a customer | `'1'` | **not fired** | `customer.newsletter = 0` by raw SQL |

- `action` is the raw `$_POST['action']` string (`'0'`/`'1'`); from the verification link it is the int
  constant `0`. `NEWSLETTER_SUBSCRIPTION = 0`, `NEWSLETTER_UNSUBSCRIPTION = 1`.
- `…After` fires only after a successful subscribe; every unsubscribe and every error returns before it.
- The block changes `customer.newsletter` with raw SQL (`registerUser()`, `unregister()`), so no
  `actionObjectCustomer…` hook fires for a customer who subscribes or unsubscribes in the block.
- `ps_emailsubscription` has no unsubscribe link of its own: its front controllers are `subscription` and
  `verification` only. An email-link unsubscribe comes from Smaily, which section 6.2's reconcile covers.

**Change:** sections 6.3 and 7, row `actionNewsletterRegistrationAfter`: "subscribe (`action = 0`) and
unsubscribe (`action = 1`)" is wrong for unsubscribe. Capturing an unsubscribe in the block needs
`actionNewsletterRegistrationBefore` with `action = '1'` (which fires before validation, so the listener
must check the stored state, for example at shutdown), or reconcile only. A customer's block
subscribe/unsubscribe reaches no customer hook. Story 2.3 is affected (and 4.2 for the welcome on
subscribe with verification: it comes from the verification link, not from the block).

### 1.11 Parent tab for the menu (section 10.1)

**No core Marketing entry on either version.**

- 8.2.8: no tab named or classed Marketing; the parents are `SELL`, `IMPROVE`, `CONFIGURE`; the customers
  menu is `AdminParentCustomer` (child of `SELL`).
- 9.2.0 demo: a tab with class name `Marketing` (child of `IMPROVE`, `module` empty) exists, but it is
  created by the installers of `ps_facebook` and `psxmarketingwithgoogle` (both installed in the demo),
  and their uninstallers delete it when it has no children. Core 9.2.0 does not create it.
- `AdminParentCustomer` exists on both.

**Change:** section 10.1: "under the Marketing entry of the side menu where the version has one, else under
Customers" — the Marketing entry depends on installed marketing modules, not on the version. Either place
the menu under `AdminParentCustomer` always, or under `Marketing` when a tab with that class exists (and
accept that another module's uninstall can leave it empty, or that ours must not delete it). Story 3.1 is
affected.

## 2. Section 7 hook map, observed

Both versions gave the same hooks in the same order unless the row says otherwise. "×n" is the number of
calls in one request.

| Event | Hooks that fired | Section 7 |
|---|---|---|
| Customer registration (front) | `additionalCustomerFormFields`, `actionSubmitAccountBefore`, `actionObjectCustomerAddAfter`, `actionCustomerBeforeUpdateGroup`, `actionCartSave`, `actionCustomerAccountAdd` | Confirmed. `actionAuthentication` does **not** fire. |
| Guest checkout, customer step | as registration, controller `OrderController` | Confirmed. `actionAuthentication` does **not** fire. |
| Login | `actionAuthentication` (controller `AuthController`) | Confirmed. |
| Account edit (front, `/identity`) | `actionObjectCustomerUpdateBefore` (stored 1, object 0), `actionObjectCustomerUpdateAfter`, `actionCartSave`, `actionCustomerAccountUpdate` | Confirmed. |
| Admin grid newsletter toggle | `actionObjectCustomerUpdateBefore`, `…UpdateAfter` | Confirmed (1.9). |
| Admin customer form save | `actionObjectCustomerUpdateBefore`, `…UpdateAfter`, `actionCustomerBeforeUpdateGroup`, `actionAfterUpdateCustomerFormHandler` | Confirmed; no newsletter field on 8.2.8 (1.9). |
| Newsletter block | see 1.10 | **Corrected** (1.10). |
| Cart add (front) | `actionCartUpdateQuantityBefore`, `actionObjectCartUpdateAfter`, `actionCartSave` ×2 | Confirmed. |
| Order placed (`ps_wirepayment` validation) | `actionObjectOrderAddAfter`, `actionObjectStockAvailableUpdateAfter` ×2, `actionUpdateQuantity` ×2 (product total and combination, delta −1), `actionValidateOrder`, `actionOrderStatusUpdate`, `actionOrderStatusPostUpdate`, `actionObjectOrderHistoryAddAfter`, `actionObjectOrderUpdateAfter` ×2, `actionValidateOrderAfter` | Confirmed. |
| Order status change (admin) | `actionOrderStatusUpdate`, `actionObjectOrderUpdateAfter` ×4, `actionOrderStatusPostUpdate`, `actionObjectOrderHistoryAddAfter` | Confirmed. |
| Partial refund, 1 item (admin) | `actionObjectStockAvailableUpdateAfter` ×2, `actionUpdateQuantity` ×2 (delta +1, restock), `actionProductCancel` (`action` 2), `actionObjectOrderSlipAddAfter`, `actionOrderSlipAdd` (`productList` keyed by `id_order_detail`) | Confirmed. |
| Product save, v2 page | `actionObjectProductUpdateAfter` ×7, `actionProductSave` ×7, `actionProductUpdate` ×7, `actionObjectStockAvailableUpdateAfter` ×1, `actionAfterUpdateProductFormHandler` | Confirmed; the listener must deduplicate per product per request. |
| Product save, legacy page (8.2.8, flag off) | see 1.3 | Confirmed. |
| Stock change, v2 Stocks tab (+5) | as product save, plus `actionUpdateQuantity` (`delta_quantity` 5) | Confirmed. |
| Combination edit, v2 list "Save list" (one price changed) | `actionObjectCombinationUpdateAfter` **once per row on the page** (8 rows, 1 changed) | Confirmed; expect one marker per product, not per changed combination. No `actionObjectProductUpdateAfter`. |
| Combination stock, v2 list (+3) | `actionObjectCombinationUpdateAfter` ×8, `actionObjectStockAvailableUpdateAfter` ×2, `actionUpdateQuantity` ×2 (combination and product total) | Confirmed. |
| Combination delete, v2 | `actionObjectCombinationDeleteAfter`, `actionUpdateQuantity` (product total) | Confirmed. |
| Specific price add / edit / delete, v2 modal | `actionObjectSpecificPriceAddAfter` / `…UpdateAfter` / `…DeleteAfter`, once each | Confirmed. |
| Category save (admin) | `actionObjectCategoryUpdateAfter`, `actionCategoryUpdate` | Confirmed. |
| Product delete, v2 | `actionObjectProductDeleteAfter`, `actionProductDelete`, `actionProductAttributeDelete` (`id_product_attribute` 0) | Confirmed. |
| Front page request | `moduleRoutes`, `actionFrontControllerSetMedia`, `actionDispatcherAfter` | Confirmed. |

**Change:** section 7, row `actionAuthentication` (engine `identity_merge`, `vs_` token): it fires on login
only. A shopper who registers or checks out as a guest is signed in without it. If the merge must also
happen then, `actionCustomerAccountAdd` has to do it. Stories 5.2 and 5.5 are affected.

**Change:** section 7, row `actionNewsletterRegistrationAfter` (see 1.10). Story 2.3.

Not exercised: `actionOrderEdited`, `actionObjectProductAddAfter` on the v2 page (the "New product" flow),
`actionObjectCustomerDeleteAfter`, `actionObjectShopDeleteAfter`, `displayCustomerAccount`, the psgdpr
hooks.

## 3. Other platform facts from the brief

### 3.1 Configuration API per context (8.2.8 and 9.2.0, identical)

Multishop on, shop 2 in group 1. "Rows" is what the call wrote to `configuration` (`g` = `id_shop_group`,
`s` = `id_shop`).

| Shop context | Call | Rows |
|---|---|---|
| All | `updateValue(K, v)` | `g NULL, s NULL` |
| Group 1 | `updateValue(K, v)` | `g 1, s NULL` |
| Shop 2 | `updateValue(K, v)` | `g 1, s 2` |
| any | `updateGlobalValue(K, v)` | `g NULL, s NULL` |
| All | `updateValue(K, v, false, null, 0)` | `g NULL, s NULL` |
| Group 1 or Shop 2 | `updateValue(K, v, false, null, 0)` | **`g 1, s NULL`** (a group row) |
| All | `updateValue(K, v, false, null, 2)` | `g NULL, s 2` |
| Group 1 or Shop 2 | `updateValue(K, v, false, null, 2)` | `g 1, s 2` |

- An omitted or `null` shop-group id is taken from the context. To write a global row whatever the
  context, use `updateGlobalValue()` (it passes `0, 0`); to write a shop row, pass both
  `id_shop_group` and `id_shop`. This is how section 2.1's "never relies on the admin's shop context" has
  to be implemented.
- `updateValue()` writes even when the value equals the inherited one (it skips only when the same scope
  already holds it). This matches the fixture README's observation.
- `deleteByName(K)` deletes every row of the key at every scope (and its `_lang` rows) and resets the
  cache.
- `deleteFromContext(K)`: in the All context it does **nothing**; in a group context it deletes the group
  row; in a shop context the shop row. `deleteFromGivenContext(K, $group, $shop)` deletes one given row.
- `hasKey(K, $lang, $group, $shop)` checks exactly one scope (no inheritance); `get()` inherits shop →
  group → global as in 1.5.
- Group context (`id_shop_group` set, `id_shop` `NULL`), not observed in the fixture README, is
  observed here: the row above.

### 3.2 Post-response work (section 4.3)

**Confirmed on 8.2.8 and 9.2.0.** `fastcgi_finish_request()` exists under php-fpm (`PHP_SAPI` =
`fpm-fcgi`); it does not exist in the CLI. A shutdown function registered from
`actionFrontControllerSetMedia` called it, then slept 3 s and queried the database: the client received
the whole home page (83 959 bytes in 0.29 s on 8.2.8; 108 956 bytes in 0.09 s on 9.2.0) before the sleep
ended, and `Db::getInstance()->getValue('SELECT 1')` still answered after it. `actionDispatcherAfter` is
the last hook of a front request; at that point the page is still in the output buffer
(`headers_sent()` false, `ob_get_level()` 2), so any hook up to it can register the shutdown function, and
the work runs at shutdown. `max_execution_time` under FPM is 30 s in both images.

### 3.3 `GET_LOCK` through `Db` (section 4.3)

**Confirmed on 8.2.8 and 9.2.0** (`Db` class `DbPDO`, MariaDB 12.3.3): `Db::getInstance()->getValue(
"SELECT GET_LOCK('<db>_smaily_tick', 0)")` returned `1`; `IS_USED_LOCK()` equalled `CONNECTION_ID()`; a
second PHP process got `0` while the first held it; `RELEASE_LOCK()` returned `1`. MySQL was not tried.

### 3.4 Admin translations (section 1.5, 10.4)

**Confirmed on 8.2.8 and 9.2.0**: the 2.2.0 file
`translations/et-EE/ModulesSmailyforprestashopAdmin.et-EE.xlf` loads. With
`Context::getContext()->getTranslatorFromLocale('et-EE')`, `trans('Smaily for PrestaShop', [],
'Modules.Smailyforprestashop.Admin')` returned the Estonian target on both. `isUsingNewTranslationSystem()`
is `true`. `TranslatorLanguageLoader` loads `<module>/translations/<locale>/` files named
`<Domain>.<locale>.xlf` for every active module. The Estonian language itself is not installed in either
demo database, so the back office was not viewed in Estonian.

## 4. Summary of the changes to ARCHITECTURE.md

| Section | It says | Observed | Story |
|---|---|---|---|
| 3.7 | A failing upgrade returns `false`; PrestaShop keeps the old version and offers the upgrade again. | `false` stores the new version and disables the module; only a throw keeps the old version. | 1.7 |
| 3.8 | FTP-copied files are upgraded when an admin opens the module list or the module. | Not run automatically; listed as "Upgrade available" until the admin clicks Upgrade or runs the CLI. | 1.7 |
| 3.3 (and 2.1) | Open question whether a `NULL` shop row falls back; reads resolve shop, then global. | A `NULL` shop row does not fall back (reads `''`); fallback only when no row exists; same-request write after `NULL` is lost. | 1.3, 1.7, 2.1, 3.3 |
| 6.3, 7 | `actionNewsletterRegistrationAfter` covers subscribe (`0`) and unsubscribe (`1`). | It never fires on unsubscribe, on errors, or before email verification; the block's customer changes fire no customer hook. | 2.3, 4.2 |
| 7 | `actionAuthentication` drives the identity merge. | It fires on login only, not on registration or guest checkout. | 5.2, 5.5 |
| 10.1 | Marketing parent "where the version has one". | No core Marketing tab on 8.2.8 or 9.2.0; `Marketing` comes from marketing modules. | 3.1 |

Confirmed without change: 1.4 (HttpClient on 8.0.0), 1.5 (`FrameworkBundleAdminController` on 9.x, now
deprecated; ObjectModel hooks on both product pages), 2.3 (`PhpEncryption` + `_NEW_COOKIE_KEY_`), 4.2
(`ps_cronjobs` hourly), 4.3 (`fastcgi_finish_request`, `GET_LOCK`), 6.1 (guest checkbox), 6.3 (grid
toggle), the catalog rows of section 7.

## 5. Not verified, and what would verify it

- **PrestaShop 8.0.x at runtime.** Run the install, hook and upgrade checks on a flashlight 8.0.x image
  (the CI matrix of section 12).
- **MySQL** (`GET_LOCK`, `NULL` handling): the same probes on a MySQL 5.7/8 container.
- **Hooks not exercised:** `actionOrderEdited`, v2 "New product" (`actionObjectProductAddAfter`),
  `actionObjectCustomerDeleteAfter`, `actionObjectShopDeleteAfter`, `displayCustomerAccount`, psgdpr
  export/erasure hooks. Each needs one admin or front action with the probe module.
- **`prestashop:module install` on an installed module** taking the upgrade path: read from code, not run.
- **Back office in Estonian**: needs the `et` language installed (language pack download).
- **LiteSpeed** (`litespeed_finish_request()`): no LiteSpeed image was available.

## 6. Additions after stage 1

Added on 2026-11-20 after Stories 1.1 to 1.7. Each item says how it is known. "Read from code" means
the PrestaShop sources of the flashlight images `8.0.0-8.0-nginx` and `9.2.0-nginx`; the code is the
same in both unless the item says otherwise.

### 6.1 The configuration cache after a delete

**Read from code** (8.0.0, 9.2.0; `classes/Configuration.php`). `deleteByName()` and
`resetStaticCache()` both set the configuration caches to `null` and `$_initialized` to `false`.
`hasKey()` is an `isset()` on those caches and never loads them, so after either call it is false for
every key. `Configuration::get()` calls `loadConfiguration()` when `$_initialized` is false;
`getGlobalValue()` goes through `get()`. So a module that asks `hasKey()` after a `deleteByName()` of
any key, or after `resetStaticCache()`, must load the configuration first (`Config\Settings::get()`
does; `Migration\Upgrade300` calls `loadConfiguration()` after `resetStaticCache()`).

### 6.2 `PhpEncryption` keeps one engine per process

**Read from code** (8.0.0, 9.2.0; `classes/PhpEncryption.php`). The engine is a `private static
$engine`, and the constructor replaces it: `self::$engine = new $engineClass($hexString)`. A
`new PhpEncryption($otherKey)` therefore changes the key that every later `PhpEncryption` call of the
request uses, the shop's own cookie encryption included. `PhpEncryptionEngine` holds its key per
object. `Smaily\Crypto` builds a `PhpEncryptionEngine` for each call.

### 6.3 Registering a hook without its method, in debug mode

**Read from code** (8.0.0, 9.2.0; `Hook::registerHook()`). When the module class has no method for
the hook (and does not implement `WidgetInterface`), the message "Hook with the name … has been
registered by …, but the corresponding method … has not been defined in the Module class." is thrown
as a `PrestaShopModuleException` while `_PS_MODE_DEV_` is on, and only logged as a warning while it is
off. **Observed** on 8.0.0 and 9.2.0 with debug mode on: the 1.6.2 install stops at `footerBefore` for
this reason (`tests/fixtures/legacy/README.md`). So a hook method lands in the main class no later
than its entry in `Install\HookList`: the migration registers the whole list on every run.

### 6.4 Module overrides are loaded by path

**Read from code** (8.0.0, 9.2.0; `Module::coreLoadModule()`). On each module load, PrestaShop checks
`Tools::file_exists_no_cache(_PS_OVERRIDE_DIR_ . 'modules/<name>/<name>.php')` and, when the file
exists, loads the class `<name>Override` from it. No class index lists a module override, so deleting
the file is enough to stop it.

### 6.5 A zip upload unpacks over the old module folder

**Read from code** (8.0.0, 9.2.0; `src/Core/Module/SourceHandler/ZipSourceHandler.php`). `handle()`
calls `ZipArchive::extractTo()` on the modules folder and removes nothing first. Files of the old
version that the new zip does not contain stay on disk. They run only if code of the new version
names them.

### 6.6 An admin controller's container has no "translator" on 8.2.8

**Observed** on 8.0.0, 8.2.8 and 9.2.0 (flashlight images, debug mode on), with the module's admin
controllers registered as services under `_defaults: autoconfigure: true`
(`config/admin/services/`). `bin/console debug:container <controller>` shows the tags
`controller.service_arguments` and `container.service_subscriber` on 8.2.8 and 9.2.0, and no tag on
8.0.0. With the subscriber tag, the controller's container is a service locator that holds only what
Symfony's `AbstractController::getSubscribedServices()` lists (router, Twig, the authorization checker,
the CSRF token manager and others): it holds no `translator`.

- 8.2.8: `FrameworkBundleAdminController::trans()` and `$this->get('translator')` both read
  `$this->container->get('translator')`, and every page and every worded JSON error answered HTTP 500
  with `ServiceNotFoundException` ("Service "translator" not found … the container inside … is a
  smaller service locator").
- 8.0.0: the same calls work; the controller's service carries no subscriber tag, and its container
  holds `translator`.
- 9.2.0: the same calls work; `FrameworkBundleAdminController::doGet()` asks the locator first and
  the whole container next.

`Context::getContext()->getTranslator()` returns the container's `translator` service in a back-office
request (**read from code**, `classes/Context.php`, 8.0.0 and 8.2.8) and needs nothing from the
controller. So the module's controllers take their translator from the context
(`Admin\BaseController::translator()`), and `Admin\BaseController` and `Admin\Api\AbstractJsonAction`
override `trans()` to use it. A browser proof of an admin Story includes 8.2.8.
