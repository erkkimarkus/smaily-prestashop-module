# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-21 (session end). Gauge 7/14. Nothing in flight; no worker branch or worktree is left._

## Next session opens with

**On `v3`, landed and pushed to the fork** (`0c38f0b`): stages 1 to 4 (upgrade in place, connection, store modes,
consent modes, contact sync, the Log, store automations with discount codes, Dashboard, Settings, Initial setup, the
Estonian round, the Marketplace structural rules, the database gateway) with the owner's decisions of 2026-11-21
(ARCHITECTURE section 15), and four Stories of stage 5, Campaign Intelligence, each exercised against an engine mock:

- 5.1 the engine client and the connection (made through `Engine\Setup`; no screen makes one yet);
- 5.2 ingest: product, customer and order changes, removals, the nightly product list, the identity merge;
- 5.5 storefront: consent-gated browse tracking through the store's relay, click attribution stored with the order,
  the "Recommended for you" block as a widget;
- 5.6 privacy: export and erasure through PrestaShop's GDPR module or a form on the Log, the customer's
  Personalization page.

The key namespace `ps-` is one constant (`Engine\Payload\Keys`) and is **not agreed with the engine team** (INT-306):
nothing that sends a key ships before the answer.

**Last runs** (on `0c38f0b`, by the last worker on its rebased branch, 2026-11-21): in-shop suite 1957 tests on 8.2.8
and on 9.2.0 with debug on and off; `tests/ci/validate.sh` 10 of 10 on both flashlight images; host 1957 tests,
3245 assertions, 1024 skipped; `node --test tests/js/` 43 pass. The zip content and the uninstall check last ran on
`0d4425e`, long before stage 5.

**NOT verified — keep these visible:** the live Smaily service and the live engine have never been called (every check
used a mock); GitHub Actions on the fork are not enabled, so CI has never run; MySQL (only MariaDB ran); LiteSpeed;
the official `9.2.0-8.1` image; PrestaShop's online validator; the multishop screens in Estonian; one unexplained PHP
8.0.30 segfault report (not reproduced in 113 runs). Of stage 5: Stories 5.2, 5.5 and 5.6 were each walked on their own
branch, never together in a browser on the merged `v3`; the erasure of PrestaShop's GDPR module ran through its
function, not its admin button; "tracking is off after an upgrade" is shown by tests, not by a browser upgrade.

## Next, in this order

1. Story 5.3: the history import and the Intelligence tab (Settings and Initial setup), with the switch "My store's
   cookie banner covers Smaily tracking" and the screen that makes a connection. A sketch against Magento's screens
   goes to the owner first. Then 5.4 engine automations. 6.1 installation report can run beside them (SUB-28 is
   answered: report contract v1.2.0).
2. Capability walks on the merged `v3`: 13 now (with the GDPR module's admin button); 10 and 11 once the consent
   switch has a screen; 8 after 5.3; 9 after 5.4; 12 after 6.1.
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

## First-release checklist (gauge: 7 / 14 demonstrated)

A box is ticked only when the real flow was exercised; the line names how. Decisions: ARCHITECTURE 15.

- [x] 1. Upgrade in place from 2.x, including settings left by 1.6.x — genuine 2.2.0 and 1.6.2 stores on 8.0.0, 8.2.8,
  9.2.0. Still waits: "Finish upgrade" (3.1); the pilot store.
- [x] 2. Smaily account connection, with an invitation for a new store — browser, 8.0.0 and 9.2.0.
- [x] 3. Store mode by language (four modes) — walked in a browser on 8.2.8 and 9.2.0.
- [x] 4. Store mode by shop: one shared setup, or one per shop — two shops walked on 8.2.8 and 9.2.0.
- [x] 5. Consent mode: subscribers only, all customers, checkout opt-in only — HTTP, 8.0.0, 9.2.0.
- [x] 6. Contact sync both ways, first import, and a Log with retry — walked on 8.2.8 and 9.2.0.
- [x] 7. Store automations: welcome, first order, abandoned cart — walked on 8.2.8 and 9.2.0.
- [ ] 8. Campaign Intelligence connection with catalog, customer and order imports — the connection and the live
  changes are built (5.1, 5.2); the imports and the screen wait for 5.3.
- [ ] 9. Campaign Intelligence automations, listed from the engine — Story 5.4.
- [ ] 10. Browse tracking and attribution of email clicks to orders — built (5.5, 5.2); waits: the merchant's consent
  switch (5.3) and a walk on the merged `v3`.
- [ ] 11. Product recommendations in the storefront, and the product feed — the block is built (5.5); waits: the same
  walk, and the product feed.
- [ ] 12. Installation report (phone-home) — Story 6.1.
- [ ] 13. Privacy: export, erasure, and a shopper's personalization opt-out — built and walked on its branch (5.6);
  waits: a walk on the merged `v3` with the GDPR module's admin button and the ingest rows.
- [ ] 14. Administration screens in English and Estonian — all strings in Estonian, walked on 8.2.8 and 9.2.0; waits:
  the multishop screens, a native reader, the stage 5 screens.

## Open questions (for the owner)

The workers' full notes are in ARCHITECTURE section 14. Before recommending on 1 to 5, look up what the sibling
connectors do (CLAUDE.md, "One connector never changes a behaviour rule alone").

1. Privacy: the record of a shopper's opt-out stays after an erasure, as a keyed hash only, so the opt-out still holds
   if the address returns: confirm.
2. Privacy: a customer who stays in the store after an erasure made from the Log form is sent again at the next
   change, as contact sync does. And PrestaShop's GDPR module deletes every cart rule of a customer it deletes, used
   codes included; the module cannot stop that.
3. Consent: the store-made visitor token needs the merchant's setting and the customer's newsletter consent, and the
   engine gets a customer at sign-in on the newsletter consent. The siblings read the cookie banner's answer, which
   PrestaShop does not have: confirm the mapping.
4. An order that a payment provider's server confirms gets no attribution row (no shopper cookies in that request).
5. A store whose connection the engine refuses sends nothing; its changes wait and go out once the engine accepts it
   again, rather than not being noted at all.
6. Marketplace's six content rules and PrestaShop's online validator wait until the listing is planned (CON-379;
   `docs/MARKETPLACE.md` 6.3). The cross-connector analysis CON-397 decides the late or retried contact changes, the
   workflows after an account or mode change and two shops on one account for all connectors at once.

**Only the owner can do:** enable GitHub Actions on the fork; give a Smaily test account for a live walk; ask the
engine team for the answer on INT-306 (`ps-`) and for the `source` value of PrestaShop's browse events; choose a pilot
store; have a native speaker read the Estonian.

Linear: Feature P-CON-324; Epics CON-373, CON-378, CON-381, CON-379, CON-380, CON-398 (Campaign Intelligence: CON-400
and CON-401 done; 5.3 and 5.4 not filed yet), CON-402 (privacy, done); cross-connector analysis CON-397 (with CON-394,
CON-395, CON-396); engine INT-306; subscription SUB-28 (answered). Gauge: 7/14.
