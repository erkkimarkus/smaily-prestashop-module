# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-23. Gauge 9/14._

## Next session opens with

**On `v3`, landed and pushed to the fork** (`0c38f0b`): stages 1 to 4 (upgrade in place, connection, store modes,
consent modes, contact sync, the Log, store automations with discount codes, Dashboard, Settings, Initial setup, the
Estonian round, the Marketplace structural rules, the database gateway) with the owner's decisions of 2026-11-21
(ARCHITECTURE section 15), and four Stories of stage 5, Campaign Intelligence, each exercised against an engine mock:

- 5.1 the engine client and the connection (made through `Engine\Setup`; no screen makes one yet);
- 5.2 ingest: product, customer and order changes, removals, the nightly product list, the identity merge;
- 5.5 storefront: consent-gated browse tracking through the store's relay, click attribution stored with the order,
  the "Recommended for you" block as a widget;
- 5.6 privacy: export and erasure through PrestaShop's GDPR module and a form on the Log, the customer's
  Personalization page.

The key namespace `ps-` is one constant (`Engine\Payload\Keys`) and is **not agreed with the engine team** (INT-306):
nothing that sends a key ships before the answer.

**Since then, on a worker branch (2026-11-23, not merged, not pushed):** a later migration run keeps the display hook
a merchant gave the recommendations block (ARCHITECTURE 3.7), and capability 13 was walked on the merged code in a
browser on 8.2.8 and 9.2.0 (ARCHITECTURE 11.2). Every privacy flow holds. One thing could not be shown: an export in
psgdpr's admin screen on 9.2.0, because psgdpr 1.4.3 itself fails there (facts file 6.13). The owner's answer
(2026-11-23): the Log's "Privacy request" form is always shown, also beside psgdpr, and capability 13 counts.

**Story 5.3, first part, on another worker branch (2026-11-23, rebased onto `v3` at `f947316`, not merged, not
pushed):** Settings > Intelligence and step 4 of the Initial setup connect a store to Campaign Intelligence, check and
end the connection, hold the merchant's cookie-banner confirmation, and show the catalog, customers and orders
imports, which a connection starts and the tick job `engine_import` runs (ARCHITECTURE 8.12 and 10.11). Walked in a
browser against the engine mock on 8.2.8 and 9.2.0 before the rebase, with one shop and with two (separate stores,
one store), in English and Estonian; screenshots in `build/screens-5.3/`.

**Last runs** (2026-11-23, on that branch after the rebase: the installation report, Story 5.4 and the first part of
Story 5.3 together): in-shop suite 2168 tests on 8.2.8 (11685 assertions) and on 9.2.0 (11690), with debug on, each
lane on a shop made from scratch behind the egress guard; host 2168 tests, 3794 assertions, 1101 skipped;
`node --test tests/js/` 43 pass; PHP CS Fixer 0 of 353 files. A browser smoke on both lanes, 18 checks each: a
connection made on Settings > Intelligence and its three import cards, Settings > Automations with the store
automations and the Campaign Intelligence card on that connection, the Dashboard, and a tick over its URL that ran
`engine_import` and `installation_report` (the report reached the subscription mock with the connection in it);
screenshots in `build/screens-5.3-merged/`. The suite with debug off last ran on `v3` before Story 5.4 (2090 tests).
Last run on `0c38f0b` (2026-11-21, 1957 tests): `tests/ci/validate.sh` 10 of 10 on both flashlight images. The zip
content and the uninstall check last ran on `0d4425e`, long before stage 5.

**NOT verified — keep these visible:** the live Smaily service and the live engine have never been called (every check
used a mock); GitHub Actions on the fork are not enabled, so CI has never run; MySQL (only MariaDB ran); LiteSpeed;
the official `9.2.0-8.1` image; PrestaShop's online validator; the multishop screens in Estonian; one unexplained PHP
8.0.30 segfault report (not reproduced in 113 runs). Of stage 5: capabilities 10 and 11 have no walk of their own on
the merged `v3` (the walk of 13 used the landing, the relay, the order's attribution and the ingest, and did not judge
them); an export in psgdpr's admin screen on 9.2.0 (psgdpr cannot show one there; the Log's form is the export
on 9.x); "tracking is off after an upgrade"
is shown by tests, not by a browser upgrade.

## Next, in this order

1. Story 5.3, second part (the first part is on a worker branch, above): the Campaign Intelligence invitation card
   on the Dashboard and the other tabs with its 30-day dismissal, the Dashboard's Campaign Intelligence and Browse
   tracking cards and Catalog tile, and what a store-layout change does with the connections (owner, 2026-11-23:
   the selected or default shop's connection is carried, the others are ended). Story 5.4, the card "Campaign
   Intelligence Automations" on Settings > Automations, is built (ARCHITECTURE 10.10).
2. Capability walks on the merged `v3`: 8, 10 and 11 now that the connection, the imports and the consent switch
   have their screen (Story 5.3, first part); 9 with a connection made on that screen.
3. Capability 14: the multishop screens and the stage 5 screens in Estonian, and a native speaker's read.
4. A full install check (zip content, uninstall) on the tip.

Price groups (CON-385) and stock alerts (CON-371) come after 3.0.0.

## Queue (technical, for the orchestrator)

- A `cart_add` of a product with combinations and no chosen combination gets a key that has no catalog row. A product
  that gets its first combination keeps its old row at the engine until the next nightly list.
- An erased failed row still shows the Retry checkbox. "Sign up for our newsletter" (checkout box) has no Estonian
  unit. Story 5.2 has no CHANGELOG or USERGUIDE entry. `ReconcileCaptureTest` teardown fails on a walked shop.
- `composer package` deletes the whole `build/` folder, the kept screenshots in `build/screens/` with it.
- The Smaily client's own failure messages show in English in the Log; its Last Error column is narrow for a sentence.
  A simplification review of the Log, Automations and stage 5 code is due.
- Clean-up (ARCHITECTURE 13): `Upgrade300`; the mock's transport passes other hosts on; one test leaves other cron
  tokens behind; screenshots for the user guide. The 2.x tab row stays in `tab`, inactive; the 1.6.x hidden-tab
  removal never ran on a real 1.6.2.

## First-release checklist (gauge: 9 / 14 demonstrated)

A box is ticked only when the real flow was exercised; the line names how. Decisions: ARCHITECTURE 15.

- [x] 1. Upgrade in place from 2.x, including settings left by 1.6.x — genuine 2.2.0 and 1.6.2 stores on 8.0.0, 8.2.8,
  9.2.0. Still waits: "Finish upgrade" (3.1); the pilot store.
- [x] 2. Smaily account connection, with an invitation for a new store — browser, 8.0.0 and 9.2.0.
- [x] 3. Store mode by language (four modes) — walked in a browser on 8.2.8 and 9.2.0.
- [x] 4. Store mode by shop: one shared setup, or one per shop — two shops walked on 8.2.8 and 9.2.0.
- [x] 5. Consent mode: subscribers only, all customers, checkout opt-in only — HTTP, 8.0.0, 9.2.0.
- [x] 6. Contact sync both ways, first import, and a Log with retry — walked on 8.2.8 and 9.2.0.
- [x] 7. Store automations: welcome, first order, abandoned cart — walked on 8.2.8 and 9.2.0.
- [ ] 8. Campaign Intelligence connection with catalog, customer and order imports — built (5.1, 5.2, 5.3 first
  part: the Intelligence tab and step, the three imports); waits: its walk on the merged `v3`.
- [ ] 9. Campaign Intelligence automations, listed from the engine — built (5.4): the Story's own walk ran in a
  browser on 8.2.8 and 9.2.0 against the engine mock, with a connection made by script. Waits: the capability walk on
  the merged `v3`.
- [ ] 10. Browse tracking and attribution of email clicks to orders — built (5.5, 5.2), and the merchant's consent
  switch has its screen (5.3 first part); waits: a walk on the merged `v3`.
- [ ] 11. Product recommendations in the storefront, and the product feed — the block is built (5.5); the product
  feed is the RSS feed of Settings > RSS (owner, 2026-11-23: no second feed); waits: the same walk.
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

1. Marketplace's six content rules and PrestaShop's online validator wait until the listing is planned (CON-379;
   `docs/MARKETPLACE.md` 6.3). The cross-connector analysis CON-397 decides the late or retried contact changes, the
   workflows after an account or mode change and two shops on one account for all connectors at once.

**Only the owner can do:** enable GitHub Actions on the fork; give a Smaily test account for a live walk (it also
shows whether the real service takes an installation report); ask the
engine team for the answer on INT-306 (`ps-`) and for the `source` value of PrestaShop's browse events; choose a pilot
store; have a native speaker read the Estonian.

Linear: Feature P-CON-324; Epics CON-373, CON-378, CON-381, CON-379, CON-380, CON-398 (Campaign Intelligence: CON-400
and CON-401 done; 5.3 is CON-405; 5.4 not filed yet), CON-402 (privacy, done); cross-connector analysis CON-397 (with CON-394,
CON-395, CON-396); engine INT-306; subscription SUB-28 (answered). Gauge: 9/14.
