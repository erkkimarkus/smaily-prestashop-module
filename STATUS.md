# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-20 — foundation stage started; the module on `v3` is
still the 2.2.0 code._

## Next session opens with

**Now (stage 0, foundation — in progress):**
- repo docs: CLAUDE.md, STATUS.md, `.claude/settings.json` (done);
- dev environment verified and legacy fixtures (a 2.x database with
  multishop and multilanguage settings) — in progress;
- architecture decisions in `docs/` — in progress.

**Next:**
1. Erkki confirms the architecture decisions.
2. Stage 1, upgrade-safe core: module skeleton (identity unchanged), the
   legacy settings mapper and `upgrade-3.0.0.php` tested on a real 2.x
   database, the Smaily client, the event queue and the Log.

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
   architecture decisions. _In progress._
1. **Upgrade-safe core** — module skeleton, legacy settings mapper and
   upgrade script tested on a real database, Smaily client, event queue and
   Log.
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

## Open questions (for Erkki)

1. **Engine key prefix** for PrestaShop product keys, and adding PrestaShop
   to the engine contract's sync list — needs the engine team (one-way
   door: shared contract).
2. **Cookie-consent signal for browse tracking** — PrestaShop has no
   standard consent API; which signal the module reads (legal/consent
   one-way door).
3. **Pilot store** — which store, and its PrestaShop and PHP versions.
4. **Maintain rights on the official repo** — Erkki has read-only access
   to `sendsmaily/smaily-prestashop-module` today.
