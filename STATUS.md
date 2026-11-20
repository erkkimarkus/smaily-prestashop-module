# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-20 — foundation done; owner decisions recorded; Story
1.1 in progress. The module on `v3` is still the 2.2.0 code._

## Next session opens with

**Done:** stage 0, foundation (Linear CON-370 closed): repo docs, verified
dev environment (`docs/DEV_ENVIRONMENT.md`), legacy fixtures
(`tests/fixtures/legacy/`), the binding architecture (`docs/ARCHITECTURE.md`)
with the owner's decisions, engine contract 1.35.0 copied.

**Now:** Story 1.1, shared skeleton — in progress in its own worktree.

**Next** (Stories of `docs/ARCHITECTURE.md` section 13):
1. Stories 1.3 (legacy mapper), 1.4 (Smaily client), 1.5 (event queue) in
   parallel, with spike 1.2 (platform facts) alongside.
2. Then Stories 1.6 (scheduler and cron URLs) and 1.7 (upgrade, install,
   uninstall).

## First-release checklist (gauge: demonstrated / 14)

A box is ticked only when the real flow has been exercised; the line under
it names how.

- [ ] 1. Upgrade in place from 2.x, including settings left behind by 1.6.x
  - demonstrated by: _pending_
- [ ] 2. Smaily account connection, with an invitation for a store without
  an account
  - demonstrated by: _pending_
- [ ] 3. Store mode by language: one account, one account per language, one
  workflow per language, or one workflow that branches on language
  - demonstrated by: _pending_
- [ ] 4. Store mode by shop: shops of a multishop store share one setup, or
  each shop has its own
  - demonstrated by: _pending_
- [ ] 5. Consent mode: subscribers only, all customers, or checkout opt-in
  only
  - demonstrated by: _pending_
- [ ] 6. Contact sync both ways, first import, and a Log with retry
  - demonstrated by: _pending_
- [ ] 7. Store automations: welcome, first order, abandoned cart
  - demonstrated by: _pending_
- [ ] 8. Campaign Intelligence connection with catalog, customer and order
  imports
  - demonstrated by: _pending_
- [ ] 9. Campaign Intelligence automations, listed from the engine
  - demonstrated by: _pending_
- [ ] 10. Browse tracking and attribution of email clicks to orders
  - demonstrated by: _pending_
- [ ] 11. Product recommendations in the storefront, and the product feed
  - demonstrated by: _pending_
- [ ] 12. Installation report (phone-home)
  - demonstrated by: _pending_
- [ ] 13. Privacy: export, erasure, and a shopper's opt-out of
  personalization
  - demonstrated by: _pending_
- [ ] 14. Administration screens in English and Estonian
  - demonstrated by: _pending_

## Staged work order

0. **Foundation** — repo docs, dev environment and legacy fixtures,
   architecture decisions. _Done (CON-370)._
1. **Upgrade-safe core** — module skeleton, legacy settings mapper and
   upgrade script tested on a real database, Smaily client, event queue and
   Log. _In progress (Story 1.1)._
2. **Contacts and modes** — consent mode, store modes by language and by
   shop, contact sync both ways, first import.
3. **Admin screens in the sibling language** — Dashboard, Initial setup,
   Settings, Log, as in Magento; EN + ET.
4. **Store automations and language routing** — welcome, first order,
   abandoned cart.
5. **Intelligence, tracking, recommendations, privacy** — engine
   connection and imports, engine automations, browse tracking and
   attribution, recommendations and feed, export/erasure/opt-out.
6. **Release** — installation report, upgrade guide, rc1 zip and an
   upgrade walk on a copy of a real 2.x store.

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

1. **Engine team** (one-way door: shared contract): agree PrestaShop's
   platform key namespace `ps-`; add this repository to the contract's
   synchronized copies; when prefix assignment is switched on (until then
   two shops on one Smaily account send no nightly manifest).
2. **Subscription team:** the installation-report registry lacks
   PrestaShop — product `connect_prestashop`, platform `prestashop`, and a
   shop field for multishop connections.
3. **Pilot store** — which store, and its PrestaShop and PHP versions.
4. **Maintain rights on the official repo** — Erkki has read-only access
   to `sendsmaily/smaily-prestashop-module` today.

Resolved today: the Docker disk was enlarged to 80 GB.
