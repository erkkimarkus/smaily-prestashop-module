# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-21 — stages 1 and 2 done; Dashboard, Initial setup and
Settings (Connection, Contacts, RSS) done; store automations built; the Log
page built, on its branch; the Automations tab built, on its branch; gauge
3/14._

## Next session opens with

On `v3`: stages 1 and 2; the admin shell, the Initial setup and the Dashboard
with its corrections (a contact's address shown as the siblings do; one cron
line, whose token the upgrade creates); the 8.2.8 admin fix; the login-cookie
fix; Settings (Story 3.3: Connection, Contacts, RSS, shop switches, layout
change; the 2.x admin page is gone); the three store automations.
Built on the branch `worktree-agent-af9375eb57f45d7cb`: the Automations tab of
Settings and step 3 of the Initial setup (Story 4.1, ARCHITECTURE 10.9), walked
on 8.2.8 and 9.2.0 against the mock; with it, the layout change merges the
workflow rows as Magento does, and "Test connection" uses Magento's call.
Built and not yet on `v3`: the Log page (Story 3.4), on the branch
`worktree-agent-a0d4f2246a378b7d7`; land it by cherry-pick.

Last verified with the Automations tab on its branch's tip: in-shop 1293 tests,
6245 assertions on a fresh 8.2.8 and a fresh 9.2.0. `tests/ci/validate.sh` last
ran with Settings on the tip, 10 of 10 on both.
Load the egress guard (`docs/DEV_ENVIRONMENT.md` 4a) before any admin walk.

Then, in this order: (1) discount codes in store automations (Story 4.4;
design with the owner first); (2) walk capabilities 3, 4, 6 and 7 through the
screens and tick them; (3) Estonian targets for every v3 admin string (the
XLIFF holds the 2.x strings, four v3 units and the texts of the Log and of the
Automations screens; capability 14); (4) the document pass (ARCHITECTURE 1.1
and 13 and the user guide lag); (5) Marketplace rules (CON-379); (6) stage 5,
Campaign Intelligence, with group prices (CON-385, contract 1.36.0).

## Queue (technical, for the orchestrator)

- **Not verified:** MySQL (queue claim, `GET_LOCK`, upgrade); the official
  9.2.0-8.1 image with any code after the event queue; LiteSpeed; the 1.6.x
  hidden-tab removal on a real 1.6.2 install (ARCHITECTURE 14, row 15).
- **The live Smaily API has never been called**; every run used the mock.
- **Open follow-ups:** the live-sync guest gates take effect only once Story
  2.3 sets `guest`; the unsubscriber list is paged by page number, so a list
  that shrinks during one first run can skip one address (2.x had the same).

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
3. **Admin screens** — _Dashboard, setup, Settings done; Log in flight; no ET._
4. **Store automations and language routing** — _Built, with the Automations tab._
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
18. Contract copy re-synced: still v1.35.0; §6c built and switched off.
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

1. **Engine team** (one-way door: shared contract), Linear INT-306: three
   questions still open — the platform key namespace `ps-`; the nightly
   manifest of connections with the empty prefix; multi-currency orders.
2. **Subscription team**, Linear SUB-28: the PrestaShop row of the
   installation report, and later the answer on a Smaily-written notice.
3. **Pilot store:** one interested store on PrestaShop 8 with module 2.x
   asked about abandoned cart.
4. **Maintain rights on the official repo:** requested at the first release;
   the orchestrator files that request then.
5. **GitHub Actions on the fork:** not enabled; CI on `v3` has never run.

Linear: Feature P-CON-324; Epics CON-373 (upgrade; four of seven criteria
met) and CON-378 (contacts); CON-379 (Marketplace rules); CON-380
(new-version notice); CON-371 (stock alerts, right after 3.0.0); CON-377
(colours); engine ask INT-306; SUB-28. Gauge: 3/14.
