# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-20 (session end) — stages 1 and 2 done; admin shell and
Initial setup done; store automations built; three workers in flight; gauge 3/14._

## Next session opens with

**Two workers were still running when the session of 2026-11-20 ended.**
Their work is NOT on `v3`. First act of the next session: `git worktree list`,
then for each branch below compare with `v3` (`git log v3..<branch>`), read
the worker's final report if it arrived, and land finished work by
cherry-pick (run the host unit tests after each; push to `origin`).

| Worktree branch | Work | Notes |
|---|---|---|
| `worktree-agent-a2e0957c6cbbb9651` | Dashboard content (Story 3.2, second half) | Screenshots in `build/screens/3.2-dashboard/` |
| `worktree-agent-ad9811a9450936b49` | Settings: Connection, Contacts, RSS; deletes the legacy 2.x admin page | Screenshots in `build/screens/3.3/` |

**DEFECT TO VERIFY AND FIX FIRST (reported by the first-order worker, observed
on 9.2.0):** the after-response work — `Scheduler\TrafficTick::afterResponse()`
and, by the same pattern, `Contacts\Capture\ContactEvents::afterResponse()` —
runs in a PHP shutdown function and calls `fastcgi_finish_request()` before
PrestaShop's cookie destructor has written the session. When a tick was due
on the request, a shopper who registered or logged in got no session id in
the cookie and was logged out on the next request. Reproduce on 8.x and
9.2.0, fix (write the cookie before finishing the request), add a browser
test of login and registration with a due tick. Nothing ships before this.

A branch with no commits beyond its base was interrupted: dispatch that
Story again from its section in `docs/ARCHITECTURE.md` 13. Leftover docker
stacks of those workers are named `smaily-presta-fo*`, `-dash*`, `-s33*`.

On `v3` and pushed (159 commits ahead of the official `master` at the last
count): stage 1 with its clean-up; stage 2 complete (scope, consent policy,
capture hooks, reconcile, first import); the admin shell with the upgrade
banner and the new-version notice; the Initial setup steps Connect, Contacts
and Overview; automation routing; welcome, first-order and abandoned-cart
automations; the checkout address capture. Last verified in a shop: 941
tests on 8.0.0, 8.2.8 and 9.2.0 before the Initial setup landed (774 on its
own branch); the merged tip was run on the host only (1019 tests, 489
skipped). **Run the full in-shop suite and `tests/ci/validate.sh` on the
merged tip on one 8.x and on 9.2.0 before anything else lands.**

Then, in this order: (1) Log page (Story 3.4) and the Automations tab
(workflow lists use Magento's call `workflows.php?trigger_type=form_submitted`);
(2) walk capabilities 3, 4, 6 and 7 end to end through the screens and tick
them; (3) one document pass — ARCHITECTURE, DEV_ENVIRONMENT, CHANGELOG and
this file lag everything since the contact Stories; (4) Marketplace
technical rules (CON-379); (5) the deferred `Upgrade300` clean-up and the
test-infrastructure items in the Queue below; (6) stage 5, Campaign
Intelligence, with group prices (CON-385, contract 1.36.0) and the identity
merge from the captured checkout address (only where tracking is allowed).

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
