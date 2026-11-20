# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-20 — stage 1 done with its clean-up; Stories 2.1,
2.2 and 2.4 done; Stories 3.1 and 4.1 (backend) in progress; gauge 1/14._

## Next session opens with

**Stage 1:** done, including the clean-up (Story 1.8). Deferred: the
`Upgrade300` clean-up, after the admin shell lands.

**Stage 2:** Stories 2.1, 2.2 and 2.4 are done, with the owner's consent
decisions applied (ARCHITECTURE 6.2, 15). Story 2.3 (capture hooks) is next.
It waits for the admin shell, because both edit the main class. Only after
2.3 can capabilities 5 and 6 (contacts) be walked end to end and ticked.

**Stage 3:** Story 3.1 (admin shell) in progress, built to the sibling
screens' structure (vertical step rail, full Dashboard composition, the
siblings' invitation banner); its real screenshots go beside Magento's.
**Stage 4:** Story 4.1 backend (routing, mapping) in progress.

**Queued:**
1. The new-version notice on the admin pages (CON-380), after Story 3.1. The
   daily check behind it is built.
2. The Marketplace technical rules (CON-379). It touches every file, so it
   starts after the running Stories land (`docs/MARKETPLACE.md`).
3. The deferred `Upgrade300` clean-up (ARCHITECTURE 13, Story 1.8).
4. Test infrastructure: the Smaily mock's transport refuses other hosts; the
   test that leaves other cron tokens behind restores them.

**Now in the code:** the 3.0.0 runtime with the event queue, the tick and its
three doors, the upgrade, the uninstall, the scope resolver, the consent
policy and the `contact.sync` handler, the reconcile from Smaily, the first
import and the daily refresh, and the daily new-version check. Store changes
do not reach the queue until Story 2.3, and no abandoned-cart reminder goes
out until Story 4.3.

**Last verified runs** (as the worker that ran them reported): in-shop suite
585 tests / 2333 assertions on PrestaShop 8.2.8 and 9.2.0;
`tests/ci/validate.sh` 10 of 10 checks on both. PHPStan does not run.

## Queue (technical, for the orchestrator)

- **Not verified:** MySQL (queue claim, `GET_LOCK`, upgrade); the official
  9.2.0-8.1 image with any code after the event queue; LiteSpeed; the 1.6.x
  hidden-tab removal on a real 1.6.2 install (ARCHITECTURE 14, row 15).
- **The live Smaily API has never been called**; every run used the mock.
- **Open follow-ups:** the live-sync guest gates take effect only once Story
  2.3 sets `guest`; the unsubscriber list is paged by page number, so a list
  that shrinks during one first run can skip one address (2.x had the same).

## First-release checklist (gauge: 1 / 14 demonstrated)

A box is ticked only when the real flow was exercised; a line names how.

- [x] 1. Upgrade in place from 2.x, including settings left by 1.6.x
  - Demonstrated by: genuine 2.2.0 and 1.6.2 stores upgraded on 8.0.0, 8.2.8
    and 9.2.0; an interrupted upgrade completed by a cron URL and by a
    storefront request. Still waits: "Finish upgrade" (3.1); the pilot store.
- [ ] 2. Smaily account connection, with an invitation for a new store
- [ ] 3. Store mode by language (four modes)
- [ ] 4. Store mode by shop: one shared setup, or one per shop
- [ ] 5. Consent mode: subscribers only, all customers, checkout opt-in only
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
2. **Contacts and modes** — _2.1, 2.2, 2.4 done; 2.3 next, after 3.1._
3. **Admin screens** (Dashboard, setup, Settings, Log; EN + ET) — _3.1 running._
4. **Store automations and language routing** — _4.1 backend in progress._
5. **Intelligence, tracking, recommendations, privacy.**
6. **Release** — installation report, upgrade guide, rc1, upgrade walk.

## Decisions (2026-11-20)

Recorded in `docs/ARCHITECTURE.md` section 15 (11–16 are Erkki's, all on
2026-11-20). "≠" marks where the owner overrode the agent-side proposal.

1. Background tasks: cron URL plus a visitor-traffic fallback tick.
2. ≠ Consent: merchant-confirmed setting plus `consentOverride()`.
3. Abandoned cart after upgrade: the siblings' consent rule; fewer reminders.
4. 1.6.x leftovers: switch on only what the running code sent, with notices.
5. 2.x opt-in without a workflow: keeps the generic opt-in trigger.
6. Product keys: one row per combination; format per contract 1.35.0.
7. Campaign Intelligence: one connection per separately set-up shop.
8. Uninstall removes all module data after the disconnect and the report.
9. ≠ Scope: multishop "separate stores" is in the first release.
10. Gauge: the 14 capabilities. Stock alerts (§6c) are out (CON-371).
11. Reconcile two-way in "Subscribers only": Smaily re-subscribe → `newsletter = 1`.
12. First reconcile per account: unsubscribes only, from Smaily's list.
13. "All customers": a guest-checkout customer only with "include guests" on.
14. "Subscribers only": a ticked-box guest only with "include guests" on.
15. New-version notice from GitHub now; Smaily's own notice later (CON-380).
16. The code follows the Marketplace technical rules from the start (CON-379).
17. Admin colours: the Campaign Intelligence palette, own components (CON-377).
18. Contract copy re-synced: still v1.35.0; §6c built and switched off.

Orchestrator (technical, reversible): the upgrade script never fails (3.7);
`language` is `NOT NULL DEFAULT ''` (2.2); the menu sits under Customers
(10.1); a shop on another Smaily account gets contact sync off after the
upgrade, with a notice (3.3; the owner may overturn it).

## Known 2.2.0 defects that 3.0.0 closes

- Closed on `v3`: an empty stored cron token opens the cron URL; uninstall
  deletes an override file it may not own; customer sync pushes every shop's
  customers with one shop's configuration.
- The cart cron fataled on 1.6.x serialized field lists: the 2.x cart code
  no longer runs, and Story 4.3 builds its successor.
- The `NW_*` keys of ps_emailsubscription are never restored (a notice).

## Open questions (for Erkki)

1. **Engine team** (one-way door: shared contract), Linear INT-306: three
   questions still open — the platform key namespace `ps-`; the nightly
   manifest of connections with the empty prefix; multi-currency orders.
2. **Subscription team**, Linear SUB-28: the PrestaShop row of the
   installation report, and later the answer on a Smaily-written notice.
3. **Abandoned cart and guests** (before Story 4.3): does the reminder also
   apply the guest rule of the live sync?
4. **Pilot store:** one interested store on PrestaShop 8 with module 2.x
   asked about abandoned cart.
5. **Maintain rights on the official repo:** requested at the first release;
   the orchestrator files that request then.
6. **GitHub Actions on the fork:** not enabled; CI on `v3` has never run.

Linear: Feature P-CON-324; Epics CON-373 (upgrade; four of seven criteria
met) and CON-378 (contacts); CON-379 (Marketplace rules); CON-380
(new-version notice); CON-371 (stock alerts, out of 3.0.0); CON-377
(colours); engine ask INT-306; SUB-28. Gauge: 1/14.
