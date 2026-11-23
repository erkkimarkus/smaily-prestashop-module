# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-23. Gauge 13/14._

## Next session opens with

**On `v3`, landed and pushed to the fork** (`0c38f0b`): stages 1 to 4 (upgrade in place, connection, store modes,
consent modes, contact sync, the Log, store automations with discount codes, Dashboard, Settings, Initial setup, the
Estonian round, the Marketplace structural rules, the database gateway) with the owner's decisions of 2026-11-21
(ARCHITECTURE section 15), and four Stories of stage 5, Campaign Intelligence, each exercised against an engine mock:

- 5.1 the engine client and the connection (made on Settings > Intelligence and on step 4 of the Initial setup);
- 5.2 ingest: product, customer and order changes, removals, the nightly product list, the identity merge;
- 5.5 storefront: consent-gated browse tracking through the store's relay, click attribution stored with the order,
  the "Recommended for you" block as a widget;
- 5.6 privacy: export and erasure through PrestaShop's GDPR module and a form on the Log, the customer's
  Personalization page.

The key namespace `ps-` is one constant (`Engine\Payload\Keys`) and is **not agreed with the engine team** (INT-306):
nothing that sends a key ships before the answer.

**Since then (2026-11-23, on `v3`):** a later migration run keeps the display hook
a merchant gave the recommendations block (ARCHITECTURE 3.7), and capability 13 was walked on the merged code in a
browser on 8.2.8 and 9.2.0 (ARCHITECTURE 11.2). Every privacy flow holds. One thing could not be shown: an export in
psgdpr's admin screen on 9.2.0, because psgdpr 1.4.3 itself fails there (facts file 6.13). The owner's answer
(2026-11-23): the Log's "Privacy request" form is always shown, also beside psgdpr, and capability 13 counts.

**Story 5.3 (Linear CON-405), first part, merged and pushed on `v3`:** Settings > Intelligence and step 4 of the
Initial setup connect a store to Campaign Intelligence, check and end the connection, hold the merchant's
cookie-banner confirmation, and show the catalog, customers and orders imports (ARCHITECTURE 8.12 and 10.11).

**Story 5.3, second part (2026-11-23, on `v3`):** the Campaign Intelligence invitation on top of the Dashboard and
of the Settings tabs with its 30-day dismissal; the Dashboard's Campaign Intelligence and Browse tracking cards,
Catalog items tile and verdicts; the Log's source and job reasons; a layout change that carries the selected or
default shop's connection and ends the others (ARCHITECTURE 8.9 and 10.12). Walked in a browser against the engine
and subscription mocks on 8.2.8 (114 checks) and 9.2.0 (114 checks), with one shop and with two, in English and
Estonian; screenshots in `build/screens-5.3b/`.

**Last runs** (2026-11-23). On the branch of Story 5.3's second part, before its merge: in-shop suite 2222 tests on
8.2.8 (11893 assertions) and on 9.2.0 (11898), with debug on, each lane on a shop made from scratch behind the egress
guard; host 2222 tests, 3910 assertions, 1129 skipped; `node --test tests/js/` 43 pass; PHP CS Fixer 0 of 355 files;
PHP 8.0.30 lint of 356 module files and 215 test files, no failure. With debug off the suite last ran on `v3` at
`82fc312`, before that second part: 2172 tests on 8.2.8 (11689 assertions) and on 9.2.0 (11695). The capability walks
of 8 to 11 ran on `82fc312` too; check by check on both versions, with two shops on 9.2.0 and a 2.2.0 store upgraded
on 8.2.8, they are in `docs/internal/walks/2026-11-23-capabilities-8-11.md`; scripts, result files and screenshots
in `build/walk-8-11/`. The merged tip (the second part and the walks' record together) ran the host tests only.
Last run on `0c38f0b` (2026-11-21, 1957 tests): `tests/ci/validate.sh` 10 of 10 on both flashlight images. The zip
content and the uninstall check last ran on `0d4425e`, long before stage 5.

**NOT verified — keep these visible:** the live Smaily service and the live engine have never been called (every check
used a mock); GitHub Actions on the fork are not enabled, so CI has never run; MySQL (only MariaDB ran); LiteSpeed;
the official `9.2.0-8.1` image; PrestaShop's online validator; the multishop screens in Estonian; one unexplained PHP
8.0.30 segfault report (not reproduced in 113 runs). Of stage 5: the capability walks of 8 to 11
(`docs/internal/walks/2026-11-23-capabilities-8-11.md`) ran with one shop, and capability 8 also with two shops as
separate stores: two shops that share one Campaign Intelligence connection, and capabilities 9 to 11 with two shops,
were not in them; an export in psgdpr's admin screen on 9.2.0 (psgdpr cannot show one there; the Log's form is the
export on 9.x).

## Next, in this order

1. Capability 14: the multishop screens and the stage 5 screens in Estonian, and a native speaker's read.
2. A full install check (zip content, uninstall) on the tip, with the in-shop suite with debug on and off.

Price groups (CON-385) and stock alerts (CON-371) come after 3.0.0.

## Queue (technical, for the orchestrator)

- A `cart_add` of a product with combinations and no chosen combination gets a key that has no catalog row. A product
  that gets its first combination keeps its old row at the engine until the next nightly list.
- An erased failed row still shows the Retry checkbox. "Sign up for our newsletter" (checkout box) has no Estonian
  unit. Story 5.2 has no CHANGELOG or USERGUIDE entry.
- The Smaily client's own failure messages show in English in the Log; its Last Error column is narrow for a sentence.
  A simplification review of the Log, Automations and stage 5 code is due.
- The Dashboard's Queued today and Failed tiles and its failures verdict count the event queue only, not the rows
  that go to Campaign Intelligence (the Log's banner counts both). The Initial setup's first answer to "How are
  your shops run?" runs no layout change, so a store that held settings or a connection before it got a second
  shop keeps them under "all shops" (ARCHITECTURE 8.9).
- Clean-up (ARCHITECTURE 13): `Upgrade300`; the mock's transport passes other hosts on; one test leaves other cron
  tokens behind; screenshots for the user guide. The 2.x tab row stays in `tab`, inactive.

## First-release checklist (gauge: 13 / 14 demonstrated)

A box is ticked only when the real flow was exercised; the line names how. Decisions: ARCHITECTURE 15.

- [x] 1. Upgrade in place from 2.x, including settings left by 1.6.x — genuine 2.2.0 and 1.6.2 stores on 8.0.0, 8.2.8,
  9.2.0. Walked in a browser on 8.2.8 and 9.2.0 (2026-11-23): no second reminder for a cart 2.x reminded, 1.6.x
  settings under 2.2.0 code with their notices in English and Estonian, and "Finish upgrade". Still waits: the pilot
  store.
- [x] 2. Smaily account connection, with an invitation for a new store — browser, 8.0.0 and 9.2.0.
- [x] 3. Store mode by language (four modes) — walked in a browser on 8.2.8 and 9.2.0.
- [x] 4. Store mode by shop: one shared setup, or one per shop — two shops walked on 8.2.8 and 9.2.0.
- [x] 5. Consent mode: subscribers only, all customers, checkout opt-in only — HTTP, 8.0.0, 9.2.0.
- [x] 6. Contact sync both ways, first import, and a Log with retry — walked on 8.2.8 and 9.2.0.
- [x] 7. Store automations: welcome, first order, abandoned cart — walked on 8.2.8 and 9.2.0.
- [x] 8. Campaign Intelligence connection with catalog, customer and order imports — walked on the merged `v3` in a
  browser on 8.2.8 and 9.2.0 against the engine mock: the connection on step 4 and on Settings > Intelligence, the
  three imports on the tick URL (one row per combination, the customers in scope, 1169 orders newest first, the
  progress calls), live product, customer and order changes and a removal; two shops as separate stores on 9.2.0.
- [x] 9. Campaign Intelligence automations, listed from the engine — walked on the merged `v3` in a browser on 8.2.8
  and 9.2.0 against the engine mock, on a connection made on a screen: the engine's names, one automation saved with
  a Smaily workflow (held in test mode), one added at the engine between two loads
  (`docs/internal/walks/2026-11-23-capabilities-8-11.md`).
- [x] 10. Browse tracking and attribution of email clicks to orders — walked on the merged `v3` in a browser on
  8.2.8 and 9.2.0: nothing tracked while the confirmation is off, the relay's events, an email click's attribution on
  an order placed in the browser and on one a payment provider's server confirms, the token of a sign-in and of a
  guest checkout, a store's `consentOverride()`; and a genuine 2.2.0 store upgraded on 8.2.8 tracks nothing until
  the confirmation is saved on.
- [x] 11. Product recommendations in the storefront, and the product feed — walked on the merged `v3` in a browser
  on 8.2.8 and 9.2.0: the block where the merchant placed it in Design > Positions, with prices, the click's
  recommendation id, no block and no engine call with the confirmation off or for an opted-out shopper; the product
  feed is the RSS feed (owner, 2026-11-23: no second feed), walked under its legacy address in English and Estonian.
- [x] 12. Installation report (phone-home) — Story 6.1, walked on its branch on 8.2.8 and 9.2.0 against a mock
  receiver: the tick URL, saves in a browser, the console's uninstall. The real service was never called.
- [x] 13. Privacy: export, erasure, and a shopper's personalization opt-out — walked on the merged `v3` in a browser
  on 8.2.8 and 9.2.0, with ingest and storefront rows: the Log's form (export and erasure, with psgdpr enabled and
  disabled), the customer's own export page, the Personalization page, and psgdpr's admin screen. That screen's export
  is shown on 8.2.8 only: psgdpr 1.4.3 cannot show a customer on 9.2.0 (its own defect), where it erases by address
  and the Log's form is the back-office export.
- [ ] 14. Administration screens in English and Estonian — all strings in Estonian, walked on 8.2.8 and 9.2.0; waits:
  the multishop screens, a native reader, the stage 5 screens.

## Open questions (for the owner)

The workers' full notes are in ARCHITECTURE section 14; the owner's decisions of 2026-11-23 on the five behaviour
questions of the stage 5 Stories are in section 15. Before recommending on a behaviour rule, look up what the sibling
connectors do (CLAUDE.md, "One connector never changes a behaviour rule alone").

1. The cookie-banner confirmation at a store-layout change (ARCHITECTURE 14, row 58). Built as the narrowest
   reading: to separate stores it stays with the shop that keeps the connection; to one store it is removed, so
   tracking and recommendations are off in every shop until the merchant confirms again. The other reading carries
   one shop's confirmation over to all shops. A consent decision: the owner's.
2. Marketplace's six content rules and PrestaShop's online validator wait until the listing is planned (CON-379;
   `docs/MARKETPLACE.md` 6.3). The cross-connector analysis CON-397 decides the late or retried contact changes, the
   workflows after an account or mode change and two shops on one account for all connectors at once.

**Only the owner can do:** enable GitHub Actions on the fork; give a Smaily test account for a live walk (it also
shows whether the real service takes an installation report); ask the
engine team for the answer on INT-306 (`ps-`) and for the `source` value of PrestaShop's browse events; choose a pilot
store; have a native speaker read the Estonian.

Linear: Feature P-CON-324; Epics CON-373, CON-378, CON-381, CON-379, CON-380, CON-398 (Campaign Intelligence: CON-400,
CON-401, CON-407, CON-408 and CON-409 done; 5.3 is CON-405; 5.4 is CON-406), CON-402 (privacy, done), CON-403
(installation report: 6.1 is CON-404, built); cross-connector analysis CON-397 (with CON-394, CON-395, CON-396); engine
INT-306; subscription SUB-28 (answered). Gauge: 13/14.
