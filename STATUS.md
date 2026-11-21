# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-21 (unattended night run). Gauge 7/14. Nothing is in flight._

## Next session opens with

**On `v3`, landed and pushed to the fork** (`0d4425e`, 298 commits ahead of the official `master`): the Dashboard with
its corrections; Settings (Connection, Contacts, Automations, RSS) with the shop switches and the layout change as
Magento; the Log page with "Send again" and "Retry"; the Initial setup steps Connect, Contacts, Automations, Overview
(the Intelligence step waits for stage 5); discount codes in store automations (CON-393: nine of ten criteria met, the
privacy criterion waits for Story 5.6); the Estonian round (all 513 strings hold an Estonian target); the Marketplace
structural rules; upgrade hardening (delay cap with notice, re-run safety, multistore off with several shop rows);
security hardening (capture token bound to the cart, hourly bound, deleted customers, the door, unreachable 2.x
classes removed); the stale-subscription fix; the scope fixes of the admin.

**Verified on `0d4425e`** (2026-11-21, nothing else running; commands in DEV_ENVIRONMENT 2, 4, 6):

- `tests/ci/validate.sh` with the packaged zip: 10 of 10 checks on flashlight 8.2.8 and 9.2.0; in the shop 1415 tests,
  7251 assertions (8.2.8) and 7257 (9.2.0).
- The in-shop suite on reset dev shops behind the egress guard: 8.2.8 (PHP 8.0.30) three runs in a row, each 1415
  tests, 7251 assertions, no segfault, no `core` file; 9.2.0: 1415 tests, 7257.
- Host: PHPUnit 1415 tests, 2121 assertions, 740 skipped; PHP CS Fixer 0 of 251 files; PHP 8.0.30 lint of `src` and
  `tests`, 398 files, 0 errors; `header-stamp --dry-run` and `node --check` clean.
- The zip: 333 files; an `index.php` in 60 of 60 folders outside vendor; the licence notice in 299 of 299 PHP, Twig,
  JavaScript and CSS files; no test or development file.
- A console uninstall on both versions after a setup in a browser (an account against the mock, the welcome on with a
  discount rule, one email with a code): 24 `SMAILY%` keys, 9 tables, 5 tabs, 32 hooks before, 0 of each after; the 1
  issued code (1 `cart_rule` row) stays, as designed.

**NOT verified — keep these visible:** the live Smaily service has never been called (every check used the mock);
GitHub Actions on the fork are not enabled, so CI has never run; MySQL (only MariaDB ran); LiteSpeed; the official
`9.2.0-8.1` image; PrestaShop's online validator (it needs an upload); the multishop screens in Estonian; one
unexplained PHP 8.0.30 segfault report (not reproduced in 113 runs).

## Next, in this order (the owner decides at the start of the session)

1. The owner's answers to the open questions below.
2. Capability walks that are now possible: 14, after the multishop screens are seen in Estonian and a native speaker
   has read the module's own sentences (92 at the Estonian round).
3. Stage 5, Campaign Intelligence, only after the owner says so. Its map: 5.1 engine client first; then 5.2 ingest,
   5.5 storefront and 5.6 privacy in parallel; then 5.3 history import and 5.4 engine automations; 6.1 installation
   report may start after 5.1. It needs an engine mock (port WooCommerce's) and the engine host in the egress guard;
   the key namespace `ps-` stays behind one constant until the engine team answers INT-306.

## Queue (technical, for the orchestrator)

- The Smaily client's own failure messages show in English in the Log; its Last Error column is narrow for a sentence.
  A simplification review of the Log and Automations code is due.
- Clean-up (ARCHITECTURE 13): `Upgrade300`; the janitor skips two tables; the mock's transport passes other hosts on;
  one test leaves other cron tokens behind; screenshots for the user guide. The 2.x tab row stays in `tab`, inactive;
  the 1.6.x hidden-tab removal never ran on a real 1.6.2.

## First-release checklist (gauge: 7 / 14 demonstrated)

A box is ticked only when the real flow was exercised; the line names how. Decisions: ARCHITECTURE 15.

- [x] 1. Upgrade in place from 2.x, including settings left by 1.6.x — genuine 2.2.0 and 1.6.2 stores on 8.0.0, 8.2.8,
  9.2.0. Still waits: "Finish upgrade" (3.1); the pilot store.
- [x] 2. Smaily account connection, with an invitation for a new store — browser, 8.0.0 and 9.2.0.
- [x] 3. Store mode by language (four modes) — walked in a browser on 8.2.8 and 9.2.0.
- [x] 4. Store mode by shop: one shared setup, or one per shop — two shops walked on 8.2.8 and 9.2.0; the scope defect
  found beside it is fixed since.
- [x] 5. Consent mode: subscribers only, all customers, checkout opt-in only — HTTP, 8.0.0, 9.2.0.
- [x] 6. Contact sync both ways, first import, and a Log with retry — walked on 8.2.8 and 9.2.0.
- [x] 7. Store automations: welcome, first order, abandoned cart — walked on 8.2.8 and 9.2.0.
- [ ] 8. Campaign Intelligence connection with catalog, customer and order imports
- [ ] 9. Campaign Intelligence automations, listed from the engine
- [ ] 10. Browse tracking and attribution of email clicks to orders
- [ ] 11. Product recommendations in the storefront, and the product feed
- [ ] 12. Installation report (phone-home)
- [ ] 13. Privacy: export, erasure, and a shopper's personalization opt-out
- [ ] 14. Administration screens in English and Estonian — all strings in Estonian, walked on 8.2.8 and 9.2.0; waits:
  the multishop screens, a native reader, the stage 5 screens.

## Open questions (for the owner)

A number in brackets is the row of `docs/ARCHITECTURE.md` section 14 that holds the detail. 1–9: consent and audience;
10–16: upgrade and data; 17: Marketplace (details in `docs/MARKETPLACE.md` 6.3); 18–22: product.

1. A newsletter-block subscriber without an account is synced and welcomed only when "Include guests" is on; Magento
   syncs and welcomes them regardless: which? [25]
2. The newsletter block's unsubscribe needs no confirmation in PrestaShop, and the module forwards it to Smaily as
   permanent: keep? [26]
3. A late unsubscribe arriving after a newer delivered subscription leaves the contact unsubscribed: keep? [23]
4. The Log refuses a resend that would re-subscribe after a later consent change, and adds no second entry for an
   event that already waits — two rules beyond Magento: confirm? [27]
5. The stale-subscription check compares rows of one shop only; two shops on one Smaily account are one contact there:
   extend it to the account? [28]
6. Two shops on one Smaily account with different consent modes: could the reconcile mark someone subscribed in the
   "subscribers only" shop because the "all customers" shop created the contact? It depends on how Smaily logs
   contacts created through the API. [29]
7. With multistore off, the abandoned-cart sweep covers the carts of every shop row; 2.x covered only the cron URL's
   shop: narrow it to 2.x's behaviour? [30]
8. In the mode "an account per language" the router ends at the no-language workflow, which Magento never starts:
   remove that fallback? [24]
9. The welcome email repeats on subscribe → unsubscribe → subscribe, as Magento and WooCommerce (Shopify sends it
   once): keep? [31]
10. A Smaily account saved while an upgrade is unfinished is replaced by the upgrade, or blocks it at the verify step:
    which? [32]
11. Abandoned-cart tracker rows are never deleted (the row is the only sign that a cart was reminded); Magento deletes
    after 30 days and reminds a changed cart again: which rule? [33]
12. Two hooks are registered and unused (`actionCartSave`, `actionObjectCustomerDeleteAfter`): remove them before the
    release (touches the main class)? [34]
13. The import card offers "Start import" again once the last import is 90 days old and deleted: keep the newest
    import per shop? [35]
14. A shop row with no workflow now means "this shop sends nothing for this event" — a data convention to confirm
    before release. [36]
15. Replacing a Smaily account in the same language mode removes that account's workflows (Magento keeps the old
    numbers): keep? [37]
16. An upgraded store keeps the 35 files 2.2.0 shipped and 3.0.0 does not: may the upgrade delete them? [21]
17. The licence GPL-3.0, the public RSS feed URL without a token, the literal SQL casts, the one `unserialize` for
    1.6.x settings, the external links, the guide as a PDF; and PrestaShop's online validator has not been run because
    it needs an upload. [21]
18. The amber and red Background tasks card hides the cron line behind the same button as the green one: keep? [38]
19. Capability 4 is ticked although a scope defect sat beside it (fixed since), and capability 3 asks for confirmation
    only when a language mode with accounts or workflows is left: agree with both ticks?
20. Discount codes: a guest's code is transferable; every email makes a new rule (WooCommerce re-sends an unused
    code); a guest's remainder voucher of a merchant's own rule can still be picked as a template. [39]
21. The checkout capture: one reminder per address per day to up to 2000 addresses an hour stays possible; Magento's
    five writes per cart need a schema change. [40]
22. Stage 5: start before the engine team answers (row 1)? Are price groups (CON-385) in 3.0.0 (row 41)? Does the 2.x
    RSS feed count as capability 11's product feed (row 42)? Are multi-currency stores in scope (row 4)? The contract
    sends customers with an order in the last 60 months whatever their marketing consent — acceptable for the privacy
    text (row 43)?

**Only the owner can do:** enable GitHub Actions on the fork (row 16); give a Smaily test account for a live walk; ask
the engine team for the answer on INT-306; choose a pilot store; have a native speaker read the Estonian.

Linear: Feature P-CON-324; Epics CON-373, CON-378, CON-381 (with CON-393), CON-379, CON-380; sibling asks CON-394,
CON-395, CON-396; engine INT-306; subscription SUB-28 (answered). Gauge: 7/14.
