# Walk of capability 14, the administration screens in Estonian — 2026-11-23

Internal record. A worker opened every screen and state of the module that a merchant can reach,
as an employee who reads Estonian, in a browser on PrestaShop 9.2.0 (PHP 8.5.12, theme
hummingbird) and 8.2.8 (PHP 8.0.30, theme classic), on `v3` at `5117054` with the commits of
this work on top, against the Smaily, engine and subscription mocks behind the egress guard. It
covers what capability 14 still waited for: the multishop screens in both layouts, the stage 5
screens (Campaign Intelligence, the privacy form, the storefront's Personalization page and
recommendations block) and, for the native speaker, a package to read.

Gauge after this walk: 13 / 14. Capability 14 is not ticked: the native speaker's read remains.

| | 9.2.0 | 8.2.8 |
|---|---|---|
| States recorded | 278 | 277 |
| of them with a screenshot | 229 | 228 |
| States with English left that the module should word | 0 | 0 |
| States with a raw placeholder (`%name%`) | 0 | 0 |
| States with a text that is cut, or a button or pill whose text wraps | 0 | 1 (a link of the Log wraps, section 4) |
| Units seen on a screen, of the 619 that the code uses | 570 | 565 |

## 1. How the walk ran

- **Shops.** One stack of this worktree, `prestaet` (port 8241), one shop at a time, each made
  from scratch: flashlight `9.2.0-nginx` and `8.2.8-8.0-nginx`, the dev stack with `src/` mounted
  as the module's folder, debug mode on. Removed with `down -v` at the end.
- **Staging by script, not part of what is proven** (`build/walk-et/stage.php`): Estonian as a
  second language without a language pack (facts file 6.7), every employee set to Estonian, no
  outgoing mail, the demo customers moved to `example.test`; four invented customers; a second
  shop "Teine pood" with the catalog of the first (the multishop part); a disabled cart rule to
  make discount codes from; two employees of profiles with fewer rights.
- **Staged wording.** A state that a store reaches only through a failure or over days was
  staged for its wording, by a row or a stored value, and is marked so in the read package: the
  19 notices an upgrade can leave with each of their reasons, the banner of an unfinished and of
  a stopped upgrade (the schema version removed; "Finish upgrade" then pressed for real), the
  notice of a newer release (no call to GitHub), the states of the three imports (job rows), one
  Log row per reason of `Log\FailureMessage` (31 rows) and per job reason, the Log's rarer
  details (a failure class, no answer, a row being sent, a discount notice), a tick that last ran
  two days ago or never, a nightly product list four nights old, a shopper's cart, attribution
  and discount code for the customer's export. Everything else was reached on the screens: the
  Initial setup from a fresh install to Finish, every save, every question, every refusal named
  below.
- **Browser.** Headless Chromium through playwright-core, viewport 1440 × 1000, one context for
  the employee and a fresh one for the shopper and for each other employee; every request to
  another origin than the shop refused. The scripts are in `build/walk-et/` of the main checkout
  (git ignores `build/`): `run-lane.sh 92|82` runs a lane from `fresh.sh` on, `walk.js` and
  `phases-*.js` hold the phases, `analyze.py` reads the recorded states (`out/audits/`), and
  `out/lane-<v>-<n>.log` is what each group of phases printed.
- **Mocks.** The Smaily mock (accounts `walkmain`, `walken`, `walket`, `walkshop2`, one invented
  API user), the engine mock and the subscription mock of the tests behind `tests/egress-guard`,
  installed and checked before the module was installed. The engine mock's switches gave the
  refusals: `fail` for the exchange, the ping, the automation routes and the privacy routes,
  `tenant_inactive`, `smaily_subdomain`, `tenant_id`, `unknown_customers`.
- **Nothing left the machine.** The proxy's log at the end of the 9.2.0 lane: 164 tunnels to the
  Smaily mock for `walkmain`, 16 for `walkshop2`, 9 for `walken`, 4 for `walket`, 65 to the engine
  mock, 10 to the subscription mock, 2 probes of the guard, and 248 refusals (`api.github.com` 3,
  `api.prestashop-project.org` 6, the guard's own probe 2, and modules that the 9.2.0 image ships:
  `mbo-api.prestashop.com` 229, `sentry.io` 8). On 8.2.8: 163, 16, 9, 4, 65 and 10 tunnels, and 15
  refusals (`api.github.com` 9, `api.prestashop-project.org` 4, the probe 2). `check.sh` answered
  `guard active` at the end of each lane.
- **The suite, on a shop made from scratch per lane, debug mode on, with the unit and the test of
  this work:** 2226 tests on 9.2.0 (11912 assertions) and on 8.2.8 (11907), all passing; on the
  host 2226 tests, 3924 assertions, 1129 skipped.

## 2. What was checked on every state

For each state the walk recorded the texts inside the module's own area (`.smaily-ui`, and the
module's block on a storefront page): the visible ones, the ones the page holds for later (hidden
parts and the labels its scripts show), the text of every browser dialog, and the module's menu
entries, breadcrumb and page title. `analyze.py` then asked four things:

1. **No English left.** A text that equals the English source of a unit, or that reads as English
   (a list of common English words), is reported, unless it is on the list of what stays as it is
   (below).
2. **No raw placeholder.** No visible text holds `%name%`.
3. **No broken plural.** The counted strings were read with 0, 1 and several: the Dashboard's
   failures (1, 6 and 8), the import jobs (0, 1, 4, 240), the engine card's "1 automaatikat ei
   õnnestunud salvestada", the Log's retry answer (1, 3, 6).
4. **No clipped text.** No button, pill or card cuts its text or pushes it outside its box; a
   button's or a pill's text does not wrap.

Stays as it is, by design: "Smaily", "Smaily Connect", "Campaign Intelligence", "Intelligence",
"RSS", "API"; code and addresses (the cron line, hook names, `{widget …}`,
`window.smailyConnect.consentOverride()`); what a store or a service names (shop and language
names, the engine's store name, workflow names, the engine's automation names); event types and
statuses as stored (`contact.sync`, `engine_orders`).

## 3. Result

Both lanes ran every phase to its end (8 groups of phases each, no phase failed).

| Area | States, 9.2.0 | Screenshots | States, 8.2.8 | Screenshots |
|---|---|---|---|---|
| Initial setup, steps 1 to 5: each language mode of step 1, each consent mode of step 2, the refusals of every step, seven refusals of a setup link, the Overview with and without an account | 29 | 22 | 29 | 22 |
| Dashboard: not connected, connected, the four refusals of the engine, needs attention (failures, one failure, tasks stopped, the engine alone unreachable, no nightly product list), setup incomplete, tasks from visits only, from the cron line and never run, the release notice, the unfinished and the stopped upgrade, the 19 upgrade notices, the line on other back-office pages | 27 | 22 | 27 | 22 |
| Settings > Connection: the four language modes with the question before one is left, the refusals | 13 | 10 | 13 | 10 |
| Settings > Contacts: the three consent modes with the question of each save, guests, the first import queued, cancelled and done, sync off and on, an expired page, a changed shop selection | 15 | 11 | 15 | 11 |
| Settings > Automations: the store automations in each language mode, discount codes, the refusals; the Campaign Intelligence card in 15 states (14 on 8.2.8) | 25 | 24 | 24 | 23 |
| Settings > Intelligence: before a connection, eight refusals of a setup link, connected, the imports queued, running, done, done with failures, stalled, stopped and cancelled, the confirmation, Disconnect, "Check again" in four states | 27 | 20 | 27 | 20 |
| Settings > RSS | 5 | 4 | 5 | 4 |
| Log: the list with every status and every reason, the filters, 16 kinds of details, Send again, Retry, the privacy form with an export, an erasure and their refusals | 55 | 40 | 55 | 40 |
| Two shops: the layout question, one store with "All shops" and with a shop's own account, Contacts settings and automations, separate stores with "All shops" and each shop, the layout change both ways with "All shops" and with a shop selected, two shops in one engine store | 67 | 64 | 67 | 64 |
| Storefront in Estonian: the account link, the Personalization page, the saved preference, the recommendations block, the newsletter box, the customer's export through PrestaShop's GDPR module in three states | 8 | 5 | 8 | 5 |
| The Module Manager's line; an employee who may only read (four pages and a refused change); an employee without the right | 7 | 7 | 7 | 7 |
| **All** | **278** | **229** | **277** | **228** |

In every state of both lanes: no English text that the module should word, no raw placeholder,
and the counted strings read rightly with 0, 1 and several. No text is cut. One link wraps, on
8.2.8 only (section 4).

The ten questions that the browser asks in its own dialog were captured as text on both
versions: the three consent modes and "include guests", the language-mode switch, Disconnect,
and the layout change in both directions with each selection. The question before a change to
one store carries the note of the owner's decision of 2026-11-23: "Sirvimise jälgimine ja
soovitused jäävad välja, kuni kinnitad menüüs Seaded > Intelligence, et iga poe küpsiseteade
neid hõlmab." The questions that the Log asks in its own dialog (Send again, Retry, Erase) are
in the screenshots.

The module's menu entries, breadcrumb and page title are in Estonian from the first page that an
Estonian employee opens ("Töölaud", "Algseadistus", "Seaded", "Logi", "Smaily Connect —
Töölaud"), on both versions.

The 8.2.8 lane ran before two late additions to the scripts: it has one state less on Settings >
Automations (a save whose state cannot be read back) and walked three of its Log's four pages.
That is the difference between 570 and 565 units seen.

## 4. What the walk found, and what was done

| Finding | Where | Done |
|---|---|---|
| "Sign up for our newsletter", the label of the newsletter box that the module adds to the customer forms, had no Estonian unit (the storefront catalogue). | `Contacts\Capture\CheckoutBox` | Fixed: the unit "Liitu meie uudiskirjaga", as the Magento connector words its box; read on the Estonian registration form of both versions. A new unit test, `tests/Unit/Admin/TranslationCatalogueTest.php`, reads every string of a template and of a translate call and fails on one without a unit, on an id that is not the MD5 of its source, on an empty target and on placeholders that differ (it failed on this string first). |
| The Log shows the Smaily client's own failure text in English ("Smaily API request failed with HTTP 500") in "Viimane viga" and in Details. | Log | Not changed: recorded in ARCHITECTURE 10.4 and in the Queue of STATUS.md. |
| The Entity of an erased row reads `[erased]`, its payload `[redacted]`; the customer's export carries the stored statuses (`sent`, `pending`, `skipped`). | Log, the export | Not changed: the user guide names `[erased]`. In QUESTIONS.md for the reader. |
| Ten words of the Log are PrestaShop's own (Search, Reset, Select all, Cancel, Close, From, To, Previous, Next, No records found) and show in English in a shop without the Estonian language pack; "Cancel" stands beside "Kustuta" in the Log's own dialog. | Log | Not changed: ARCHITECTURE 10.4. In QUESTIONS.md. |
| "Loo ühendus Campaign Intelligence’ga" beside "Campaign Intelligence'iga" in four other units; "1 sündmus(t) pandi …"; "Test-aadressid"; "Külastaja tunnus" beside "külastajatunnus". | several | Not changed: each is the reader's call, and the first is the Magento catalogue's wording. In QUESTIONS.md, part A. |
| The Log's table: once a "Viimane viga" holds a sentence, the column is a few words wide and breaks long Estonian words in the middle ("turunduskirja-dega"), the table needs a sideways scroll at 1440 pixels on 9.2.0, and on 8.2.8 the link "Saada uuesti" wraps onto two lines under "Üksikasjad". | Log | Not changed: the narrow column is known (STATUS.md, Queue), and it is the table's layout, not a wording. Nothing is cut. |
| Dates show as `2026-11-23 16:29:07` in both languages. | Dashboard, Log | Not changed: not a string. In QUESTIONS.md. |
| After a successful "Testi ühendust" on step 1, the footer still reads "Salvestamine ebaõnnestus." from the refused Continue before it. | Initial setup | Not changed: not a wording. |

No template or script holds a hard-coded English sentence, and no string bypasses the translator:
the static check (`build/walk-et/static.py`, `static-calls.py`) found every string of the 50
templates and of the translate calls in the catalogues but the one above, and no prose literal in
the eight admin scripts.

## 5. Not shown on a screen

Of the 619 units that the code uses, 570 showed on a screen of the 9.2.0 lane, 19 are held by a
page and show for a moment only or in a rare state ("Salvestan…", "Kontrollin…", "Ühendan…",
"Katkestan ühendust…", "Laadimine…", the two "copying failed" answers, "Midagi läks valesti. …"),
and 30 were not reached. All 49 have an Estonian target (the unit test) and are in the read
package's full table. The 30:

- answers to a Smaily failure that the Smaily mock cannot give: an unknown subdomain, a package
  without API access, no answer, an error (six);
- refusals that the page's own controls cannot send: a sort field or order outside the list, a
  category that is no number, a save without a consent mode or a layout, a per-language account
  without its name or password (seven);
- "Send again" refused for a withdrawn reminder, for an automation whose contact got a later
  message, for an erased contact and for a row that is not failed, and a queue write that fails
  (five);
- "Finish upgrade" that does not finish, and the PHP version check of the install (two);
- the engine refusing a workflow or an automation at a save, and an exchange answered with
  another host (three);
- rarer sentences of the multishop screens ("How the shops are run is changed under Settings.",
  "Saving this step starts contact sync on every shop: …", "all shops", "These shops are set up
  as separate stores. Reload the page …"), "None" of the Overview, and "Yes" and "Opted out" of
  the customer's export (a used discount code, a shopper who opted out) (seven).

The 71 other units are texts of 2.x that no code of 3.0.0 uses (each is a unit of the 2.2.0
catalogue).

## 6. The package for the native speaker's read

`build/estonian-read/` in the main checkout (git ignores it):

- `index.html`: 273 states in reading order, 228 with their 9.2.0 screenshot (`NNN-<state>.png`,
  the tick token masked), each followed by the "English | Estonian" table of the units on it. A
  string that an earlier screen already showed is folded under the table; a question that the
  browser asks in its own dialog stands as text above it. The last section lists all 690 units
  with their ids, marks the 188 added after the first Estonian round (commit `b844192`,
  2026-11-21) as "new" and the 71 texts of 2.x as "2.x", and names the screens each unit is on.
  The page is one file with inline styles and no outside resource.
- `QUESTIONS.md`: 27 wordings the walk is unsure of, each with its alternatives.

`composer package` deletes all of `build/`: copy the folder before the next packaging.
