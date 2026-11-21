# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-21 (the owner's decisions are in the code). Gauge 7/14. In flight: Story 5.1._

## Next session opens with

**On `v3`, landed and pushed to the fork** (`345c727`): the night run of 2026-11-21 (Dashboard; Settings with four
tabs; the Log; the Initial setup steps Connect, Contacts, Automations, Overview; discount codes in store automations —
CON-393, nine of ten criteria, the privacy one waits for Story 5.6; the Estonian round; the Marketplace structural
rules; upgrade and security hardening; the database gateway `Database\Sql`) and the owner's 23 decisions of the same
day, each with its date in ARCHITECTURE section 15: contacts and consent as the sibling connectors, language modes and
account changes as Magento, the upgrade and data rules (a saved account survives an unfinished upgrade, closed
cart-tracker rows go after 30 days, import jobs are kept, 30 hooks, the upgrade deletes the files 2.2.0 and 1.6.2
left).

**In flight:** Story 5.1 (the engine client, the connection, an engine mock, the engine host in the egress guard) on
a worker branch; Linear Epic CON-398. The owner started stage 5 before the engine team answered INT-306: the key
namespace `ps-` stays in one constant and nothing that uses it ships.

**Last runs** (each landing on its own rebased branch, 2026-11-21): in-shop suite 1513 tests on 8.2.8 and on 9.2.0
with debug on and off; `tests/ci/validate.sh` 10 of 10 on both flashlight images; host 1513 tests, 2358 assertions,
807 skipped. Capability 3 was walked again after the decisions: 23 of 23 on both versions. The zip content and the
uninstall check last ran on `0d4425e`, before the database gateway and the decisions (the zip holds 336 files since).

**NOT verified — keep these visible:** the live Smaily service has never been called (every check used the mock);
GitHub Actions on the fork are not enabled, so CI has never run; MySQL (only MariaDB ran); LiteSpeed; the official
`9.2.0-8.1` image; PrestaShop's online validator (it needs an upload); the multishop screens in Estonian; one
unexplained PHP 8.0.30 segfault report (not reproduced in 113 runs).

## Next, in this order

1. Land Story 5.1; then 5.2 ingest, 5.5 storefront and 5.6 privacy in parallel; then 5.3 history import with the
   Intelligence tab and 5.4 engine automations. 6.1 installation report may start after 5.1 (SUB-28 is answered:
   report contract v1.2.0). Price groups (CON-385) and stock alerts (CON-371) come after 3.0.0.
2. Capability 14: the multishop screens in Estonian, and a native speaker's read of the module's own sentences.
3. A full install check (zip content, uninstall) on the tip once the first stage 5 Stories are in.

## Queue (technical, for the orchestrator)

- The Smaily client's own failure messages show in English in the Log; its Last Error column is narrow for a sentence.
  A simplification review of the Log and Automations code is due.
- Clean-up (ARCHITECTURE 13): `Upgrade300`; the mock's transport passes other hosts on;
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

Decided on 2026-11-21: the 23 questions of the night run (ARCHITECTURE section 15 holds each decision). Still open:

1. Leaving the mode "an account per language" now deletes the languages' account rows, as Magento's confirmation
   text says: confirm.
2. The upgrade deletes 47 files, not the 35 named when the owner decided: the 12 more are files only 1.6.2 shipped
   (it unpacks into the same folder): confirm.
3. Marketplace's six content rules (the licence GPL-3.0, the public RSS feed URL without a token, the literal SQL
   casts, the one `unserialize` for 1.6.x settings, the external links, the guide as a PDF) and PrestaShop's online
   validator wait until the listing is planned (CON-379; `docs/MARKETPLACE.md` 6.3).
4. The cross-connector analysis CON-397 decides for all connectors at once: late or retried contact changes, workflows
   after an account or language-mode change, two shops on one Smaily account with different consent modes. Until then
   PrestaShop behaves as the siblings.
5. Multi-currency orders: Story 5.2 follows what the sibling connectors send.

**Only the owner can do:** enable GitHub Actions on the fork; give a Smaily test account for a live walk; ask the
engine team for the answer on INT-306; choose a pilot store; have a native speaker read the Estonian.

Linear: Feature P-CON-324; Epics CON-373, CON-378, CON-381 (with CON-393), CON-379, CON-380, CON-398 (Campaign
Intelligence); cross-connector analysis CON-397 (with CON-394, CON-395, CON-396); engine INT-306; subscription SUB-28
(answered). Gauge: 7/14.
