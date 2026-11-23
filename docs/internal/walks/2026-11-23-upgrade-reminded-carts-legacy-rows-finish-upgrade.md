# Walk of three behaviours of the upgrade — 2026-11-23

Internal record. An audit of the upgrade Epic (Linear CON-373) found three behaviours of the in-place
upgrade that were built and covered by tests and had never been exercised as a real flow. A worker
walked each on PrestaShop 8.2.8 (PHP 8.0.30) and 9.2.0: the merchant's part in the back office in a
browser, the shopper's part in the storefront in a browser, the background part over the store's
cron URLs, and the Smaily side on the mock behind the egress guard. Nothing in the module was
changed: the walk found no defect in the upgrade code.

| Walk | Criterion | Verdict | Checks passed, 8.2.8 | Checks passed, 9.2.0 |
|---|---|---|---|---|
| 1. Carts that 2.x reminded | "Given carts that already got a reminder under 2.x, when the abandoned-cart check runs after the upgrade, then no shopper gets a second reminder for the same cart." | **HOLDS** | 12 of 12 | 12 of 12 |
| 2. Settings left by 1.6.x under 2.x code | "Given a store that still holds settings from 1.6.x, when it upgrades, then only what was actually being sent stays on, and a notice names each thing that was left off." | **HOLDS** | 14 of 14 | 14 of 14 |
| 3. The "Finish upgrade" button | "On a store whose migration is pending, the notice offers "Finish upgrade", and the action completes the migration." (Story 3.1, acceptance 6) | **HOLDS** | 12 of 12 | 12 of 12 |

The gauge does not change: capability 1 was counted already. Of its two open points, "Finish
upgrade" is now walked; the pilot store remains.

## 1. How the walk ran

- **Code.** `v3` at `f947316`. The 3.0.0 zip is the release zip, built with `composer --working-dir
  src package` (510 files).
- **Shops.** One stack of the worktree, `prestaupg` on port 8201, from
  `build/walk-upgrade/docker-compose.yaml`: the dev stack without the `src/` mount, so that the
  module's folder could hold a genuine 1.6.2 or 2.2.0 first. One lane at a time (flashlight
  8.2.8-8.0-nginx, theme classic; then 9.2.0-nginx, theme hummingbird), and a shop made from
  scratch for every walk: six shops in all for the counts above. Removed with `down -v` at the end.
- **The legacy releases** are the release zips, built from the git tags 2.2.0 and 1.6.2 as
  `docs/DEV_ENVIRONMENT.md` 7a says. 2.2.0 was installed with the console. 1.6.2 was installed with
  the console too, with debug mode off for that one command: its own installer then runs to the end
  (section 6, observation 1). Their settings come from the fixtures in `tests/fixtures/legacy/`.
- **The upgrade to 3.0.0** in walks 1 and 2 is Module Manager > Upload a module with the release
  zip, in the browser, behind the guard, with debug mode on, and without a cache clear afterwards.
  PrestaShop answered "Module installed!" (its words for an upgrade too), the module row read
  3.0.0, and the migration was `done` when the upload's answer came. Walk 2 uploaded the 2.2.0 zip
  over 1.6.2 the same way.
- **Browser.** Headless Chromium through playwright-core: one context for the employee, a context
  of its own for every shopper, and a shopper who comes back uses the cookies of the first visit
  (PrestaShop gives a shopper who signs in from another browser a new cart). The scripts are in the
  session's scratch folder, not in the repository; every check was appended to a result file, and
  this document is written from those files.
- **Smaily.** The mock of the tests behind `tests/egress-guard`, installed and checked (`guard
  active`) before the legacy module was installed. It accepts the fixtures' invented API user only
  and lists the workflows 101 and 102 to the fixture's account. The releases 1.6.2 and 2.2.0 reach
  the mock through the same proxy: what they sent under their own code is read from the mock too.
- **Shoppers.** Six invented people, all on `example.test`.
- **Nothing left the machine.** The proxy's log of each shop holds only tunnels to the mocks (the
  fixture's account, the guard's probe, one installation report to the subscription mock) and
  refusals: the update check (`api.github.com`), PrestaShop's own calls
  (`api.prestashop-project.org`, and the modules the 9.2.0 image ships) and the guard's probe. The
  browser contexts refused every request to another origin than the shop.
- **Crashes.** No PHP-FPM crash line in the container log of any shop, read at the end of each walk.

### Shortcuts

| Shortcut | Used for | What it stands in for |
|---|---|---|
| The legacy settings come from `2.2.0-configured.sql` and from part 1 of `1.6.2-configured.sql` | every walk | saving the forms of 2.2.0 and 1.6.2 |
| `cart.date_upd` is set 50 minutes back by SQL (the delay is 45 minutes), with the shop's own clock | walks 1 and 2 | waiting 45 minutes |
| `smaily_cart.date_sent` of one cart is set three days back by SQL | walk 1 | a reminder that 2.x sent three days ago |
| The tick state is edited so that named jobs are due, then a cron URL of the store runs the tick with its token | every walk | waiting 5 minutes to 24 hours for a job's interval |
| A PHP script builds `Upgrade300` with the callable that stops it before the `accounts` step, and runs it once | walk 3 | an upgrade that an error interrupts halfway |
| Another database session holds the migration's lock (`GET_LOCK`) until the walk ends it | walk 3 | a cause that lasts: while it lasts no run can finish the migration |
| PrestaShop's console upgrade, with debug mode off for that command (`docs/DEV_ENVIRONMENT.md` 4a) | walk 3 | Module Manager > Upgrade |
| Estonian is added as a language without a language pack, and the employee's language is set by SQL | walk 2 | Localization and the employee's own profile |
| The shop's web server waits 300 seconds for a page (the image's 30 were too few on a machine that ran two other stacks), and the browser refuses the background requests of PrestaShop's own dashboard | every walk | a machine of its own |

No shortcut replaces the thing proven: every reminder that 2.x sent was sent by 2.2.0's own cron,
every upgrade ran through PrestaShop, every shopper act was done in the storefront, every check of
the abandoned carts ran over a cron URL of the store, and the button was pressed on the screen.

## 2. Walk 1 — no second reminder for a cart that 2.x reminded

A genuine 2.2.0 with `2.2.0-configured.sql`: abandoned cart on, workflow 102, 45 minutes. The
fixture's three `smaily_cart` rows name demo carts that became orders, so the walk made its own
reminded carts: three shoppers registered with the newsletter box and left a cart, and 2.2.0's own
cart cron reminded two of them.

| # | Step | Seen | 8.2.8 | 9.2.0 |
|---|---|---|---|---|
| A1 | 2.2.0 installed from its release zip, the fixture loaded | module 2.2.0, its three hooks, three `smaily_cart` rows | pass | pass |
| A2 | Anna, Boris and Clara register with the box and leave a cart each | carts 6, 7, 8; 2.2.0 itself sent three opt-ins to the mock (workflow 101, `example-store`) | pass | pass |
| A3 | Anna's and Boris's carts pass the delay; the 2.x cart cron URL, with its token | "2 abandoned cart email(s) sent!"; two triggers on the mock (workflow 102); 2.2.0 wrote two `smaily_cart` rows; Clara's cart is not due | pass | pass |
| A4 | The 2.x cart cron URL again | "0 abandoned cart email(s) sent!" | pass | pass |
| — | Shortcut: Anna's reminder is dated three days back; Boris's stays as written | so that the rule of one reminder per shopper per 24 hours cannot be what holds Anna's cart back | | |
| A5 | The 3.0.0 zip uploaded in the Module Manager | "Module installed!"; module 3.0.0, active; the migration `done` with its 15 steps; of the legacy keys only the two cron tokens are left | pass | pass |
| A6 | The carried-over state | five tracker rows `mailed`, each with the time 2.x sent it (the fixture's three and the walk's two); `smaily_cart` unchanged | pass | pass |
| A7 | The automation after the upgrade | on, workflow 102, 45 minutes | pass | pass |
| A8 | Anna and Boris come back in the same browser and add a product (the cart keeps its id); all three carts pass the delay; the 2.x cart cron URL runs the check | "OK: ran queue_flush, abandoned_cart, …"; **no trigger and no queue row for Anna's and Boris's carts**; their tracker rows as carried over | pass | pass |
| A9 | The cart that was never reminded | one trigger for Clara, to `example-store`, workflow 102, 67 fields; the queue row Sent, the tracker row `mailed` | pass | pass |
| A10 | All three add one more product, the carts pass the delay again, the check runs again | no trigger; still one abandoned-cart row in the queue: each cart is reminded once | pass | pass |
| A12 | Anna and Boris each start another cart from another browser; both pass the delay; the check runs | Anna (reminded by 2.x three days ago) gets a reminder for the new cart; Boris (reminded by 2.x within the last 24 hours) does not: Skipped, `recently_reminded` | pass | pass |
| A11 | The back office after the upgrade | the Dashboard opens with the two notices of this store; the Log lists Clara's and Anna's reminders as Sent and Boris's second cart as Skipped, "this address already got an abandoned-cart reminder for another cart in the last 24 hours" | pass | pass |

A12 goes beyond the criterion: it shows the two rules of ARCHITECTURE 3.5 apart. A cart that 2.x
reminded is never reminded again while its row is kept (A8, A10: Anna's reminder was three days
old, Boris's some minutes). Another cart of the same shopper is reminded, unless 2.x reminded that
shopper in the last 24 hours.

## 3. Walk 2 — settings left by 1.6.x under 2.x code

The store ran 1.6.2 and took 2.2.0 over it. 2.2.0 ships no upgrade script and converts nothing, so
the store holds the 1.6.x rows under 2.2.0 code: the switch `SMAILY_ENABLE_CRON` (2.2.0 reads
`SMAILY_ENABLE_CUSTOMER_SYNC`, which 1.6.x never wrote) and PHP-serialized field lists (2.2.0 reads
JSON). The fixture's account is `examplestore`, as 1.6.x stored it.

| # | Step | Seen | 8.2.8 | 9.2.0 |
|---|---|---|---|---|
| B1 | 1.6.2 installed by its own installer, part 1 of the fixture loaded | module 1.6.2; the hidden tab `AdminSmailyforprestashopAjax` and the six hooks are the ones the installer wrote; 17 legacy keys | pass | pass |
| — | 1.6.2's own customer cron URL (note, not a check) | it read its switch and called the mock. 8.2.8, with one subscribed demo customer: a read of the unsubscribers and one contact write, then "User synchronization failed!" (what 1.6.2 made of the mock's answer was not looked into). 9.2.0, with none: the read, then "User synchronization done!" | | |
| B2 | The 2.2.0 zip uploaded in the Module Manager | module 2.2.0, active; every legacy row exactly as before | pass | pass |
| B3 | Under 2.2.0: Dora registers with the box and leaves a cart | one opt-in trigger on the mock (workflow 101, `examplestore`), no contact write | pass | pass |
| B4 | Under 2.2.0: the customer cron URL | "User synchronization disabled!"; nothing reached the mock | pass | pass |
| B5 | Under 2.2.0: Dora's cart is due; the cart cron URL | PHP's "Fatal error: Uncaught TypeError" in 2.2.0's `AbandonedCartController`, on the field list (with debug mode on it comes with HTTP 200); nothing reached the mock, no `smaily_cart` row | pass | pass |
| B6 | The 3.0.0 zip uploaded in the Module Manager | module 3.0.0, active; the migration `done`, from 2.2.0; only the two cron tokens left | pass | pass |
| B7 | What stays on | welcome on with workflow 101; contact synchronization off (and not confirmed); abandoned cart off, its workflow 102 and its 45 minutes kept for the day the merchant switches it on; first order off; the account `examplestore`; consent mode "Subscribers only" | pass | pass |
| B8 | The stored notices | `settings_migrated`; `contact_sync_left_off` with `switch_not_read`; `abandoned_cart_left_off` with `field_list_not_json`; none for the welcome | pass | pass |
| B9 | What the two releases left behind | the files of 1.6.2 and of 2.2.0 are gone from the module's folder; the hidden 1.6.x tab is gone; the 1.6.x hooks are gone; 30 hooks | pass | pass |
| B10 | The Dashboard, in English | three notices (below), no upgrade banner | pass | pass |
| B11 | Another back-office page, in English | one line: "Smaily Connect: the upgrade left notices for you. Read them" | pass | pass |
| B12 | The Dashboard, for an employee who reads Estonian | the same three notices in Estonian (below) | pass | pass |
| B13 | Another back-office page, in Estonian | "Smaily Connect: uuendus jättis sulle teateid. Loe teateid" | pass | pass |
| B14 | After the upgrade: Erik registers with the box and leaves a cart, Dora adds a product, both carts pass the delay; both 2.x cron URLs run the tick with the contact jobs and the cart check due | one welcome trigger (workflow 101, `examplestore`); no contact write, no cart reminder, no other request to the mock; no tracker row for the two carts | pass | pass |

So the store sends after the upgrade exactly what 2.2.0 sent before it (B3 to B5 against B14): the
opt-in, now the welcome automation, and nothing else.

**The notices as shown** ("PrestaShop" is the demo shop's name):

| | English | Estonian |
|---|---|---|
| Carried over | Smaily Connect upgrade: Your settings from the previous version of the module were carried over. Review them under Settings, or re-run the initial setup — your saved configuration is untouched either way. | Smaily Connecti uuendus: Sinu seaded mooduli eelmisest versioonist võeti üle. Vaata need üle lehel Seaded või läbi algseadistus uuesti — salvestatud seadistus jääb mõlemal juhul puutumata. |
| Contact synchronization | Smaily Connect upgrade: Contact synchronization stays off for PrestaShop: the previous version did not run it, as it read another switch. Switch it on under Settings > Contacts when you want it. | Smaily Connecti uuendus: Kontaktide sünkroonimine jääb välja lülitatuks (pood: PrestaShop): eelmine versioon seda ei käivitanud, sest luges teist lülitit. Soovi korral lülita see sisse: Seaded > Kontaktid. |
| Abandoned cart | Smaily Connect upgrade: The abandoned cart automation stays off for PrestaShop: the previous version stopped with an error on its field list, so it sent nothing. Switch it on under Settings > Automations when you want it. | Smaily Connecti uuendus: Hüljatud ostukorvi automaatika jääb välja lülitatuks (pood: PrestaShop): eelmine versioon peatus väljade loendi vea tõttu ega saatnud midagi. Soovi korral lülita see sisse: Seaded > Automaatikad. |

The walk compared each Estonian sentence with the target of its English source in
`src/translations/et-EE/`. The back office's own menu stays English in these shops (no language
pack), and the module's tab row is English on the first page an Estonian employee opens (CLAUDE.md,
"Menu names").

## 4. Walk 3 — the "Finish upgrade" button

A genuine 2.2.0 with `2.2.0-configured.sql` (17 legacy keys, three reminded carts). A page request
runs the fallback tick, and every tick runs the migration, so a migration that one error stopped
once is finished by the next request (demonstrated on 2026-11-20, ARCHITECTURE 3.7), and nobody
sees the button. The banner stays only while the cause lasts. The walk therefore stopped the
migration halfway through the failure seam and then held the migration's database lock from another
session: while it is held, no run does any work, as when a cause of failure repeats.

| # | Step | Seen | 8.2.8 | 9.2.0 |
|---|---|---|---|---|
| C1 | 2.2.0 installed from its release zip, the fixture loaded | module 2.2.0; 17 legacy keys; three `smaily_cart` rows | pass | pass |
| C2 | The 3.0.0 zip unpacked over the folder, as an upload unpacks it; the migration run once with the seam that stops it before `accounts` | `failed`, step `accounts`, error class `LogicException`; done: `tables`, `hooks`, `menu`, `notices`, `settings`; all 17 legacy keys in place; no account row; no schema version | pass | pass |
| C3 | The lock held; PrestaShop's own upgrade (console) | "Upgrade action on module smailyforprestashop succeeded." (9.2.0: "Update action …"); module 3.0.0, active; the migration still `failed` at `accounts` | pass | pass |
| C4 | The store's 2.x customer cron URL, with its old token | HTTP 200, "Waiting: the upgrade of the module has not finished."; the migration unchanged; nothing reached the mock | pass | pass |
| C5 | The employee signs in: PrestaShop's dashboard | one line: "Smaily Connect: the upgrade to version 3.0.0 is not finished. Finish upgrade" | pass | pass |
| C6 | The line's link | the Smaily Connect Dashboard with the red banner "The upgrade to Smaily Connect 3.0.0 stopped before it finished", "Smaily Connect stays paused and your previous settings stay in place until the upgrade finishes. It also finishes on its own with the next run of the cron URL.", and the button "Finish upgrade"; no upgrade notice yet; the status reads "Smaily Connect stays paused until the upgrade finishes." | pass | pass |
| C7 | The button, while the cause lasts | "The upgrade did not finish. Your previous settings are kept. Try again in a few minutes; if it keeps stopping, see the PrestaShop logs."; the banner and the button stay; the migration, the 17 legacy keys and the two tokens unchanged | pass | pass |
| C8 | The lock released; the button again | the endpoint answers 200 `{"ok":true,"data":{"current":true}}`, the page loads again without the banner; the migration `done` with all 15 steps, no failure, from 2.2.0; the schema version stored | pass | pass |
| C9 | The Dashboard after it | the two notices of this store: the carried-over settings, and the newsletter block's emails that 2.x switched off | pass | pass |
| C10 | No legacy setting is lost | the account `example-store` with its API user; contact synchronization on and confirmed, fields `store, first_name, last_name, birthday, website`; welcome on, workflow 101; abandoned cart on, workflow 102, 45 minutes; RSS category 3, limit 100, sorted by price ascending; the three reminded carts as `mailed` rows with 2.x's times | pass | pass |
| C11 | What is gone and what is kept | the two cron tokens are the ones 2.x had (compared by hash); every other legacy key is gone; no configuration row holds the plain-text password; `smaily_cart` unchanged | pass | pass |
| C12 | The carried-over account works: Fred registers with the box; the tick URL | the contact and the welcome (workflow 101) delivered to `example-store`; the mock, which accepts the fixture's password only, refused no request | pass | pass |

The second press followed the release of the lock within about a second, and the walk made no
other request to the shop in between: the endpoint's own run finished the migration.

## 5. Defects

None in the upgrade code.

## 6. Observations (no defect claimed)

1. **1.6.2 installs by itself with debug mode off**, on 8.2.8 and on 9.2.0
   (`tests/fixtures/legacy/README.md` had it untried). Its installer creates the hidden tab, the
   table and the legacy keys, and registers its hooks under the names it passes: on these
   PrestaShop versions the rows are named `backOfficeHeader`, `footerBefore`, `leftColumn` and
   `rightColumn`, not `display…` as part 2 of the fixture has them. The upgrade removed these
   hooks and the installer's own tab (B9): the tab removal had run on the fixture's row only.
2. **PrestaShop words an uploaded upgrade as an install** ("Module installed!", "Installation of
   module smailyforprestashop was successful."), and the Module Manager page behind the dialog
   still lists the old version until it is loaded again.
3. **A cron URL names `migration` among the jobs it ran on every run**, also on a store whose
   migration is long done ("OK: ran queue_flush, abandoned_cart, installation_report, migration.").
   The job does nothing then; the answer reads as if it did.
4. **PrestaShop does not bring a shopper's cart back at a new sign-in** in these shops ("Re-display
   cart at login" is off in the demo data): a shopper who comes back from another browser starts a
   new cart, and only the same browser changes the cart that was reminded.
5. **While the upgrade is unfinished** the Dashboard's Background tasks card reads "Running" after
   a cron URL request, with "Show the cron line": the tick runs, and waits for the upgrade.

## 7. Walk-tool faults (so that the counts can be trusted)

The counts above are from the last run of each walk per version, on the final scripts, each on a
shop made from scratch: on 8.2.8 the three walks in one row; on 9.2.0 walks 1 and 2 in one row and
walk 3 once more after it. The first run of walk 3 on the final scripts failed C8 alone: the script
read the endpoint's answer after the page had loaded again, when the browser no longer holds it
(the migration was `done` and the banner gone in that run too).

Before those runs the scripts were corrected in runs that are set aside:

- **8.2.8.** Walk 1 ran twice: the first run expected a shopper who signs in again to get the old
  cart back (observation 4) and stopped there; the second stopped at its last step, where the
  script did not wait for a back office that answered 504 on a machine that ran two other stacks.
  Walk 2 ran once, with a wrong pattern in B5 (it looked for HTTP 500; PHP in debug mode answers
  200 with the error's text) and with part 2 of the fixture in place of the installer's own tab
  and hooks. Walk 3 ran twice: in the first the script did not end the session that held the
  lock, so the second press was refused as the first; the second passed its 12 checks without the
  endpoint's answer in C8.
- **9.2.0.** The three walks ran once in a row: walk 1 stopped at its last step (the Dashboard did
  not load within the script's three minutes on the busy machine), walk 2 failed B5 by the same
  wrong pattern, and walk 3 stopped at C6, where the script read the page before the link had led
  there.

No check was judged again or amended, and every superseded result file was set aside. Every
failed check of a set-aside run was a fault of the script (a wrong pattern, a wait that was too
short, a session that was not ended); none showed the module doing something other than the tables
above say.

## 8. Screenshots

In `build/walk-upgrade/8.2.8/` and `build/walk-upgrade/9.2.0/` of the worktree (git ignores
`build/`), 15 per version:

| File | Walk | Shows |
|---|---|---|
| `A-01-cart-under-2.2.0` | 1 | a shopper's cart in the storefront under 2.2.0 |
| `A-02-module-manager-after-upload` | 1 | Module Manager after the upload of the 3.0.0 zip |
| `A-03-dashboard-after-upgrade` | 1 | the Dashboard after the upgrade, with its notices and the recent reminders |
| `A-04-log-abandoned-cart` | 1 | the Log: two reminders Sent, one Skipped with its sentence |
| `B-01-en-back-office-line` | 2 | PrestaShop's dashboard with the one line |
| `B-02-en-dashboard-notices` | 2 | the three notices in English |
| `B-04-en-settings-contacts-off`, `B-05-en-settings-automations` | 2 | Settings > Contacts and > Automations after the upgrade |
| `B-06-et-back-office-line`, `B-07-et-dashboard-notices` | 2 | the line and the three notices in Estonian |
| `C-01-back-office-line-upgrade-not-finished` | 3 | PrestaShop's dashboard with the line and its link "Finish upgrade" |
| `C-02-dashboard-banner-finish-upgrade` | 3 | the banner with the button |
| `C-03-finish-upgrade-refused-while-cause-lasts` | 3 | the button's answer while the cause lasts |
| `C-04-dashboard-after-finish-upgrade` | 3 | the Dashboard after the button: no banner, the notices |
| `C-05-settings-connection-after-finish` | 3 | Settings > Connection with the carried-over account |

The screenshots show invented addresses only, and no token.
