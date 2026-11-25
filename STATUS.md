# STATUS — Smaily Connect for PrestaShop

> **Rule:** this file is updated in the same commit that changes reality
> (CLAUDE.md, "Keeping the docs current"). Stale status is a defect; if this
> file and your memory disagree, trust this file and fix it.
> **Keep it short** (well under 150 lines): it holds where we are now, the
> next step, the checklist and the open questions. History belongs in git
> and CHANGELOG.md, not here — replace a finished handoff, do not append to
> it.

_Last updated: 2026-11-23 (session end). Gauge 14/14._

## Next session opens with

**All 14 first-release capabilities are demonstrated against mocks on PrestaShop 8.2.8 and 9.2.0.** That is not a
release: no live service was called, no pilot store has upgraded, and CI has never run ("NOT verified" below). All of
it is on `v3` and pushed to the fork; `e957d83` is the last code-bearing commit before this handoff, and no worker
branch or worktree is left. The key namespace `ps-` is one constant (`Engine\Payload\Keys`) and is **not agreed with
the engine team** (INT-306): nothing that sends a key ships before the answer.

**What 2026-11-23 added.** Story 6.1: the installation report goes out on the tick, on a save and at uninstall, to
a mock receiver. Story 5.3: Settings > Intelligence and step 4 of the Initial setup make, check and end a Campaign
Intelligence connection and run the three imports. Story 5.4: the engine's automations on the Automations tab.
Attribution is kept with the cart, so an order that a payment provider's server confirms keeps its email click. The
consent rule: the merchant's cookie-banner confirmation alone decides the tracking of signed-in customers and of
guests. The Log's "Privacy request" form is always shown, also beside PrestaShop's GDPR module. The Dashboard has the
Campaign Intelligence and Browse tracking cards, and its tiles count the engine's deliveries. A store-layout change
carries one connection and ends the others; a change to one store also removes the cookie-banner confirmation, so
tracking is off until the merchant confirms again. Three upgrade behaviours were walked: no second reminder for a
cart 2.x reminded, 1.6.x settings under 2.2.0 code, "Finish upgrade". The full install check ran from the packaged
zip. Every screen was walked in Estonian, with the owner's native read. The simplification pass tightened stage 5.

**The owner's test environment** (he is clicking through it and continues tomorrow; workers never touch these
stacks): two long-lived local shops behind the egress guard, mocks only. `build/testkeskkond/lahtesta.sh a|b` in the
main checkout builds one (the folder is git-ignored); the guide is `build/testkeskkond/JUHEND.md` (Estonian).

- Shop A, Compose project `erkki920`: PrestaShop 9.2.0 at http://localhost:8301/, module 3.0.0 from the main
  checkout's `src/`, not set up. It shows new code after its cache is cleared.
- Shop B, Compose project `erkki828`: PrestaShop 8.2.8 at http://127.0.0.1:8302/, a genuine 2.2.0, upgraded by
  uploading `build/testkeskkond/smailyforprestashop-3.0.0.zip`. **That zip was built at `02a0255`, before the later
  fixes: build it again before the owner tests the upgrade again.**
- After a reboot: `colima start`, then `build/testkeskkond/kaivita.sh`. The guard's certificates last 30 days.

**Last runs** (2026-11-23). After the last two merges the merged tip `e957d83` ran on the host only (it skips the
integration tests): 2236 tests, 3946 assertions, 1132 skipped; `node --test tests/js/` 43 pass; PHP CS Fixer 0 of 355
files. In a shop, behind the egress guard: the simplification batch ran 2232 tests on 8.2.8 and 9.2.0 with debug mode
on and off, on shops made from scratch; the owner's-findings branch after it ran 2235 tests with debug mode on, on
its own base (its worker's report). `tests/ci/validate.sh` with the packaged zip: 10 of 10 on the flashlight images
of 8.2.8 and 9.2.0, last with the small-fixes batch (`docs/DEV_ENVIRONMENT.md`).

**NOT verified — keep these visible:** the live Smaily service and the live engine have never been called (every
check used a mock, also the installation report's); GitHub Actions on the fork are not enabled, so CI has never run;
the in-shop suite on the merged tip; MySQL (only MariaDB ran); LiteSpeed; the official `9.2.0-8.1` image;
PrestaShop's online validator; Module Manager's own Uninstall and Reset buttons; an upload over 1.6.2 on the current
tip; the install check with two shops; two shops that share one Campaign Intelligence connection (capability 8 was
walked with one shop and with two separate stores), and capabilities 9 to 11 with two shops; an export in psgdpr's
admin screen on 9.2.0 (psgdpr 1.4.3 fails there); one unexplained PHP 8.0.30 segfault report (not reproduced).

## Next, in this order

1. The owner's own walk of the two test shops; his findings come back as fixes.
2. The owner's decision on step 1 of the Initial setup (open question 1).
3. A walk against the live Smaily service and engine, once the owner gives a test account and INT-306 is answered.
4. The pilot store (owner). Price groups (CON-385) and stock alerts (CON-371) come after 3.0.0.

## Queue (technical, for the orchestrator)

- A `cart_add` of a product with combinations and no chosen combination gets a key that has no catalog row. A product
  that gets its first combination keeps its old row at the engine until the next nightly list.
- The Initial setup's first answer to "How are your shops run?" runs no layout change, so a store that held
  settings or a connection before it got a second shop keeps them under "all shops" (ARCHITECTURE 8.9).
- The Smaily client's own failure messages show in English in the Log.
- `Privacy\EngineFailure` builds the permanent-failure reason itself.
- `Config\Settings::setJson()` writes without the tag-safe flag; adding it changes the stored bytes of the notices
  and the update-check keys, so it needs a decision first.
- The dev `docker-compose.yaml` lets a shop without the egress guard reach `api.github.com` (`tests/ci/` does not).
- Tests: some test leaves rows behind after a suite run (37 event-queue rows, `skipped` ingest rows), and one leaves
  other cron tokens; the engine mock stores each request's bearer key in its state file (keys it issued itself).
- Clean-up (ARCHITECTURE 13): `Upgrade300`; the mock's transport passes other hosts on; screenshots for the user
  guide. The 2.x tab row stays in `tab`, inactive.
- After 3.0.0, from the simplification review: a shared base of the two queues; index use in the ingest queue's
  sweeps (weigh it against ARCHITECTURE 4.3); a prefetch of product facts at the catalog flush; caches of the
  connections and of the workflow list; `HistoryJobs` through `JobStore`; `ReportSettings` through `setJson()`.

## First-release checklist (gauge: 14 / 14 demonstrated)

A box is ticked only when the real flow was exercised, every service a mock. Records: `docs/internal/walks/`.

- [x] 1. Upgrade in place from 2.x, including settings left by 1.6.x — genuine 2.2.0 and 1.6.2 stores on 8.0.0, 8.2.8
  and 9.2.0; three upgrade behaviours walked in a browser on 8.2.8 and 9.2.0. Still waits: the pilot store.
- [x] 2. Smaily account connection, with an invitation for a new store — browser, 8.0.0 and 9.2.0.
- [x] 3. Store mode by language (four modes) — walked in a browser on 8.2.8 and 9.2.0.
- [x] 4. Store mode by shop: one shared setup, or one per shop — two shops walked on 8.2.8 and 9.2.0.
- [x] 5. Consent mode: subscribers only, all customers, checkout opt-in only — HTTP, 8.0.0, 9.2.0.
- [x] 6. Contact sync both ways, first import, and a Log with retry — walked on 8.2.8 and 9.2.0.
- [x] 7. Store automations: welcome, first order, abandoned cart — walked on 8.2.8 and 9.2.0.
- [x] 8. Campaign Intelligence connection with catalog, customer and order imports — browser, 8.2.8 and 9.2.0: the
  connection on both screens, the three imports on the tick URL, live changes; two shops as separate stores on 9.2.0.
- [x] 9. Campaign Intelligence automations, listed from the engine — browser, 8.2.8 and 9.2.0; one saved in test mode.
- [x] 10. Browse tracking and attribution of email clicks to orders — browser, 8.2.8 and 9.2.0: nothing tracked with
  the confirmation off, the relay's events, a click's attribution also on an order a provider's server confirms.
- [x] 11. Product recommendations in the storefront, and the product feed — browser, 8.2.8 and 9.2.0: the block
  where the merchant placed it; the product feed is the RSS feed under its legacy address (owner: no second feed).
- [x] 12. Installation report (phone-home) — 8.2.8 and 9.2.0, mock receiver: the tick URL, saves, the uninstall.
- [x] 13. Privacy: export, erasure, and a shopper's personalization opt-out — browser, 8.2.8 and 9.2.0: the Log's
  form, the customer's own export page, the Personalization page, psgdpr's admin screen (its export on 8.2.8 only).
- [x] 14. Administration screens in English and Estonian — every screen and state walked in Estonian on 8.2.8 and
  9.2.0; the owner's native read found the texts fine; 3 wordings aligned with the siblings, 4 chosen by a colleague
  (CON-416, 2026-11-24) and read on both versions.

## Open questions (for the owner)

Workers' notes: ARCHITECTURE 14; decisions: 15. Before recommending on a behaviour rule, read what the siblings do.

1. Step 1 of the Initial setup. Today, as in Magento's code, Continue saves an account that Smaily rejected and ticks
   the step; WooCommerce and Shopify refuse Continue until the test passes ("Test your Smaily connection to
   continue."). The owner hit this on 2026-11-23. Which rule, for all connectors (cross-connector analysis CON-397)?
2. After a reload the step list ticks every step up to the furthest one reached, also one never saved (as Magento).
3. Settled 2026-11-24: a colleague chose the four Estonian wordings (CON-416; capability 14 walk record, section 8).
4. Marketplace's six content rules and PrestaShop's online validator wait until the listing is planned (CON-379).
5. CON-397 also decides: late or retried contact changes, workflows after an account or mode change, two shops on
   one account.

**Only the owner can do:** enable GitHub Actions on the fork; give a Smaily test account for a live walk (it also
shows whether the real service takes an installation report); get the engine team's answer on INT-306 (`ps-`) and on
the `source` value of browse events; choose a pilot store; report psgdpr's defect on 9.x to PrestaShop, if he wants.

Linear: Feature P-CON-324. Open Epics: CON-373 (upgrade; waits for the pilot), CON-398 (Campaign Intelligence;
Stories CON-399 to CON-401, CON-405 to CON-409 and CON-415 done), CON-403 (installation report; CON-404 done; waits
for the live service), CON-379 (Marketplace). Done Epics: CON-370, CON-378, CON-380, CON-381, CON-402. CON-416
(wordings); analysis CON-397 (with CON-394, CON-395, CON-396); engine INT-306; subscription SUB-28 (answered).
