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

26 Nov added (CHANGELOG and ARCHITECTURE 15 hold the detail): contract 1.37.2; the new module icon; the menu names
on 8.2.8's first page; the RSS feed's language picker; CON-452 (a ticked checkout box and a store unsubscribe reach
Smaily in every consent mode, the welcome follows contact sync, with an upgrade notice); CON-454 (Magento's catalog
count, date formats, setup layout, "Subscription type", import stop details); CON-459 (test mode and "Send test
email" per Campaign Intelligence email, "Stopped"); CON-460 (WooCommerce's three language modes, one card per
automation, the guest switch in All customers, the Overview with Finish and the cron card, the User Guide link);
the guest-merge fix and the quiet merge end (CON-397); ticked guests imported in every mode; README for 3.0.0.
The merged tip `1d997b6` was verified as one (below). The guide is live at https://smaily.com/connect-presta/.

Then, in order:

1. **Release step 1:** a release candidate on the fork (`erkkimarkus/smaily-prestashop-module`), after the
   owner's approval (Linear CON-462); a release is a one-way door.
2. **The pilot store** (owner's choice): a real 2.x store upgrades in place.
3. **The official repository** (`sendsmaily/smaily-prestashop-module`): Maintain rights are requested (Linear, to
   Kait Todesk) before this step; agents never push there.

Open beside them: Tanel Rand's native read (CON-453); the Magento and WooCommerce follow-ups CON-429 to CON-432 and
CON-461; CON-444's questions (owner, later); the demo store (CON-425). After 3.0.0: CON-385, CON-371.

**All 14 first-release capabilities are demonstrated against mocks on PrestaShop 8.2.8 and 9.2.0**, and those the
live shop's settings switch on against the real services on 9.2.0 (2026-11-26). That is not a release: no pilot
store has upgraded ("NOT verified" below).

**The owner's test environment** (workers never touch these stacks; guide `build/testkeskkond/JUHEND.md`, git-ignored):

- Shop A, `erkki920`: 9.2.0 at http://localhost:8301/, mocks, the main checkout's `src/` (new code after a cache clear).
- Shop B, `erkki828`: 8.2.8 at http://127.0.0.1:8302/, mocks, a genuine 2.2.0 that the owner upgrades by uploading
  `build/testkeskkond/smailyforprestashop-3.0.0.zip`, **rebuilt on 2026-11-26 from `1d997b6`** (SHA-256 `7cc04259…`;
  458 files outside `vendor/`, no `docs/site/`).
- Live shop, `erkkilive`: 9.2.0 at http://localhost:8303/, the main checkout's `src/`, debug mode off, EN and ET; the
  real Smaily API, engine and report receiver through the guard's allow-list (DEV_ENVIRONMENT 4a), a tick every minute
  (`tiksur`). `build/testkeskkond/live.sh` starts it; no worker runs a suite or walk there unasked.
- After a reboot: `colima start`, then `build/testkeskkond/kaivita.sh`. The guard's certificates last 30 days.

**Last runs** (2026-11-26, the merged tip `1d997b6`, the release-candidate check). Host: `src/vendor/bin/phpunit`
2417 tests, 4249 assertions, 1239 skipped; `node --test tests/js/*.test.js` 55 pass; PHP CS Fixer 0 of 370 files;
PHP 8.0.30 lint of the 142 PHP files changed since `679d70a`; the Estonian XLIFF files valid (804 and 8 units, no
duplicate id, every id the MD5 of its source); `admin.css` balanced. In shops made from scratch behind the egress
guard, debug mode on: 2417 tests on 9.2.0 (12968 assertions) and on 8.2.8 (12963), the leftovers check silent.
`tests/ci/validate.sh` with the zip, debug mode off: 10 of 10 on flashlight 8.2.8 and 9.2.0 (the same counts),
with `SHOPS=2` 12 of 12 on both. CI on the fork: all six jobs green at `1d997b6`. Upgrades with the zip, behind the
guard: Module Manager upload over a genuine 2.2.0 and over a genuine 1.6.2 (8.2.8), the console over a genuine 2.2.0
(9.2.0), and a 2.2.0 with contact sync off by both paths: each ended with `ps_module` at 3.0.0 and active, the
migration `done`, the two legacy cron URLs answering with their stored tokens (403 with a wrong one), the RSS feed,
no file of `LegacyFiles::FILES` left, and the notices of its case (the welcome notice only where contact sync was
off). A headless walk of every admin screen (Dashboard, Initial setup 1–5, Settings Connection, Contacts,
Automations, Intelligence and RSS, the Log and its Details), EN and ET, mocks only, on 9.2.0 and 8.2.8: one shop
with one language, two languages in `single`, b, c and a, and two shops: 224 screens, none with a PHP error, a
module 5xx, a module JS error, an untranslated module string or an empty card. Nine screens also caught
PrestaShop's own errors (its notification and address endpoints and `ps_mbo` answering 500 right after a cache
rebuild, and `ps_mcp_server`'s script refused by CORS on the second shop's address). PrestaShop's own words stay
English without a language pack.

**NOT verified — keep these visible:** a pilot store's upgrade; against the live services, walked on 9.2.0 only for
what the owner's settings switch on, never on 8.2.8: a change made in Smaily reaching the store, the First order and
Abandoned cart triggers and discount codes (off in the live shop), recommendations that render (the engine answered
0 slots), the invitation, a retried failure; MySQL 5.7 (8.0 ran); LiteSpeed; PrestaShop's online validator; two
shops in two shop groups or with shared customers; an export in psgdpr's admin screen on 9.2.0 (psgdpr 1.4.3 fails
there); one unexplained PHP 8.0.30 segfault report (not reproduced).

## Queue (technical, for the orchestrator)

- Campaign Intelligence keys: a product that gets its first combination keeps its old row (`ps-<id>`) until the next
  nightly list; ending it at the save needs a new hook, so the main class changes (ARCHITECTURE 8.11, "Not built").
  With `PS_COMBINATION_FEATURE_ACTIVE` = 0 the cart and order lines use `ps-<id>` while the catalog still sends a row
  per combination. The product page's `product_view` key uses `Product::getDefaultAttribute($id)` without the
  page's availability fallback.
- The engine mock's default 5xx code `internal_error` is no contract code, but 1.37.2 names none for a general 5xx.
  `sendsmaily.net` has wildcard DNS: only the egress guard fences a shop from the real Smaily API.
- PrestaShop drops the query of `/en/?…` and `index.php?id_product=…&…` in its redirect; no link the engine builds
  is of that form (the catalog sends `Link::getProductLink()`), but a hand-made email link loses its attribution.
- CI: `actions/checkout@v4` and `actions/setup-node@v4` run on Node 20, which GitHub deprecates (forced to Node 24).
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

1. Tanel Rand's native read (CON-453) of the new Estonian wordings: at least "nõusolekurežiim" for "consent mode",
   the sentences of a skipped older contact change, the CON-445 strings that Magento's catalogue does not hold, and
   the Estonian names of PrestaShop's own menus on the guide page.
2. Marketplace's six content rules and PrestaShop's online validator wait until the listing is planned (CON-379).
3. For the cross-connector analysis CON-397: whether a stored Smaily subdomain that is not one plain label should
   park its contact row at once (today it takes the retry ladder, as Magento keeps it). WooCommerce also locks its
   Settings tabs while the connection is broken; PrestaShop does not. The server gate of the later steps checks only
   that an account is stored, as Shopify does. A Done contact import keeps its refusal reason in the Log (owner,
   2026-11-25); Magento and WooCommerce keep no error on a completed import.

**Only the owner can do:** approve the release candidate (CON-462); choose a pilot store; request Maintain rights
on the official repository; upload the guide after a change to it (the command is in CLAUDE.md); for the live
walk's waiting parts, unsubscribe a `walk.*@example.com` address in Smaily and, if wanted, switch on First order and
Abandoned cart in the live shop; answer GRO-310's questions on the demo store. Also, when he wants: the engine
team's answer on the `source` value of browse events; psgdpr's defect on 9.x reported to PrestaShop.

Linear: Feature P-CON-324. Open Epics: CON-373 (upgrade; waits for the pilot), CON-398 (Campaign Intelligence),
CON-403 (installation report; the live receiver took it in), CON-379 (Marketplace). Done Epics: CON-370, CON-378,
CON-380, CON-381, CON-402. Asks to the sibling connectors: CON-417 to CON-422, CON-427, CON-429 to CON-432 and
CON-461; analysis CON-397 and CON-444; the guide page CON-424; the demo store CON-425 (under GRO-310); engine
INT-306 (answered).
