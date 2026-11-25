# CLAUDE.md — Agent Working Guide (Smaily Connect for PrestaShop)

If you are a fresh agent picking up this repo: read this first, then
`STATUS.md` (where we are now, and what the next session opens with), then
`docs/ARCHITECTURE.md` — the **binding design** of 3.0.0; its section 13 is
the Story list, its section 15 the owner's decisions.
`docs/internal/spikes/platform-facts.md` holds the observed behaviour of
PrestaShop 8 and 9; where it and ARCHITECTURE differ, the facts file wins.
`docs/DEV_ENVIRONMENT.md` holds the verified local setup. README gives the
30-second orientation of the module as it ships today (2.2.0).

## Mission

Rebuild the existing module **Smaily for PrestaShop** (2.2.0, PrestaShop
8.0–9.x, PHP >= 8.0) into **Smaily Connect for PrestaShop 3.0.0**, reaching
feature parity with the sibling connectors — WooCommerce 3.25.0
(`../connect-woo/`), Magento 3.0.1 (`../connect-magento/`) and the hosted
Shopify app (`../connect-shopify/`) — while **every store on 2.x upgrades in
place and keeps its settings and behaviour**. GPL-3.0.

Repository: work lands on branch `v3` of the fork
`erkkimarkus/smaily-prestashop-module` (local remote `origin`). The
official repository `sendsmaily/smaily-prestashop-module` (local remote
`upstream`, branch `master`) gets the work at release-candidate time; Erkki
has read-only access there today; Maintain rights are requested at the first
release. Agents never push to `upstream`.

**Public facade:** README, CONTRIBUTING, CHANGELOG, USERGUIDE and `docs/`
are written as finished-product public documentation. Internal process talk
(stages, agents, Linear) lives here and in STATUS.md only.

## Binding rules — every agent, every task

1. **Identity continuity.** The module technical name and folder
   `smailyforprestashop`, the main class `SmailyForPrestaShop` and the
   `module_key` never change. PrestaShop would treat a renamed module as a
   different module, and the merchant's settings would be lost. "Smaily
   Connect" is the `displayName` only.
2. **Upgrade safety.** Legacy state survives the upgrade, or an upgrade
   script (`upgrade/upgrade-3.0.0.php`) migrates it:
   - the `SMAILY_*` configuration keys, including the misspelled
     `SMAILY_SYNCRONIZE_ADDITIONAL` and `SMAILY_CART_SYNCRONIZE_ADDITIONAL`;
   - the table `smaily_cart` — its rows prevent duplicate abandoned-cart
     reminders;
   - the cron front-controller URLs (`SmailyCustomerCron`, `SmailyCartCron`)
     and their tokens (`SMAILY_CUSTOMER_CRON_TOKEN`, `SMAILY_CART_CRON_TOKEN`)
     — merchants configured them in external crontabs;
   - the RSS feed URL (`SmailyRssFeed`) and its parameters — merchants
     embedded them in their Smaily templates.
   An upgrade never widens the synced audience silently: an upgraded store
   starts in the narrowest consent mode that matches its legacy behaviour.
   Uninstall wipes state, so "reinstall to upgrade" is never the path.
3. **UI parity** (Erkki, 2026-11-20). The Dashboard, Initial setup (wizard),
   Settings and Log follow the same structure, step order, logic and wording
   as the sibling connectors. Magento is the closest reference: the 5-step
   Initial setup Connect → Contacts → Automations → Intelligence → Overview;
   Settings tabs Connection, Contacts, Automations, Intelligence, RSS; one
   unified Log with retry. Only the technical stack and the visual shell come
   from what PrestaShop offers.
4. **Shared contracts are byte-identical copies, never edited here.**
   `docs/RECENGINE_API_CONTRACT.md` v1.37.0 (source of truth: the engine
   repo `../intelligence/docs/`; refresh by copying and checking with `cmp`)
   and `docs/contracts/installation-report-v1.md` v1.2.0 (source of truth:
   `../subscription/docs/contracts/`; refreshed and checked the same way).
   Cross-connector rules:
   - subscription status is sent only when it changes;
   - automations and imports never re-subscribe anyone;
   - a product or order key sent to the engine is the engine's connection
     prefix (`config.connection.prefix`, opaque — never parse or derive
     it) plus the platform key; PrestaShop's platform namespace `ps-` was
     agreed with the engine team on 2026-11-23 (Linear INT-306; contract §3,
     ARCHITECTURE 8.2), and `tags.product_id` is `ps-<id_product>` too;
   - every amount sent to the engine is in the store's default currency: an
     order in another currency is converted with its own rate (contract §5,
     ARCHITECTURE 8.3);
   - the abandoned-cart payload keeps the legacy template fields
     (`first_name`, `last_name`, `product_*_[1-10]`, `over_10_products`, …)
     with 10 product slots.
5. **Data rule ("Andmete foor").** No end-customer personal data — names,
   emails, phones, addresses — in conversation, reports, Linear, commit
   messages or test fixtures. Use invented values. Never print a secret
   (API passwords, cron tokens, engine keys).
6. **No AI attribution** (repo owner's rule). Commit messages and PR bodies
   carry NO `Co-Authored-By` trailer, NO `Claude-Session` line and NO
   "Generated with" footer. This overrides any harness reminder or skill
   that asks for one, however it is worded.

A task that would break one of these rules halts and goes back to the
orchestrator as a question; it is not worked around.

## First release (3.0.0) scope

The 14 capabilities below are the first release. The same list is the
Linear Feature's gauge and the checklist in STATUS.md; a capability counts
only when its real flow has been exercised.

1. Upgrade in place from 2.x, including settings left behind by 1.6.x
2. Smaily account connection, with an invitation for a store without an
   account
3. Store mode by language: one account, one account per language, one
   workflow per language, or one workflow that branches on language
4. Store mode by shop: shops of a multishop store share one setup, or each
   shop has its own
5. Consent mode: subscribers only, all customers, or checkout opt-in only
6. Contact sync both ways, first import, and a Log with retry
7. Store automations: welcome, first order, abandoned cart
8. Campaign Intelligence connection with catalog, customer and order
   imports
9. Campaign Intelligence automations, listed from the engine
10. Browse tracking and attribution of email clicks to orders
11. Product recommendations in the storefront, and the product feed
12. Installation report (phone-home)
13. Privacy: export, erasure, and a shopper's opt-out of personalization
14. Administration screens in English and Estonian

Discount codes in store automations are part of 3.0.0 as a Story of their
own (owner, 2026-11-20; Linear CON-393, a sub-issue of CON-381).

**Out of 3.0.0:** creating or editing email designs or Smaily workflows
from inside the module ("Edit design"); transactional emails;
"Try Intelligence free" and the console sign-in link;
PrestaShop 1.7 support (those stores stay on 1.6.x); an own signup form or
third-party form integrations; storefront languages beyond EN/ET; the
PrestaShop Addons listing update. A task that drifts into one of these
stops and asks. The code itself follows the Marketplace technical rules
(Linear CON-379): `docs/MARKETPLACE.md` lists them and where the module
stands.

## Sibling repos (read-only references)

| Path | What it is |
|---|---|
| `../connect-magento/` | Magento 2 connector 3.0.1 — closest reference for the admin screens, the upgrade-in-place path and multi-scope (website/store) handling. |
| `../connect-woo/` | WooCommerce plugin 3.25.0 — richest reference for patterns; `docs/LESSONS.md` and `docs/DECISIONS.md` hold the scars. |
| `../connect-shopify/` | Hosted Shopify app — third parity source. |
| `../intelligence/` | The recommendation engine — owns `RECENGINE_API_CONTRACT.md`. |
| `../subscription/` | Receiver of the installation report — owns installation-report-v1. |

Never edit a sibling from here. A change a sibling needs is a cross-repo
ask (Linear discipline below).

## Platform traps (PrestaShop)

- **No built-in scheduler.** Legacy work runs when a merchant-configured
  cron hits a front-controller URL with its token. Anything periodic in v3
  (queue drain, imports, abandoned-cart detection, installation report)
  needs the same trigger, and the existing URLs must keep working (rule 2).
- **No standard cookie-consent API.** Owner's rule (2026-11-20): browse
  tracking and recommendations are off until the merchant confirms in
  Settings that the store's cookie banner covers them (the merchant carries
  that responsibility); a store's `window.smailyConnect.consentOverride()`,
  where defined, takes precedence. No consent-cookie rule, no vendor
  adapters (ARCHITECTURE section 9). Changing this is a consent one-way
  door.
- **Configuration storage.** An empty value is stored as SQL `NULL`; an
  "All shops" save leaves shop-specific rows in place
  (`tests/fixtures/legacy/README.md`). A shop row that holds `NULL` does
  not fall back to the global value, so removing a shop's value deletes
  its row (ARCHITECTURE 2.1).
- **Configuration cache.** `deleteByName()` of any key, and
  `resetStaticCache()`, drop the loaded configuration; `hasKey()` then reads
  empty in the same request. Read through `Config\Settings`, which reloads;
  after `resetStaticCache()` call `loadConfiguration()` (facts file 6.1).
- **New keys.** Every new `SMAILY_CONNECT_*` key goes into
  `Install\Uninstaller::KEYS` in the same commit; a unit test checks it.
- **Upgrade scripts.** A `false` return stores the new version and disables
  the module for good; `upgrade-3.0.0.php` never fails and only calls
  `Upgrade300::runPending()` (ARCHITECTURE 3.7).
- **Hooks in debug mode.** Registering a hook whose method the main class
  lacks throws while debug mode is on (facts file 6.3). Add the hook method
  and the `Install\HookList` entry in one commit.
- **Admin controllers.** On 8.2.8 the controller's service locator has no
  `translator` (facts file 6.6): word through
  `Admin\BaseController::translator()`. Every admin Story's browser proof
  includes 8.2.8.
- **Menu names.** PrestaShop names a module's tabs with the English `name`
  in every language until a language is installed or updated; a page of the
  module puts its employee's language right (`Admin\Menu::name()`). A new
  tab needs its `wording` and a unit in the XLIFF.
- **Translations.** A string of a template or of a translate call gets its
  Estonian unit in `src/translations/et-EE/` in the same commit (the id is
  the MD5 of the source; the storefront has its own file). A unit test fails
  on a string without a unit (`TranslationCatalogueTest`).
- **Work after the response.** PrestaShop writes the session of a login
  into its cookie when the request ends, after the shutdown functions.
  Send a response early only through `TrafficTick::finishFunction()`,
  which writes the cookie first (facts file 6.8).
- **Database access.** Every database read and write goes through the
  module's gateway, which throws on a failed query; PrestaShop 9 in
  production answers `false` instead (facts file 6.11). The gateway is
  `Database\Sql`; a unit test fails on a direct call of `Db`, and a test
  fails a chosen query with `tests/Integration/Fixtures/FailingQueries.php`
  (ARCHITECTURE 1.6). A failed load of PrestaShop's own configuration
  throws through `Config\Settings` (facts file 6.12).
- **The tick URL's token.** A tick over its URL needs a stored token; the
  Dashboard's Background tasks card (it asks for the cron line: at once
  while the tasks do not run, on "Show the cron line" while they do) or the
  upgrade creates it, and a request to the URL never does
  (ARCHITECTURE 4.3).
- **Queue SQL** names its rows by primary key (`FORCE INDEX (PRIMARY)` on
  the locking updates); no statement locks rows through a secondary index
  (ARCHITECTURE 4.3).
- **A contact's queue rows.** A contact's queue row takes its entity from
  `Queue\ContactEntity::of()`; the resend guard (`Log\ResendGuard`) matches
  rows by shop and entity. A contact change is sent as stored, in any order,
  as the sibling connectors do (owner, 2026-11-21; ARCHITECTURE 2.2, 6.4).
- **One connector never changes a behaviour rule alone** (owner,
  2026-11-21). Where the siblings behave one way, PrestaShop behaves the
  same way; an improvement only one connector would have goes into the
  cross-connector analysis (Linear CON-397) and all change together.
- **Files of earlier releases.** The upgrade deletes the fixed list `Migration\LegacyFiles::FILES`
  from the module's folder (ARCHITECTURE 3.7). The dev shop mounts `src/` as that folder, and the
  suite runs the real upgrade there: no listed path may exist in `src/` (a unit test checks it),
  so never list a path that 3.0.0 ships. A test that builds `Upgrade300` itself gives it a folder
  under the system's temporary folder. 2.x's `views/templates/hook/` is such a folder: the
  storefront templates of 3.0.0 lie in `views/templates/front/`.
- **Marketplace structure.** Every new PHP, Twig, JS and CSS file needs the
  licence notice and every new directory an `index.php`:
  `composer --working-dir src autoindex`, then `header-stamp` (it stamps PHP
  and Twig; JS and CSS by hand). `src/config.xml` is shipped and pinned to
  the main class by a unit test. No code reads a superglobal directly
  (`docs/MARKETPLACE.md`).
- **Multishop × multilanguage scopes** are where upgrade inheritance bugs
  come from. `Configuration` values can be set per shop group, per shop and
  per language; the legacy module mostly wrote global values. Magento had
  three release-candidate bugs of exactly this kind — every upgrade test
  covers a multishop, multilanguage database. With multistore off,
  `Configuration` reads and writes the global row whatever shop a call
  names, while `Shop::getShops()` still lists every row of `shop`: never
  write a value per shop in a loop over the shops (facts file 6.9).
- **Admin cache.** Stale `var/cache` breaks module routes and service
  configuration (CONTRIBUTING.md, "Invalidating cache").
- **The selected shop is one cookie.** Another tab, or a late answer of
  another back-office page, changes it under an open page. Every JSON
  endpoint therefore compares the header `X-Smaily-Scope` with the selection
  it runs in and refuses a difference (ARCHITECTURE 10.1); a test that
  executes an endpoint sets that header (`AbstractJsonAction::pageScope()`).
- **Admin page addresses.** PrestaShop's back office reads the parameters
  `error` and `conf` of every page address as its own message numbers. A
  module page that carries `error=<text>` fails in debug mode ("Undefined
  array key"). Name no parameter of a module page `error` or `conf`; the
  Log's filter is `last_error` (ARCHITECTURE 10.8).
- **Consent lives in PrestaShop.** Marketing consent is `customer.newsletter`
  and `emailsubscription`; the module keeps no consent store of its own.
  `customer.optin` is PrestaShop's "partner offers" box: never create or
  read it (ARCHITECTURE 6.1).
- **Consent rules** (owner, 2026-11-20; ARCHITECTURE 6.2, 15). Contact sync
  runs only where it is enabled and confirmed. The reconcile is two-way in
  "Subscribers only", but the first run of each Smaily account takes only
  unsubscribes (Smaily's unsubscriber list) and marks nobody subscribed: an
  upgrade never marks anyone subscribed again. A guest-checkout customer is
  sent in "All customers", and with a ticked box in "Subscribers only", only
  when "include guests" is on; "Checkout opt-in only" sends a ticked box
  always. A newsletter-block subscriber without an account goes by the
  consent mode alone, whatever "include guests" says, as Magento (owner,
  2026-11-21). All audience questions go through `Contacts\ContactAudience`.
- **Discount codes are cart rules** (ARCHITECTURE 7). The module knows the
  rules it issued by their description (`smaily-connect-issued:`); never
  filter by name. PrestaShop 8 lists a customer's vouchers by the database
  server's clock, so a code starts a day back. Another module's hook on a new
  cart rule can throw (`ps_eventbus` behind the egress guard): the row then
  fails once and is retried, and the issuer removes the half-made rule.
- **Colours and logos.** The module's own components use the Campaign
  Intelligence palette (Linear CON-377) and the Smaily Connect and CI logos
  (Linear GMS-66); the back office keeps its native look (ARCHITECTURE 10.5,
  10.6).
- **The Initial setup's later steps need an account** (owner, 2026-11-25). `Api\Setup\SaveStep`
  saves `contacts`, `automations`, `intelligence` and `finish` only while the scope has a Smaily
  account stored, and the step list opens no later step until step 1's Continue gate is clear: a
  test that posts a later step stores an account first (`SetupPanelsTest`, `ControllerContainerTest`).
- **Admin endpoints are services.** PrestaShop 9 answers HTTP 500 for a route whose controller
  is not listed in `config/admin/services/`; PrestaShop 8 serves it. Add the route and the
  service in one commit (a unit test compares the two), and clear `var/cache` in a dev shop.
- **Privacy** (ARCHITECTURE 11.2). A shopper is an address: the address, its keyed hash
  (`Queue\ContactEntity::key()`) and its customer accounts. Every table that holds a person's
  data joins `Privacy\LocalData` for the export and the erasure. No log line, stored reason or
  screen holds an engine's text: the HTTP status and the error code only. One exception (owner,
  2026-11-23): the names and descriptions of the engine's automations show on Settings >
  Automations as the engine gives them (contract §11); they are never stored. psgdpr 1.4.3, which
  PrestaShop 9.2.0 ships, cannot search or show a customer in its admin screen there (facts file
  6.13): on 9.x it erases by address, and its export is the customer's own page. The Log's
  "Privacy request" form is therefore always shown, also beside psgdpr (owner, 2026-11-23).
- **The storefront** (ARCHITECTURE 8.10, 9). Browse tracking and the recommendations run only with
  the browser's consent rule (`consent.js`: the store's `consentOverride()`, else the merchant's
  setting, which is off until confirmed); the three landing cookies are not gated, and nothing else
  may join them. Every front script is one IIFE and the scripts add one global,
  `window.smailyConnect` (`node --test tests/js/`). A page holds nothing about its visitor: what
  differs per visitor comes after load from `relay`, `land` and `recommendations`. A store-created
  `vs_` token, and a customer sent to Campaign Intelligence at sign-in, need the merchant's setting
  alone, never the customer's `newsletter` (owner, 2026-11-23); a guest-checkout customer's token
  follows the same one rule (`Attribution::marketingConsent()`). The click and the visitor token also wait with the
  cart (`Storefront\CartAttribution`, by the rule of the order's own row) for an order whose
  request has no shopper's cookies; the order takes the row once per request and deletes it. The
  cache is files in its own folder under PrestaShop's cache folder (the uninstall deletes it); an engine answer stays an hour. PrestaShop initialises a front controller once per
  process: a test that renders theme templates shares one (`StorefrontTestCase::themedRequest()`).
  The storefront reads a shop's connection once in a request (`Storefront\ActiveConnection`):
  `Engine\Connections` forgets it on every write, and the integration tests' base at each test's
  start; a test that writes the connection table itself and then asks the storefront calls
  `ActiveConnection::reset()`.
- **Campaign Intelligence keys and prices** (ARCHITECTURE 8.2, 8.3, 8.11). Every product and
  order key comes from `Engine\Payload\Keys`; the namespace literal is its one constant, and a
  unit test fails on a second one in `src/`. A catalog price comes from
  `Product::priceCalculation()` for the visitor group: `Product::getPriceStatic()` needs a cart
  or an employee in the context and takes the customer group of whoever's request runs the tick.
  An order's amounts are in its shop's default currency, the catalog's: PrestaShop's
  `orders.conversion_rate` is units of the order's currency per unit of the default one, so an
  amount is divided by it (`OrderPayloadBuilder::inStoreCurrency()`). An ingest row is a marker: what goes out is read from the store at the flush. All customer
  questions go through `Engine\Ingest\CustomerScope`.
- **Campaign Intelligence automations** (ARCHITECTURE 10.10). The card on Settings > Automations
  lists the engine's catalogue of that moment: never name an automation by its key, and store
  nothing of it. The card is asked of the engine after the page has loaded (a walk waits for it).
  While the catalogue or the configuration cannot be read, nothing is listed and nothing is saved.
  A save sends every row with all eight keys, as Magento, and hands a workflow map that the page
  could not offer back as stored (`Engine\Automations\ConfigRow`). `Engine\Client` reads of a
  refusal the status, the code and the field names of `errors[]`, never a `message`.
- **The installation report** (ARCHITECTURE 11.1) is sent only to
  `https://subscription.smaily.com` (`Report\ReportSender::ENDPOINT`), and no
  test or dev shop may reach it: a test gives the sender a transport, the
  Compose files resolve the host to 127.0.0.1 (a shop made before that is
  recreated; `tests/bootstrap.php` refuses it), and a walk reads the mock
  receiver behind the egress guard. Every Smaily client tells the report's
  health through `Smaily\Client::observe()`; a test that names an observer
  puts the one it found back. A listener that must run before another
  area's uses `Services::listenFirst()`.
- **Campaign Intelligence connections** (ARCHITECTURE 8.9). The engine is
  called at `https://intelligence.smaily.com` only
  (`Engine\Client::isEngineUrl()`), and no test or dev shop may reach it:
  the Compose files resolve the host to 127.0.0.1, as they do the report's
  and `api.github.com` (a shop made before 2026-11-25 is recreated;
  `tests/bootstrap.php` refuses it), and a walk reaches the engine mock
  behind the egress guard. A `docker compose exec` command gets the guard's
  proxy through `-e` flags written out one by one, never through one
  unquoted zsh variable (`docs/DEV_ENVIRONMENT.md` 4a). The connection
  prefix is stored as the engine sent it: PrestaShop strips tags in `pSQL()` without its second
  argument and in every `Configuration` value, so such a value is written
  with `pSQL($value, true)`. The tick's health check pings every stored
  connection: a test that stores one removes it
  (`tests/Integration/Engine/EngineTestCase.php`).
- **A store-layout change and the connections** (owner, 2026-11-23;
  ARCHITECTURE 8.9). `Admin\Settings\LayoutChange` ends with
  `Engine\LayoutConnections`: the connection of the selected or default shop
  is carried into the new layout (`Connections::move()`, the same row and
  key), and every other one is ended through `Engine\Disconnector`. The
  cookie-banner confirmation stays with that shop to separate stores and is
  removed to one store (`EngineSettings::keepConsentFor()`): never copy a
  confirmation to visitors it was not given for.
- **Where a Log row goes** is its Source (`Log\LogRows::sourceOf()`): a row
  of the event queue whose type begins with `engine.` is Campaign
  Intelligence's, though its log id stays `smaily-<id>`. A new job reason
  needs its sentence in `Log\FailureMessage` and its Estonian unit.

## Working mode — orchestrated, autonomous with checkpoints

Sessions run orchestrated (skill `orchestrate`): the orchestrator talks to
Erkki, plans, and hands tasks to background workers; workers write the
repo. Up to 3 workers run in parallel on this machine; a worker that
writes code works in its own git worktree (`.claude/worktrees/`, ignored).

- **Proceed without asking:** implementation, tests, refactors, doc upkeep,
  and reversible technical decisions (record significant ones in `docs/` as
  you go).
- **Queue for Erkki, don't block:** non-urgent strategic questions and
  human-only tasks — collect them in STATUS.md under "Open questions";
  a worker hands them over in its report. Batch them, don't drip.
- **Stop and ask first:** spending money; anything published externally (a
  release, the Addons listing, contact with the Smaily or engine team, a
  push to the official repo); breaking or extending a shared contract; a
  schema change or an upgrade step that can lose merchant data; deleting
  store data; security or consent trade-offs; anything in "Out of 3.0.0".

Workers follow this discipline: minimum code that solves the task; touch
only what the task requires; report unrelated bugs, dead code or style
under FOLLOW-UPS instead of fixing them; match existing style; state
assumptions; return a question instead of guessing on a genuinely
ambiguous task. Workers verify a claim by running it: a "green" in a report
names the command and its counts (tests, assertions, checks).

**The main class** `src/smailyforprestashop.php` changes after Story 1.1
only for the wirings that ARCHITECTURE section 13 names (Stories 1.7, 2.3,
3.1 and 5.5), each in a commit of its own; those Stories never run in
parallel with each other.

## Keeping the docs current — part of every change

If your change makes a doc wrong, your change isn't finished until the doc
is fixed **in the same commit**. STATUS.md and `docs/` are updated in the
same commit that changes reality. A merchant-visible change also updates
CHANGELOG.md, README and the merchant guide site where they describe it,
and both translation languages. New operational gotchas go into this file.

**Merchant guide site (`docs/site/index.html`)** — the merchant user guide,
bilingual (EN/ET), one self-contained page (no build step, no external
resources; open it in a browser), laid out like the Magento guide
(`../connect-magento/docs/site/`) in its section order. A merchant-visible
change — a label, a step, a setting, an error or notice text, an import's
behaviour, a requirement — updates it in the same commit **in both
languages**: every unit is a `data-lang="en"` element followed by its
`data-lang="et"` twin; edit the pair together, and quote admin labels
exactly as the module's catalogue shows them (the source string, and its
unit in `src/translations/et-EE/`). `tests/js/user-guide-site.test.js`
(`node --test tests/js/`) fails on a broken in-page link, a missing twin,
twins whose structure drifted, or a section the admin links to that is
missing. The page lies outside `src/`, so the release zip never holds it.
`USERGUIDE.md` is only a pointer at the site — do not add content there.
**The admin links the site** through `Admin\UserGuide`: `URL` is the
site's address and each `SECTION_*` an id on the page; `UserGuide::link()`
builds a section's address, which the controller passes to the template;
`tests/Unit/Admin/UserGuideTest.php` and the JS check pin
them. Renaming a site id breaks those links: search the repo first.
**Publishing it live** goes to https://smaily.com/connect-presta/ (a new
web root, as the siblings' guides at `/connect-woo/` and
`/connect-magento/`). It waits for the owner's FTP account: the credentials
file will be `~/smaily_ftp_presta` on Erkki's Mac, which agents never
print, copy or commit, and the first upload waits for Erkki's go-ahead.
Until then the page is not published.

## Build / test commands

Verified on 2026-11-20; the full recipe, ports and pitfalls are in
`docs/DEV_ENVIRONMENT.md`.

- Dependencies first (the shop mounts `src/` with its `vendor/`):
  `composer install --working-dir src/`
- Tests are PHPUnit 9.6 on PHP 8.0+. On the host `src/vendor/bin/phpunit`
  runs the unit tests and skips the integration tests. In the dev shop:
  `docker compose exec -u www-data -e PS_ROOT_DIR=/var/www/html prestashop
  php modules/smailyforprestashop/vendor/bin/phpunit --configuration
  /smaily/phpunit.xml.dist` (`composer test` does not work there). Code
  style (CI gate):
  `(cd src && vendor/bin/php-cs-fixer fix --dry-run --using-cache=no)`
  (set `PHP_CS_FIXER_IGNORE_ENV=1` on a PHP newer than it supports).
- PHP syntax (CI gate): CI lints on PHP 8.0 and 8.5; local PHP accepts
  newer syntax — lint under 8.0 in Docker before calling work done.
- Dev shop: put `COMPOSE_PROJECT_NAME` and free ports (`PS_PORT`,
  `PMA_PORT`, `MAILHOG_PORT`, `PS_IMAGE`) in `.env`, then
  `docker compose up -d prestashop`. The shop does **not** install the
  module itself: `docker compose exec -u www-data prestashop php bin/console
  prestashop:module install smailyforprestashop`. Admin at
  `/admin-dev/` (login: CONTRIBUTING.md; never copy it into reports).
- `docker compose stop`/`start` keep the database; recreating the shop
  container (`down` + `up`, a changed image, port or environment) restores
  the demo database and drops the module install. `down -v` is a full
  reset. Stacks with different project names and ports run side by side.
  Colima cannot mount a path under `/private/tmp`.
- Install check on a fresh shop (project `ci`, port 8000; change them with
  `COMPOSE_PROJECT_NAME` and `CI_PORT`): `composer --working-dir src
  package` and `composer install --working-dir src`, then
  `PS_IMAGE=prestashop/prestashop-flashlight:8.2.8-8.0-nginx tests/ci/validate.sh`
  and the same with `9.2.0-nginx`; it also runs the unit and integration
  tests in the shop, which runs with debug mode off (the dev stack runs
  with it on). `KEEP=1` leaves the shop up.
- A test that runs the whole tick must stub the update check, so no test
  calls the real GitHub. Every Story that registers a scheduler job edits
  the two tests that pin the tick's job list (`SchedulerProviderTest`,
  `PendingMigrationTest`).
- Walks and suites run behind `tests/egress-guard`, installed before the
  first request to the module (an admin page or a tick), and the suite needs
  a reset shop (`docs/DEV_ENVIRONMENT.md` 4 and 4a).
- One worker saw the in-shop suite segfault three times on the 8.2.8 lane
  (PHP 8.0.30) on 2026-11-21. It did not reproduce in 113 later runs, and
  the upgrade, the re-run, the tick and the admin pages ran 22 to 42 times
  each without a crash; the cause is unknown. On a next crash keep
  `/var/www/html/core` and the tree (`docs/DEV_ENVIRONMENT.md` 5). A
  multilanguage storefront may redirect at `/` (8.2.8 does), and a
  redirect runs no tick: follow redirects.
- Release zip: `composer --working-dir src package` writes
  `dist/smailyforprestashop.zip`; `release.yaml` attaches it to a GitHub
  release.
- `validate.yaml` runs on pull requests and on pushes to `master` and `v3`,
  once the fork's Actions are enabled (the owner enables them once in the
  Actions tab; until then CI on `v3` is unverified). Read a PR's result
  with `gh pr checks <number> --watch`.

## Commits

Work lands on `v3` (or a worktree branch merged into it). Commit messages
follow `github:writing-change-records` — `area: Imperative summary`, then a
problem paragraph and a solution paragraph wrapped at 72 characters —
**minus the co-author trailer** (rule 6 wins). Areas follow upstream's
style: `module:`, `tooling:`, `rss-feed:`, `customer-sync:`,
`abandoned-cart:`, plus `docs:` and new areas as v3 adds them. Stage files
by explicit path, never `git add -A`. Check with
`git log --format='%h %s%n%(trailers)' upstream/master..HEAD` that no
commit carries a trailer. Agents do not push unless the task says so.

## Language conventions

Repo docs, code, commits and Linear content: English. The orchestrator talks
to Erkki in Estonian. The admin UI ships in English and Estonian
(`src/translations/`).

## Linear discipline (Smaily process bridge)

This repository is engineering truth — STATUS.md and `docs/` stay canonical
here. Linear is the coordination and visibility layer. Full process:
Outline → Processes → "Agent-Driven Development".

Track: Growth · product owner: Erkki

Issues live in the Linear team **Connect** (key `CON`), a sub-team of
**Growth (lab)**. The model is Initiative → Feature (a Linear project) →
Epic (a top-level issue in the project) → Story (a sub-issue of the Epic).
**Workers have no Linear access:** a worker does not load or call any
Linear tool; it puts what it wants posted in the `LINEAR:` section of its
report, and the orchestrator writes it.

1. **Anchor before work.** Work anchors on the initiative *Smaily earns
   revenue beyond email sending* (owner, 2026-11-25: every project moved
   under it, and the other initiatives went away), and on the
   Feature [Smaily Connect for PrestaShop — v3
   rewrite](https://linear.app/smaily/project/smaily-connect-for-prestashop-v3-rewrite-cb6b41ae3cb1)
   (P-CON-324, lead team Connect) — outcome: 3.0.0 with the 14 capabilities
   reaches real stores as an in-place upgrade.

   Work found in a session is filed in team Connect as a Story under the
   Epic whose outcome it serves. Cross-repo asks are filed in the sibling
   projects: WooCommerce "Smaily Connect for WooCommerce — v3 rewrite"
   (P-CON-215), Magento "Smaily Connect for Magento 2 — v3 rewrite"
   (P-CON-216), Shopify "Smaily Connect for Shopify — hosted app"
   (P-CON-214), engine "Campaign Intelligence — recommendation engine"
   (P-INT-217), installation reports "Smaily sees every installation of its
   products" (P-SUB-320).

2. **One-way doors interrupt.** Before any irreversible or expensive-to-undo
   commitment — persistent data schema, public or integration contracts
   (`RECENGINE_API_CONTRACT.md`, installation-report-v1, the engine key
   namespace `ps-`), releases reaching real stores, anything touching
   deliverability or reputation, pricing, or legal/consent — halt, file a
   Linear gate issue in the Feature with the evidence and the proposed
   action, and wait for Erkki's approval. Reversible work proceeds at full
   speed without asking.

3. **Scribe pass at session end.** Before a working session ends, the
   orchestrator distills it into Linear: an honest project status update
   (onTrack/atRisk/offTrack) with the gauge value, new backlog items as
   issues, completed issues closed. Use the `linear-project` skill.

Never duplicate repo documents into Linear — summarize and link. Linear
content is written in English.

## Outcome gauges

- **Smaily Connect for PrestaShop — v3 rewrite** (P-CON-324) — shape:
  trend. Gauge: first-release capabilities demonstrated by exercising the
  real flow, out of 14 (read from the checklist in STATUS.md). Dates: none
  — Erkki 2026-11-20: "no deadline — show progress over time". Values:
  2026-11-20 (start): 0/14; 2026-11-20 (stage 1 done): 1/14; 2026-11-21
  (capabilities 3, 4, 6 and 7 walked): 7/14; 2026-11-23 (capabilities 12 and
  13 walked): 9/14; 2026-11-23 (capabilities 8–11 walked on merged v3): 13/14;
  2026-11-23 (capability 14: native read): 14/14.
