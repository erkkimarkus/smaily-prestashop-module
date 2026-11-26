# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-26. Gauge 14/14._

## Next session opens with

- 2026-11-26 (CON-445 part 2, as Magento): a finished Initial setup opens step 1 "Completed" on the account's
  summary with "Edit credentials"; steps 1–3 list "Shops with their own values" (`use_shared` returns a shop,
  ARCHITECTURE 5.2); the setup's bar has "Change" (Settings' `ChangeLayout` under a setup route); Back is a
  button; the intro sits in the credentials card; the Overview lists the RSS feed and the User Guide; Settings >
  Connection says Connected as "<subdomain>". Host 2328 tests, 4121 assertions; JS 55; CS 0 of 365; PHP 8.0.30
  lint of 8 files. Fresh shops behind the guard: 2328 tests on 9.2.0 (12555) and 8.2.8 (12550), leftovers
  silent. Headless walk, two shops, EN and ET, Smaily mock: 45 of 45 checks on 9.2.0 and on 8.2.8.
- 2026-11-26 (CON-445 part 1, Dashboard and Log against Magento, owner-approved): "Setup incomplete" also while the
  Initial setup is unfinished; no verdict while the upgrade is unfinished; dots on the connection cards; Withdrawn,
  the employee's date format and → in Recent activity and Quick links; Contact syncs counted exactly; the 227 sub-line;
  no button on a refusal and no two-shops line (another CON-445 part puts it on Settings > Intelligence); the Log's intro box,
  "Delivery details" with pinned header and foot, the engine store line and the account-wait line (ARCHITECTURE 10.2,
  10.8, 10.12). Host 2333 tests, 4135 assertions; JS 55; CS 0 of 366. Fresh shops behind the guard: 2333 tests on
  9.2.0 (12538) and 8.2.8 (12533). Headless walk on both, EN and ET.
- 2026-11-26: the engine contract copy is v1.37.1 (CON-413; wording only). A reconnect already sends the stored
  `previous_prefix`, and `site_url` carries the shop's path. The privacy requests keep to the engine's one limit (10 a
  minute per engine store); a 429 waits as asked and spends no attempt (CON-433b, ARCHITECTURE 11.2).
- 2026-11-26: the Campaign Intelligence card has Magento's design, with the "Engine in action" preview, which is
  hidden below 960 px (owner-approved sketch; ARCHITECTURE 10.12). Host 2318 tests, 4121 assertions; JS 55; CS 0 of
  362. Fresh shops behind the guard: 2318 tests on 9.2.0 (12481) and 8.2.8 (12476). Headless walk on both, EN and ET:
  the card on the Dashboard, Settings and step 4; Dismiss and a connection still hide it.
**All 14 first-release capabilities are demonstrated against mocks on PrestaShop 8.2.8 and 9.2.0.** That is not a
release: no live service was called, no pilot store has upgraded, and CI has never run ("NOT verified" below).
Everything is on `v3`; no worker branch or worktree is left.

**The owner's test environment** (workers never touch these stacks): two long-lived local shops behind the egress
guard, mocks only. `build/testkeskkond/lahtesta.sh a|b` builds one (git-ignored); guide `build/testkeskkond/JUHEND.md`.

- Shop A, Compose project `erkki920`: PrestaShop 9.2.0 at http://localhost:8301/, module 3.0.0 from the main
  checkout's `src/`. It shows new code after its cache is cleared.
- Shop B, Compose project `erkki828`: PrestaShop 8.2.8 at http://127.0.0.1:8302/, a genuine 2.2.0 that the owner
  upgrades by uploading `build/testkeskkond/smailyforprestashop-3.0.0.zip`, **rebuilt on 2026-11-25 (late)
  from `5f1db54`** (SHA-256 `e3234c48…`; 446 files outside `vendor/`, no `docs/site/`; `src/` is the same at the
  handoff's commit).
- After a reboot: `colima start`, then `build/testkeskkond/kaivita.sh`. The guard's certificates last 30 days.

**Last runs** (2026-11-25 night, on `ed1590b`). Host: `src/vendor/bin/phpunit` 2284 tests, 4035 assertions, 1153
skipped; `node --test tests/js/` 55 pass; PHP CS Fixer 0 of 358 files. In a shop made from scratch behind the egress
guard, debug mode on: 2284 tests on 8.2.8 (12214 assertions with a tick state at the start, 12213 without) and on
9.2.0 (12219 both ways); the leftovers check, now with the tick state, silent on every run. `tests/ci/validate.sh`
with the zip: 10 of 10 on flashlight 8.2.8 and 9.2.0, its suite with debug mode off 2284 tests on both (12214 and
12219 assertions).
2026-11-26 (release verification part 1, `docs/internal/walks/2026-11-26-release-verification-part-1.md`): the suite on
MySQL 8.0.46 (`docker-compose.mysql.yaml`), 2284 tests on 8.2.8 (12214) and 9.2.0 (12219) after one fix (the Log's ingest
rows ordered by `id`); Module Manager's Reset and Uninstall in a browser, 8 of 8 checks on each lane; the zip of `91acd79`
uploaded over a genuine 1.6.2 on 8.2.8, 10 of 10.

**Run of 2026-11-26 (the cron URL answers before the jobs, `739a01d`).** Host: 2289 tests, 4035 assertions, 1158
skipped; `node --test tests/js/` 55 pass; PHP CS Fixer 0 of 358 files; PHP 8.0 lint of the changed files. In a
shop made from scratch behind the guard, debug mode on, no tick state at the start: 2289 tests on 9.2.0 (12258
assertions) and on 8.2.8 (12253), leftovers check silent. Slow-Smaily walk (mock answers after 35 s): before, the
tick URL answered nginx's 504 after 30.0 s on 9.2.0; after, `OK: the tick is running.` in 0.03–0.06 s on 9.2.0 and
0.7 s on 8.2.8, rows back on the retry ladder and the lock free about 30 s later. `tests/ci/validate.sh` not run.
2026-11-26 on `91acd79`: `validate.sh` 10 of 10 on both with its new `SHOPS` switch, and with `SHOPS=2` 10 of 12 on
both (the console-install defect, fixed by CON-435 below). **Two shops** walked on 8.2.8 and 9.2.0
(`docs/internal/walks/2026-11-26-two-shops.md`): Module Manager's install, one shared connection, a connection per
shop, the layout change both ways, capabilities 8 to 11 per shop: 91 of 91 and 91 of 92 (the one a walk fault).

**Run of 2026-11-26 (CON-445 part 3: Contacts, Automations and Intelligence as Magento draws them).** Host 2335
tests, 4143 assertions; JS 55; CS 0 of 364; PHP 8.0.30 lint of the 23 changed PHP files. Fresh shops behind the guard:
2335 tests on 8.2.8 (12567) and 9.2.0 (12572), leftovers silent. Headless walk on both, EN and ET, Smaily and engine
mocks, a second shop for the shared engine store: 30 of 30 checks on 8.2.8, 30 of 30 on 9.2.0 (one check re-run alone:
the tick had not taken the catalog import yet). The Smaily mock got `user_gender`, `customer_id` and `customer_group`.

**Run of 2026-11-26 (CON-433c on `effe744`: workflows after an account or mode change, Settings refuses a rejected
account, refused import contacts in the Log and in a privacy request, the consent-mode warning).** Host 2324 tests,
4121 assertions, 1175 skipped; JS 55; CS 0 of 364; PHP 8.0.30 lint of the 22 changed PHP files. Fresh shops behind
the guard: 2324 tests on 8.2.8 (12510) and 9.2.0 (12515), leftovers silent. Browser walk on both, EN and ET, Smaily
mock, two shops: 35 of 35 checks on each (the Initial setup, the refused test and endpoint, the account-change
question with Cancel and OK, the plain "Saved." after it, both banners and the pills, the import card and the Log's
refused list, the warning on Settings and step 2).

**Run of 2026-11-26 (CON-433b, privacy requests paced).** Host 2317 tests, 4121 assertions; JS 55; CS 0 of 362; PHP
8.0 lint of 23 files. Fresh shops behind the guard: 2317 tests on 9.2.0 (12474) and 8.2.8 (12469), leftovers silent.
Walk on both, Smaily and engine mocks, tick URL: 25 opt-outs from the reconcile went 10, 10, 5 a minute apart (at
most 10 in any 60 s), attempts 0; a 429 with `retry_after_seconds` 90 held all three rows 90 s after one request,
attempts 0, engine health unchanged, then delivered.

**Run of 2026-11-26 (CON-435, a console install enables every shop; ARCHITECTURE 3.9).** Host 2301 tests, 4054
assertions; JS 55; CS 0 of 359; PHP 8.0 lint. Fresh shops behind the guard: 2301 tests on 9.2.0 (12331) and 8.2.8
(12326), leftovers silent. `validate.sh` 10 of 10 on both (suite with debug mode off 2301 tests, 12331 and 12326),
with `SHOPS=2` 12 of 12 on both. Two shops behind the guard on both: the console install enables both shops, each
feed and cron URL answers; Module Manager with shop 2 selected enables shop 2 alone (as before the fix, seen on
9.2.0), with "All shops" both. A console upgrade over a genuine 2.2.0 off in shop 2: `done` from 2.2.0, shop 2 still
without a `ps_module_shop` row after a tick.

**NOT verified — keep these visible:** the live Smaily service and the live engine have never been called (every
check used a mock, also the installation report's); GitHub Actions on the fork are not enabled, so CI has never run;
MySQL 5.7 (8.0 ran); LiteSpeed; the official `9.2.0-8.1` image; PrestaShop's online validator; two shops in two shop
groups or with shared customers; an export in psgdpr's admin screen on 9.2.0 (psgdpr 1.4.3 fails there); the guide
page on its web root; one unexplained PHP 8.0.30 segfault report (not reproduced).

## Next, in this order

1. The owner's walk of the two test shops, with the upgrade of shop B from the new zip; findings come back as fixes.
2. Publish the merchant guide (CON-424) once the owner has the web root and the FTP account and gives the go-ahead.
3. The live walk against the real Smaily service and engine: INT-306 is answered, only the test account is missing.
4. The PrestaShop demo store (CON-425, under GRO-310), after the live walk.
5. The pilot store (owner). Price groups (CON-385) and stock alerts (CON-371) come after 3.0.0.

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

1. Marketplace's six content rules and PrestaShop's online validator wait until the listing is planned (CON-379).
2. For the cross-connector analysis CON-397 (decided 2026-11-26 and built under CON-433: a newer contact change
   wins; workflows that another account or mode lacks are removed; Settings > Connection refuses a rejected account;
   a refused import names its contacts; shops of one account with different consent modes are warned): whether a
   stored Smaily subdomain that is not one plain label should park its
   contact row at once (today it takes the retry ladder, as Magento keeps it). WooCommerce also locks its
   Settings tabs while the connection is broken; PrestaShop does not. The server gate of the later steps checks only
   that an account is stored, as Shopify does: a stored account Smaily refuses can still save steps 2–4 around the
   page. A Done contact import keeps its refusal reason in the Log (owner, 2026-11-25); Magento and WooCommerce keep
   no error on a completed import.
3. The Estonian names of PrestaShop's own menus on the guide page wait for a native read after publishing.
4. A wording for Tanel Rand's native review: "nõusolekurežiim" for "consent mode" (CON-433's warning "Selle Smaily
   konto poed kasutavad erinevaid nõusolekurežiime"); the admin catalogues had no word for it (the guide page uses it).

**Only the owner can do:** enable GitHub Actions on the fork; give a Smaily test account for the live walk (it also
shows whether the real service takes an installation report); make the web root https://smaily.com/connect-presta/
and its FTP account (`~/smaily_ftp_presta`) and give the go-ahead for the guide's first upload; answer GRO-310's
questions on the demo store's hosting, cost and demo account; choose a pilot store. Also, when he wants: the engine
team's answer on the `source` value of browse events; psgdpr's defect on 9.x reported to PrestaShop.

Linear: Feature P-CON-324. Open Epics: CON-373 (upgrade; waits for the pilot), CON-398 (Campaign Intelligence),
CON-403 (installation report; waits for the live service), CON-379 (Marketplace). Done Epics: CON-370, CON-378,
CON-380, CON-381, CON-402. 2026-11-25: CON-416 (wordings) and CON-377 (palette) done; CON-417 to CON-422 and CON-427
(asks to the sibling connectors; CON-427 to WooCommerce, keys of variable products); CON-424 (the guide page);
CON-425 (the demo store, under GRO-310); analysis CON-397; engine INT-306 (answered).
