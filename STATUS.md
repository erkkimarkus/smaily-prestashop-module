# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-25. Gauge 14/14._

## Next session opens with

**All 14 first-release capabilities are demonstrated against mocks on PrestaShop 8.2.8 and 9.2.0.** That is not a
release: no live service was called, no pilot store has upgraded, and CI has never run ("NOT verified" below).
Everything is on `v3`; no worker branch or worktree is left.

**What 2026-11-25 added** (each change tested on its own branch, then the merged tip together, "Last runs"):

- Engine contract 1.37.0: orders go to Campaign Intelligence in the shop's default currency (another currency is
  divided by the order's own rate); a catalog row's parent id and a removal's product id carry `ps-`. The agent
  guide anchors the work on the one initiative "Smaily earns revenue beyond email sending".
- The Initial setup's first answer "Separate stores" runs the layout change, as Settings and Magento's wizard do;
  step 1 refuses Continue until the Smaily connection test passes (owner, CON-397).
- The Log's failures and Test connection's reasons, Campaign Intelligence's too, show in the employee's language.
  Estonian: the colleague's four wordings (CON-416), the Overview's "Küpsiste bänner", "Website" as "Veebisait".
- Dev and test shops resolve `intelligence.smaily.com` and `api.github.com` to 127.0.0.1; the test bootstrap
  refuses a shop where the engine's name resolves elsewhere.
- The merchant guide page `docs/site/index.html` (CON-424; checked by `node --test`), linked from the admin. **Not
  published:** it waits for the owner's web root https://smaily.com/connect-presta/, the FTP account
  (`~/smaily_ftp_presta`) and his go-ahead.

**The owner's test environment** (workers never touch these stacks): two long-lived local shops behind the egress
guard, mocks only. `build/testkeskkond/lahtesta.sh a|b` builds one (git-ignored); guide `build/testkeskkond/JUHEND.md`.

- Shop A, Compose project `erkki920`: PrestaShop 9.2.0 at http://localhost:8301/, module 3.0.0 from the main
  checkout's `src/`. It shows new code after its cache is cleared.
- Shop B, Compose project `erkki828`: PrestaShop 8.2.8 at http://127.0.0.1:8302/, a genuine 2.2.0 that the owner
  upgrades by uploading `build/testkeskkond/smailyforprestashop-3.0.0.zip`, **rebuilt on 2026-11-25 from
  `cc5e06a`** (444 files outside `vendor/`, `src/Admin/UserGuide.php` among them, no `docs/site/`).
- After a reboot: `colima start`, then `build/testkeskkond/kaivita.sh`. The guard's certificates last 30 days.

**Last runs** (2026-11-25, merged tip `cc5e06a`). Host: `src/vendor/bin/phpunit` 2266 tests, 4001 assertions, 1146
skipped; `node --test tests/js/` 55 pass; PHP CS Fixer 0 of 356 files. In a shop made from scratch behind the egress
guard, debug mode on: 2266 tests on 8.2.8 (12141 assertions) and 9.2.0 (12146). `tests/ci/validate.sh` with the
packaged zip: 10 of 10 on flashlight 8.2.8 and 9.2.0, its suite with debug mode off green on both (same counts).

**NOT verified — keep these visible:** the live Smaily service and the live engine have never been called (every
check used a mock, also the installation report's); GitHub Actions on the fork are not enabled, so CI has never run;
MySQL (only MariaDB ran); LiteSpeed; the official `9.2.0-8.1` image; PrestaShop's online validator; Module
Manager's own Uninstall and Reset buttons; an upload over 1.6.2 on the current tip; the install check with two
shops; two shops that share one Campaign Intelligence connection, and capabilities 9 to 11 with two shops; an
export in psgdpr's admin screen on 9.2.0 (psgdpr 1.4.3 fails there); the guide page on its web root; one
unexplained PHP 8.0.30 segfault report (not reproduced).

## Next, in this order

1. The owner's own walk of the two test shops, with the upgrade of shop B from the new zip; his findings come back
   as fixes.
2. Publish the merchant guide (`docs/site/index.html`, CON-424) once the owner has the web root and the FTP account
   and gives the go-ahead; until then the admin's guide links lead to a page that is not published.
3. The live walk against the real Smaily service and engine: INT-306 is answered, only the test account is missing.
4. The PrestaShop demo store (CON-425, under GRO-310), after the live walk.
5. The pilot store (owner). Price groups (CON-385) and stock alerts (CON-371) come after 3.0.0.

## Queue (technical, for the orchestrator)

- Initial setup: the "Connected" badge of step 1 stays after a field edit, while Continue asks for a new test; the
  step rail lets a store with a refused saved account jump to a ticked step (WooCommerce and Shopify lock it); the
  first-answer card of "Separate stores" names no connection that moves.
- The tick URL answers 504 from nginx (30 seconds) while a 30-second Smaily timeout runs inside it. The engine
  mock's default 5xx code `internal_error` is not a contract code. `sendsmaily.net` has wildcard DNS: only the
  egress guard fences a shop from the real Smaily API. `assets/*.png` (2.x screenshots) are unreferenced now, and
  the PR template still lists them.
- `prestashop:module install` on 8.2.8 with debug mode on was seen to fail behind the guard as `upgrade` does
  (`docs/DEV_ENVIRONMENT.md` 4a names only the upgrade); on a fresh 8.2.8 shop of 2026-11-25 it succeeded.
- A `cart_add` of a product with combinations and no chosen combination gets a key that has no catalog row. A product
  that gets its first combination keeps its old row at the engine until the next nightly list.
- `Privacy\EngineFailure` builds the permanent-failure reason itself. `Config\Settings::setJson()` writes without
  the tag-safe flag; adding it changes the stored bytes of the notices and the update-check keys: decide first.
- Tests: some test leaves rows behind after a suite run (event-queue rows, `skipped` ingest rows), and one leaves
  other cron tokens; the engine mock stores each request's bearer key in its state file (keys it issued itself).
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
- [x] 8. Campaign Intelligence connection with catalog, customer and order imports — browser, 8.2.8 and 9.2.0: the
  connection on both screens, the three imports on the tick URL, live changes; two shops as separate stores on 9.2.0.
- [x] 9. Campaign Intelligence automations, listed from the engine — browser, 8.2.8 and 9.2.0; one saved in test mode.
- [x] 10. Browse tracking and attribution of email clicks to orders — browser, 8.2.8 and 9.2.0: nothing tracked with
  the confirmation off, the relay's events, a click's attribution also on an order a provider's server confirms.
- [x] 11. Product recommendations in the storefront, and the product feed — browser, 8.2.8 and 9.2.0: the block
  where the merchant placed it; the product feed is the RSS feed under its legacy address (owner: no second feed).
- [x] 12. Installation report (phone-home) — 8.2.8 and 9.2.0, mock receiver: the tick URL, saves, the uninstall.
- [x] 13. Privacy: export, erasure, and a shopper's personalization opt-out — browser, 8.2.8 and 9.2.0: the Log's
  form, the customer's own export page, the Personalization page, psgdpr's admin screen (its export on 8.2.8 only).
- [x] 14. Administration screens in English and Estonian — every screen and state walked in Estonian on 8.2.8 and
  9.2.0; the owner's native read found the texts fine; 3 wordings aligned with the siblings, 4 chosen by a colleague
  (CON-416, 2026-11-24) and read on both versions.

## Open questions (for the owner)

Workers' notes: ARCHITECTURE 14; decisions: 15. Before recommending on a behaviour rule, read what the siblings do.

1. After a reload the step list ticks every step up to the furthest one reached, also one never saved (as Magento).
2. Marketplace's six content rules and PrestaShop's online validator wait until the listing is planned (CON-379).
3. For the cross-connector analysis CON-397: late or retried contact changes, workflows after an account or mode
   change, two shops on one account; whether a stored Smaily subdomain that is not one plain label should park its
   contact row at once (today it takes the retry ladder, as Magento keeps it); whether Settings > Connection should
   refuse an account that Smaily rejects, as WooCommerce does (today it saves it).
4. The Estonian names of PrestaShop's own menus on the guide page wait for a native read after publishing.

**Only the owner can do:** enable GitHub Actions on the fork; give a Smaily test account for the live walk (it also
shows whether the real service takes an installation report); make the web root https://smaily.com/connect-presta/
and its FTP account (`~/smaily_ftp_presta`) and give the go-ahead for the guide's first upload; answer GRO-310's
questions on the demo store's hosting, cost and demo account; choose a pilot store. Also, when he wants: the engine
team's answer on the `source` value of browse events; psgdpr's defect on 9.x reported to PrestaShop.

Linear: Feature P-CON-324. Open Epics: CON-373 (upgrade; waits for the pilot), CON-398 (Campaign Intelligence),
CON-403 (installation report; waits for the live service), CON-379 (Marketplace). Done Epics: CON-370, CON-378,
CON-380, CON-381, CON-402. 2026-11-25: CON-416 (wordings) done; CON-417 to CON-422 (asks to the sibling connectors);
CON-424 (the guide page); CON-425 (the demo store, under GRO-310); analysis CON-397; engine INT-306 (answered).
