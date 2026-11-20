# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-20 — stage 1 done; Story 2.1 done; Story 2.2 in
progress; gauge 1/14._

## Next session opens with

**Done:** stage 0 (CON-370) and stage 1 on `v3`: Stories 1.1–1.7, an
integration pass and the queue deadlock fix. Story 2.1 (scope) is done.
Story list and states: `docs/ARCHITECTURE.md` section 13.

**In progress:** Story 2.2 (consent policy and contact payload) in its own
worktree. Not done.

**In parallel:** a simplification review of the stage 1 code. Its accepted
fixes land as one commit together with Story 1.8 (stage 1 clean-up,
ARCHITECTURE 13).

**Next:**
1. Story 2.3 (capture hooks, the `actionNewsletterRegistrationBefore` hook,
   the flush at the end of a request that enqueued a row) and Story 2.4
   (reconcile, daily refresh, first import).
2. Story 1.8 with the review's fixes, before stage 3.
3. Stage 3 (admin shell first) waits for the owner's go-ahead and the GMS-66
   logo package.

**Now in the code:** the 3.0.0 runtime: 31 hooks through `Hook\Dispatcher`,
9 tables, the event queue, the tick with its three doors and the fallback
tick, the upgrade from 2.x and 1.6.x, the uninstall, the scope resolver.
Until stage 2 and Story 4.3 register their jobs, a 2.x crontab runs the tick
but sends no customer sync and no abandoned-cart reminder. 2.x pieces still
in the tree: the admin configuration page and the RSS feed (wired); the 2.x
sync controllers and models (unwired; ARCHITECTURE 13, Story 1.8).

**Last verified runs:** in-shop suite 278 tests / 1337 assertions on 8.0.0,
8.2.8 and 9.2.0 at the stage 1 integration commit; 350 tests / 1399
assertions on 8.2.8 and 9.2.0 after Story 2.1 (as that Story reported).
`tests/ci/validate.sh`: 10 checks. PHPStan does not run.

## Queue (technical, for the orchestrator)

- **203 rule in two places.** "Smaily code 203 is permanent" is in both
  `Queue\Failure::PERMANENT_ENVELOPE_CODES` and `Smaily\Exception\ApiException`.
  Settle one home (Story 2.2 or 1.8).
- **Not verified:** MySQL (queue claim, `GET_LOCK`, upgrade); the official
  9.2.0-8.1 image with the stage 1 code; LiteSpeed; the 1.6.x hidden-tab
  removal on a real 1.6.2 install (ARCHITECTURE 14, row 15).

## First-release checklist (gauge: 1 / 14 demonstrated)

A box is ticked only when the real flow has been exercised; a ticked box
gets a line under it that names how.

- [x] 1. Upgrade in place from 2.x, including settings left by 1.6.x
  - Demonstrated by: genuine 2.2.0 and 1.6.2 stores upgraded on PrestaShop
    8.0.0, 8.2.8 and 9.2.0; an interrupted upgrade completed by a cron URL
    and by a storefront request.
  - Still waits: the admin "Finish upgrade" notice (stage 3, Story 3.1);
    the pilot store.
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
1. **Upgrade-safe core** — _Done (1.1–1.7); clean-up Story 1.8 pending._
2. **Contacts and modes** — _In progress (2.1 done, 2.2 running)._
3. **Admin screens** — Dashboard, Initial setup, Settings, Log; EN + ET.
4. **Store automations and language routing.**
5. **Intelligence, tracking, recommendations, privacy.**
6. **Release** — installation report, upgrade guide, rc1, upgrade walk.

## Decisions (2026-11-20)

Recorded in `docs/ARCHITECTURE.md` section 15. "≠" marks where the owner's
input differed from the agent-side proposal.

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
11. Reconcile is two-way in subscribers-only mode: a re-subscribe in Smaily
    sets `newsletter` 0 → 1 in the store (Erkki).
12. Admin colours: the Campaign Intelligence palette, for the module's own
    components only (Linear CON-377, owner).
13. Contract copy re-synced: still v1.35.0; §6c "built and switched off";
    this repository is in the contract's synchronized list.

Orchestrator decisions (technical, reversible): the upgrade script never
fails (ARCHITECTURE 3.7); `language` is `NOT NULL DEFAULT ''` (2.2); the
menu sits under Customers (10.1); a shop whose own Smaily account differs
from the default shop's gets contact sync off with a notice after the
upgrade (3.3; the owner may overturn it).

## Known 2.2.0 defects that 3.0.0 closes

- An empty stored cron token lets the cron URL run without a token. _Closed
  on `v3`._
- The cart cron fatals on stores holding 1.6.x serialized field lists.
- Uninstall deletes an override file it may not own. _Closed on `v3`._
- Customer sync pushes every shop's customers with one shop's configuration.
- The `NW_*` keys of ps_emailsubscription are never restored (3.0.0 leaves a
  notice).

## Open questions (for Erkki)

1. **Engine team** (one-way door: shared contract), Linear INT-306: the
   synchronized-list point is answered by the contract re-sync. Still open:
   the platform key namespace `ps-`; the nightly manifest of connections
   with the empty prefix; multi-currency orders.
2. **Subscription team**, Linear SUB-28: the installation-report registry
   lacks PrestaShop (`connect_prestashop`, `prestashop`, a shop field).
3. **Pilot store** — which store, and its PrestaShop and PHP versions.
4. **Maintain rights on the official repo** — read-only today.
5. **GitHub Actions on the fork** — not enabled; CI on `v3` is unverified.
6. **Stage 3 go-ahead** and the GMS-66 logo package.

Linear: Feature P-CON-324; Epics CON-373 (upgrade) and CON-378 (contacts);
CON-371 (stock alerts) is out of 3.0.0 — the engine side is built and
switched off. Gauge: 1/14.
