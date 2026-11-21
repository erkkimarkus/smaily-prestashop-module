# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-21 — stages 1 and 2 done; the admin screens built
(Dashboard, Initial setup, Settings, Log); the three store automations built
with their Automations tab; gauge 3/14._

## Next session opens with

On `v3`: stages 1 and 2; the admin shell, Initial setup, Dashboard, Settings
(Connection, Contacts, Automations, RSS, shop switches, layout change) and Log;
the three store automations; the upgrade hardening; the stale-subscription
skip; the Marketplace structural rules. In flight (2026-11-21): the PHP 8.0.30
segfault on the 8.2.8 test lane, and discount codes (CON-393).
Last verified: in-shop 1293 tests, 6245 assertions on a fresh 8.2.8 and 9.2.0;
host 1293 tests, 2012 assertions, 636 skipped; `tests/ci/validate.sh` one
landing earlier, 10 of 10 on both. Egress guard first, suite on a reset shop.

Then, in this order: (1) discount codes (CON-393, Story 4.4; decided: a
registered customer's code is tied to the customer, a guest's is single-use and
untied; codes show in the discount list under a "Smaily Connect" name; unused
codes go 30 days after expiry); (2) walk capabilities 3, 4, 6 and 7 through the
screens and tick them, and check against ARCHITECTURE 6.x and the siblings that
a newsletter-block subscriber without an account gets the welcome email only
with "Include guests" on (an existing rule); (3) Estonian targets and an
on-screen check for every v3 admin string, sentences for the Log's reason codes
that still show as codes, a cron line without the admin's language prefix;
(4) a simplification review of the Log and Automations code; (5) stage 5 (group
prices, CON-385, contract 1.36.0) only after the owner says so.

## Queue (technical, for the orchestrator)

- **Not verified:** MySQL (queue claim, `GET_LOCK`, upgrade); the official
  9.2.0-8.1 image after the event queue; LiteSpeed; the 1.6.x hidden-tab
  removal on a real 1.6.2 install; the 2.x admin tab's row after an upgrade.
- **The live Smaily API has never been called**; every run used the mock.
- **Clean-up** (ARCHITECTURE 13): `Upgrade300`; the janitor skips two tables;
  two hooks without a listener; the unwired 2.x classes; screenshots for the
  user guide (five 2.x images are unreferenced); CLAUDE.md rule 4 says v1.35.0.

## First-release checklist (gauge: 3 / 14 demonstrated)

A box is ticked only when the real flow was exercised; a line names how.

- [x] 1. Upgrade in place from 2.x, including settings left by 1.6.x
  - Demonstrated by: genuine 2.2.0 and 1.6.2 stores upgraded on 8.0.0, 8.2.8
    and 9.2.0; an interrupted upgrade completed by a cron URL and by a
    storefront request. Still waits: "Finish upgrade" (3.1); the pilot store.
- [x] 2. Smaily account connection, with an invitation for a new store
  - Demonstrated by: the Initial setup walked in a browser on 8.0.0 and 9.2.0
    against the Smaily mock (connect, test, wrong credentials, invitation).
- [ ] 3. Store mode by language (four modes)
- [ ] 4. Store mode by shop: one shared setup, or one per shop
- [x] 5. Consent mode: subscribers only, all customers, checkout opt-in only
  - Demonstrated by: the mode chosen in the Initial setup; registration,
    guest checkout, account edit, newsletter block and orders walked over
    HTTP on 8.0.0 and 9.2.0 against the Smaily mock. Not the live Smaily.
- [ ] 6. Contact sync both ways, first import, and a Log with retry
- [ ] 7. Store automations: welcome, first order, abandoned cart
- [ ] 8. Campaign Intelligence connection with catalog, customer and order
  imports
- [ ] 9. Campaign Intelligence automations, listed from the engine
- [ ] 10. Browse tracking and attribution of email clicks to orders
- [ ] 11. Product recommendations in the storefront, and the product feed
- [ ] 12. Installation report (phone-home)
- [ ] 13. Privacy: export, erasure, and a shopper's personalization opt-out
- [ ] 14. Administration screens in English and Estonian

## Staged work order

0. **Foundation** — _Done (CON-370)._
1. **Upgrade-safe core** — _Done (1.1–1.8); `Upgrade300` clean-up deferred._
2. **Contacts and modes** — _Done (2.1–2.4)._
3. **Admin screens** — _Dashboard, setup, Settings, Log done; no ET sweep._
4. **Store automations and language routing** — _Built; discount codes next._
5. **Intelligence, tracking, recommendations, privacy.**
6. **Release** — installation report, upgrade guide, rc1, upgrade walk.

## Decisions (2026-11-20)

Recorded in `docs/ARCHITECTURE.md` section 15 (10–16 and 19–22 are Erkki's,
all on 2026-11-20). "≠": the owner overrode the agent-side proposal.

1. Background tasks: cron URL plus a visitor-traffic fallback tick.
2. ≠ Consent: merchant-confirmed setting plus `consentOverride()`.
3. Abandoned cart after upgrade: the siblings' consent rule; fewer reminders.
4. 1.6.x leftovers: switch on only what the running code sent, with notices.
5. 2.x opt-in without a workflow: keeps the generic opt-in trigger.
6. Product keys: one row per combination; format per contract 1.35.0.
7. Campaign Intelligence: one connection per separately set-up shop.
8. Uninstall removes all module data after the disconnect and the report.
9. ≠ Scope: multishop "separate stores" is in the first release.
10. Gauge: the 14 capabilities. Stock alerts (§6c, CON-371): right after 3.0.0.
11–12. Reconcile two-way in "Subscribers only"; first run: unsubscribes only.
13–14. A guest-checkout customer is sent only with "include guests" on.
15. New-version notice from GitHub now; Smaily's own notice later (CON-380).
16. The code follows the Marketplace technical rules from the start (CON-379).
17. Admin colours: the Campaign Intelligence palette, own components (CON-377).
18. Contract copy re-synced: v1.36.0 (price groups); §6c is on at the engine.
19. Admin screens show a contact's address as the siblings do.
20. Separate stores → one store removes other shops' own settings (Magento).
21. The wording of the consent-mode and layout confirmations is approved.
22. Discount codes in store automations are in 3.0.0, a Story of their own.

Orchestrator (technical, reversible): the upgrade script never fails (3.7);
`language` is `NOT NULL DEFAULT ''` (2.2); the menu sits under Customers
(10.1); a shop on another Smaily account gets contact sync off after the
upgrade, with a notice (3.3; the owner may overturn it).

## Known 2.2.0 defects that 3.0.0 closes

- Closed on `v3`: an empty stored cron token opens the cron URL; uninstall
  deletes an override file it may not own; customer sync pushes every shop's
  customers with one shop's configuration; the cart cron fataled on 1.6.x
  serialized field lists (the 2.x cart code no longer runs).
- The `NW_*` keys of ps_emailsubscription are never restored (a notice).

## Open questions (for Erkki)

1. A late unsubscribe that arrives after a newer, delivered subscription
   leaves the contact unsubscribed — keep it so? (ARCHITECTURE 14, row 23.)
2. The Log has two guard rules beyond Magento — no "Send again" that would
   re-subscribe, no second queue entry for a waiting event — confirm them?
3. A Smaily account saved while an upgrade is unfinished is replaced by the
   upgrade, or stops it at the verify step — which should it be? (3.7.)
4. Marketplace: ten rules need your decision (`docs/MARKETPLACE.md` 6.3): the
   licence, the public feed address, the 27 files 2.2.0 leaves, the SQL casts…
5. The amber and red Background tasks card now hide the cron line behind the
   same button as the green one — keep?
6. In language mode `a` the router still ends at the no-language workflow,
   which Magento never starts and the tab hides — remove it? (14, row 24.)
7. The engine team (INT-306) and subscription team (SUB-28) asks are unanswered.
8. GitHub Actions on the fork are not enabled: CI on `v3` has never run.
9. The live Smaily service has never been called; it needs your test account.
10. A pilot store: one PrestaShop 8 store on 2.x asked about abandoned cart.

Linear: P-CON-324; CON-373 (upgrade), CON-378 (contacts), CON-379 (Marketplace),
CON-380, CON-393, CON-385, CON-371, CON-377; INT-306; SUB-28. Gauge: 3/14.
