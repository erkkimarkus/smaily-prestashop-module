# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-20 — Stories 1.1, 1.2 and 1.5 done on `v3`; 1.3 and
1.4 in progress; the in-shop test step of `tests/ci/validate.sh` fixed; the
architecture aligned with the platform facts._

## Next session opens with

**Done:** stage 0, foundation (Linear CON-370 closed). On `v3`: Story 1.1
(skeleton), Story 1.2 (platform facts,
`docs/internal/spikes/platform-facts.md`), Story 1.5 (event queue).
`tests/ci/validate.sh` passes all 10 checks (67 tests, 349 assertions in the
shop) on flashlight 8.0.0, 8.2.8 and 9.2.0 and on the official 9.2.0 image.

**In progress:** Story 1.3 (legacy mapper) and Story 1.4 (Smaily client,
accounts, encryption), each in its own worktree.

**Next** (Stories of `docs/ARCHITECTURE.md` section 13):
1. Story 1.6: scheduler, tick, cron URLs and the queue janitor.
2. Story 1.7: upgrade, install, uninstall. It follows the "never fail the
   upgrade script" design (ARCHITECTURE 3.7) and settles its open point
   (the "Finish upgrade" notice while the Dispatcher is inert).
3. One simplification review over the stage 1 code.
4. Then stage 2.

**Now in the code:** the module on `v3` is the 3.0.0 skeleton (version,
31 hooks through `Hook\Dispatcher`, 9 tables, the schema-version guard)
plus the event queue. 2.x pieces still in the tree: the admin configuration
page (`Controller/ModuleConfigurationController`, `Form/`,
`config/admin/services.yml`, `config/routes.yml`; still wired, the
"Configure" button opens it), the cron controllers `SmailyCustomerCron`
and `SmailyCartCron` (still wired, 2.x sync code), the RSS feed (still
wired), `src/lib/` (until Story 1.4 lands), `OptInController` (no hook calls
it any more) and `overrides/ps_emailsubscription.php` (a fresh install no
longer copies it).

## Queue (technical, for the orchestrator)

- **203 rule in one place.** "Smaily code 203 is permanent" exists in
  `Queue\Failure::PERMANENT_ENVELOPE_CODES` today. When Story 1.4 lands,
  settle its single home (the client's error types or `Queue\Failure`) and
  remove the other.
- **Flaky concurrency test.** `ConcurrentFlushTest` failed 1 time in 21 runs
  on MariaDB: a deadlock in the UPDATE that records the sent rows. The claim
  no longer deadlocks; the outcome writes still can. Story 1.5 follow-up.
- **Story 1.7 open point.** The Dispatcher runs no listener while the schema
  is not current, so the "Finish upgrade" notice needs another path.

## First-release checklist (gauge: 0 / 14 demonstrated)

A box is ticked only when the real flow has been exercised; a ticked box
gets a line under it that names how. None is demonstrated yet.

- [ ] 1. Upgrade in place from 2.x, including settings left by 1.6.x
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

0. **Foundation** — docs, dev environment, fixtures, architecture. _Done
   (CON-370)._
1. **Upgrade-safe core** — skeleton, mapper, upgrade, Smaily client, event
   queue. _In progress (1.1, 1.2, 1.5 done; 1.3, 1.4 running)._
2. **Contacts and modes** — consent mode, store modes, contact sync, import.
3. **Admin screens** — Dashboard, Initial setup, Settings, Log; EN + ET.
4. **Store automations and language routing.**
5. **Intelligence, tracking, recommendations, privacy.**
6. **Release** — installation report, upgrade guide, rc1, upgrade walk.

## Decisions (2026-11-20)

Recorded in `docs/ARCHITECTURE.md` section 15. "≠" marks where the owner's
input differed from the agent-side proposal.

1. Background tasks: cron URL plus a visitor-traffic fallback tick.
2. ≠ Consent: merchant-confirmed setting (off by default; the merchant
   carries the responsibility) plus `consentOverride()`, instead of the
   proposed consent-cookie rule.
3. Abandoned cart after upgrade: the siblings' consent rule; the upgrade
   guide says fewer reminders go out than under 2.x.
4. 1.6.x leftovers: switch on only what the running code sent, with notices.
5. 2.x opt-in without a workflow: keeps the generic opt-in trigger.
6. Product keys: one row per combination; format per contract 1.35.0.
7. Campaign Intelligence: one connection per separately set-up shop.
8. Uninstall removes all module data after the disconnect and the report.
9. ≠ Scope: multishop "separate stores" is in the first release.
10. Gauge: the 14 first-release capabilities. Stock alerts (contract §6c)
    are out of 3.0.0 (Linear CON-371).

Orchestrator decisions of the same day (technical, reversible): the upgrade
script never fails (ARCHITECTURE 3.7); `language` is `NOT NULL DEFAULT ''`
in the account and mapping tables (2.2); the menu sits under Customers on
every version (10.1).

## Known 2.2.0 defects that 3.0.0 closes

For the upgrade Stories (1.3, 1.6, 1.7, 2.x); `docs/ARCHITECTURE.md` section 0.

- An empty stored cron token lets the cron URL run without a token.
- The cart cron fatals on stores holding 1.6.x serialized field lists.
- Uninstall deletes an override file it may not own.
- Customer sync pushes every shop's customers with one shop's
  configuration.
- The `NW_*` keys of ps_emailsubscription are never restored (2.x kept no
  copy; 3.0.0 leaves a notice).

## Open questions (for Erkki)

1. **Re-subscribe in Smaily, before stage 2** (ARCHITECTURE 14, row 11):
   when a contact re-subscribes in Smaily, does the reconcile switch the
   store's newsletter consent back on (`newsletter` 0 → 1, as WooCommerce
   preset 2 does; the current design), or does the store take only
   unsubscribes from Smaily, as 2.x did?
2. **Engine team** (one-way door: shared contract): agree PrestaShop's
   platform key namespace `ps-`; add this repository to the contract's
   synchronized copies; when prefix assignment is switched on (until then
   two shops on one Smaily account send no nightly manifest). Filed as
   Linear INT-306.
3. **Subscription team:** the installation-report registry lacks
   PrestaShop — product `connect_prestashop`, platform `prestashop`, and a
   shop field for multishop connections. Filed as Linear SUB-28.
4. **Pilot store** — which store, and its PrestaShop and PHP versions.
5. **Maintain rights on the official repo** — Erkki has read-only access
   to `sendsmaily/smaily-prestashop-module` today.
6. **GitHub Actions on the fork** — enable them once in the fork's Actions
   tab; until then CI on `v3` is unverified.

Linear: the Epic for the upgrade capability is CON-373; stock alerts
(CON-371) are out of 3.0.0. Gauge: 0/14 (nothing demonstrated end to end).
