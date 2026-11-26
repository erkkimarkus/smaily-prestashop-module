# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-26. Gauge 14/14 (2026-11-26)._

## Next session opens with

- 26 Nov added: contract 1.37.1; cron URLs answer before the jobs; Smaily calls bounded to 30 s; a MySQL dev stack
  and `validate.sh SHOPS=2`; CON-433 (stale contact changes skipped, privacy requests paced, rejected accounts
  refused, lost workflows dropped, refused import contacts named, the consent-mode warning); the identity-merge
  deferral; CON-435 (a console install enables every shop); Gender, Customer ID and group synced; the Campaign
  Intelligence card and CON-445 parts 1–3 as Magento draws them. Verified together below; the merge left one
  stylesheet rule unclosed, fixed in `0011857`.
- CON-454 part a (owner, 26 Nov; Magento's): the Catalog items tile counts exactly; "Last run" and the Overview's
  last run in the employee's date format; every Initial setup step lays its sections on the page; "Subscription
  type" (`subscription_type` = `Subscriber`, opt-in) as a contact field; a stopped import stores where it stopped
  (`Failure::location()`) and its card shows that in the admin's language.
- Next, in order: the owner's walk of the two test shops with the new zip; the guide (CON-424) once web root, FTP
  and go-ahead exist; the live walk against real Smaily and engine (needs a test account); CON-444's seven
  cross-connector questions (owner, later); the demo store (CON-425); the pilot. Open: Tanel's native read, CI.

**All 14 first-release capabilities are demonstrated against mocks on PrestaShop 8.2.8 and 9.2.0.** That is not a
release: no live service was called, no pilot store has upgraded, and CI has never run ("NOT verified" below).

**The owner's test environment** (workers never touch these stacks): two long-lived local shops behind the egress
guard, mocks only, and a third, live one. `build/testkeskkond/lahtesta.sh a|b` builds one (git-ignored); guide `build/testkeskkond/JUHEND.md`.

- Shop A, Compose project `erkki920`: PrestaShop 9.2.0 at http://localhost:8301/, module 3.0.0 from the main
  checkout's `src/`. It shows new code after its cache is cleared.
- Shop B, Compose project `erkki828`: PrestaShop 8.2.8 at http://127.0.0.1:8302/, a genuine 2.2.0 that the owner
  upgrades by uploading `build/testkeskkond/smailyforprestashop-3.0.0.zip`, **rebuilt on 2026-11-26 from `0011857`**
  (SHA-256 `12d114fd…`; 455 files outside `vendor/`, no `docs/site/`; `src/` is the same at the handoff's commit).
- Live shop, Compose project `erkkilive` (owner, 2026-11-26): PrestaShop 9.2.0 at http://localhost:8303/, module
  3.0.0 from the main checkout's `src/`, debug mode off, EN and ET, shop name "Smaily Connect test (Erkki)". It
  reaches the real Smaily API, engine and installation-report receiver behind the guard's allow-list
  (DEV_ENVIRONMENT 4a); every other host is refused. A side container `tiksur` calls its tick URL every minute.
  `build/testkeskkond/live.sh` builds or starts it; no worker's suite or walk runs there without the owner's request.
- After a reboot: `colima start`, then `build/testkeskkond/kaivita.sh` (it starts the live shop too). The guard's
  certificates last 30 days.

**Last runs** (2026-11-26, the merged tip `7356f4f` with the stylesheet fix `0011857`). Host: `src/vendor/bin/phpunit`
2346 tests, 4157 assertions, 1188 skipped; `node --test tests/js/` 55 pass; PHP CS Fixer 0 of 367 files; PHP 8.0.30
lint of the 106 PHP files changed since `679d70a`; the Estonian XLIFF files valid, no duplicate id. In shops made from
scratch behind the egress guard, debug mode on: 2346 tests on 9.2.0 (12625 assertions) and on 8.2.8 (12620), the
leftovers check silent. `tests/ci/validate.sh` with the zip, debug mode off: 10 of 10 on flashlight 8.2.8 and
9.2.0 (its suite 2346 tests, 12620 and 12625 assertions), with `SHOPS=2` 12 of 12 on both.
Headless browser walk of every admin screen (Dashboard, Initial setup steps 1–5, Settings Connection, Contacts,
Automations, Intelligence and RSS, the Log and its Details), EN and ET, Smaily and engine mocks, on 9.2.0 and 8.2.8,
one store and a store of two shops: 104 of 104 screens without a PHP error, a 5xx, a JS error of the module or an
untranslated module string, each with its cards. PrestaShop's own words stay English without a language pack.

**NOT verified — keep these visible:** the live Smaily service and the live engine have never been called (every
check used a mock, also the installation report's); GitHub Actions on the fork are not enabled, so CI has never run;
MySQL 5.7 (8.0 ran); LiteSpeed; the official `9.2.0-8.1` image; PrestaShop's online validator; two shops in two shop
groups or with shared customers; an export in psgdpr's admin screen on 9.2.0 (psgdpr 1.4.3 fails there); the guide
page on its web root; one unexplained PHP 8.0.30 segfault report (not reproduced).

## Next, in this order

1. The owner's walk of the two test shops, with the upgrade of shop B from the new zip; findings come back as fixes.
2. Publish the merchant guide (CON-424) once the owner has the web root and the FTP account and gives the go-ahead.
3. The live walk against the real Smaily service and engine: INT-306 is answered, only the test account is missing.
4. CON-444's seven cross-connector questions (owner, later).
5. The PrestaShop demo store (CON-425, under GRO-310), after the live walk.
6. The pilot store (owner). Price groups (CON-385) and stock alerts (CON-371) come after 3.0.0.

## Queue (technical, for the orchestrator)

- Campaign Intelligence keys: a product that gets its first combination keeps its old row (`ps-<id>`) until the next
  nightly list; ending it at the save needs a new hook, so the main class changes (ARCHITECTURE 8.11, "Not built").
  With `PS_COMBINATION_FEATURE_ACTIVE` = 0 the cart and order lines use `ps-<id>` while the catalog still sends a row
  per combination. The product page's `product_view` key uses `Product::getDefaultAttribute($id)` without the
  page's availability fallback.
- The engine mock's default 5xx code `internal_error` is no contract code, but 1.37.1 names none for a general 5xx.
  `sendsmaily.net` has wildcard DNS: only the egress guard fences a shop from the real Smaily API.
- Clean-up (ARCHITECTURE 13): `Upgrade300`; the mock's transport passes other hosts on; screenshots for the user
  guide. The 2.x tab row stays in `tab`, inactive.
- After 3.0.0, from the simplification review: a shared base of the two queues; index use in the ingest queue's
  sweeps (weigh it against ARCHITECTURE 4.3); a prefetch of product facts at the catalog flush; caches of the
  connections and of the workflow list; `HistoryJobs` through `JobStore`; `ReportSettings` through `setJson()`.

## First-release checklist (gauge: 14 / 14 demonstrated)

A box is ticked only when the real flow was exercised, every service a mock. Records: `docs/internal/walks/`.

- [x] 1. Upgrade in place from 2.x, including settings left by 1.6.x — genuine 2.2.0 and 1.6.2 stores on 8.0.0, 8.2.8
  and 9.2.0; three upgrade behaviours walked in a browser on 8.2.8 and 9.2.0. Still waits: the pilot store.
- [x] 2. Smaily account connection, with an invitation for a new store — browser, 8.0.0 and 9.2.0.
- [x] 3. Store mode by language (four modes) — walked in a browser on 8.2.8 and 9.2.0.
- [x] 4. Store mode by shop: one shared setup, or one per shop — two shops walked on 8.2.8 and 9.2.0.
- [x] 5. Consent mode: subscribers only, all customers, checkout opt-in only — HTTP, 8.0.0, 9.2.0.
- [x] 6. Contact sync both ways, first import, and a Log with retry — walked on 8.2.8 and 9.2.0.
- [x] 7. Store automations: welcome, first order, abandoned cart — walked on 8.2.8 and 9.2.0.
- [x] 8. Campaign Intelligence connection with catalog, customer and order imports — browser, 8.2.8 and 9.2.0.
- [x] 9. Campaign Intelligence automations, listed from the engine — browser, 8.2.8 and 9.2.0; one saved in test mode.
- [x] 10. Browse tracking and attribution of email clicks to orders — browser, 8.2.8 and 9.2.0.
- [x] 11. Product recommendations in the storefront, and the product feed (the RSS feed) — browser, 8.2.8, 9.2.0.
- [x] 12. Installation report (phone-home) — 8.2.8 and 9.2.0, mock receiver: the tick URL, saves, the uninstall.
- [x] 13. Privacy: export, erasure, and a shopper's personalization opt-out — browser, 8.2.8 and 9.2.0.
- [x] 14. Administration screens in English and Estonian — walked in Estonian on 8.2.8 and 9.2.0; native read.

## Open questions (for the owner)

Workers' notes: ARCHITECTURE 14; decisions: 15. Before recommending on a behaviour rule, read what the siblings do.

1. Tanel Rand's native read of the new Estonian wordings: at least "nõusolekurežiim" for "consent mode" (the
   warning "Selle Smaily konto poed kasutavad erinevaid nõusolekurežiime"), the sentences of a skipped older
   contact change, and the CON-445 strings that Magento's catalogue does not hold.
2. CI has never run: GitHub Actions on the fork are off until the owner enables them.
3. Marketplace's six content rules and PrestaShop's online validator wait until the listing is planned (CON-379).
4. For the cross-connector analysis CON-397: whether a stored Smaily subdomain that is not one plain label should
   park its contact row at once (today it takes the retry ladder, as Magento keeps it). WooCommerce also locks its
   Settings tabs while the connection is broken; PrestaShop does not. The server gate of the later steps checks only
   that an account is stored, as Shopify does. A Done contact import keeps its refusal reason in the Log (owner,
   2026-11-25); Magento and WooCommerce keep no error on a completed import.
5. The Estonian names of PrestaShop's own menus on the guide page wait for a native read after publishing.

**Only the owner can do:** enable GitHub Actions on the fork; give a Smaily test account for the live walk (it also
shows whether the real service takes an installation report); make the web root https://smaily.com/connect-presta/
and its FTP account (`~/smaily_ftp_presta`) and give the go-ahead for the guide's first upload; answer GRO-310's
questions on the demo store's hosting, cost and demo account; choose a pilot store. Also, when he wants: the engine
team's answer on the `source` value of browse events; psgdpr's defect on 9.x reported to PrestaShop.

Linear: Feature P-CON-324. Open Epics: CON-373 (upgrade; waits for the pilot), CON-398 (Campaign Intelligence),
CON-403 (installation report; waits for the live service), CON-379 (Marketplace). Done Epics: CON-370, CON-378,
CON-380, CON-381, CON-402. Asks to the sibling connectors: CON-417 to CON-422 and CON-427; analysis CON-397 and
CON-444; the guide page CON-424; the demo store CON-425 (under GRO-310); engine INT-306 (answered).
