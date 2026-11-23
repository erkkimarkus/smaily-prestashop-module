# Walk of capabilities 8, 9, 10 and 11 — 2026-11-23

Internal record. A worker walked the four Campaign Intelligence capabilities end to end on the
merged `v3` (`82fc312`), on PrestaShop 8.2.8 (PHP 8.0.30, theme classic) and 9.2.0 (PHP 8.5.12,
theme hummingbird): the merchant's part through the admin screens in a browser, the shopper's part
through the storefront in a browser, the background part through the tick URL, and the engine, the
Smaily and the subscription side on the mocks behind the egress guard. Nothing in the module was
changed.

Gauge after this walk: 13 / 14.

| Capability | Verdict | Checks passed, 8.2.8 | Checks passed, 9.2.0 |
|---|---|---|---|
| 8. Campaign Intelligence connection with catalog, customer and order imports | **HOLDS** | 27 of 27 | 30 of 30; two shops: 71 of 72 (the one is a fault of the walk, section 4.2) |
| 9. Campaign Intelligence automations, listed from the engine | **HOLDS** | 10 of 10 | 10 of 10 |
| 10. Browse tracking and attribution of email clicks to orders | **HOLDS** | 28 of 28; the upgraded 2.2.0 store: 12 of 13 (the one is a fault of the walk, section 5.2) | 28 of 28 |
| 11. Product recommendations in the storefront, and the product feed | **HOLDS** | 18 of 18 | 18 of 18 |

The Initial setup that all four rest on: 13 of 13 on 8.2.8 (with the connection made on step 4)
and 8 of 8 on 9.2.0 (step 4 passed; the connection made on Settings > Intelligence).

## 1. How the walk ran

- **Shops.** One stack of this worktree, `prestawalk` (port 8231), one shop at a time, each made
  from scratch: flashlight `8.2.8-8.0-nginx`, then `9.2.0-nginx`, the dev stack with `src/`
  mounted as the module's folder; debug mode on; one shop, multistore off, so the connection is
  the installation's (shop 0). Then a 9.2.0 shop with two shops as separate stores (section 4.2)
  and an 8.2.8 shop that began as a genuine 2.2.0 store (section 5.2). All removed with `down -v`
  at the end.
- **Code.** `v3` at `82fc312`.
- **Staging by script, not part of what is proven** (`build/walk-8-11/stage.php`, phase `s0`):
  Estonian as a second language (no language pack), one product name in Estonian, no outgoing
  mail, the demo customers moved to `example.test`; nine invented customers with an address in
  France and 1166 orders copied from demo order 1 as rows (no hook runs), aged 1 to 80 months: 27
  orders of the last 18 months, 1142 of 18 to 60 months (more than the import's backlog of 1000,
  so that the older part still runs when the start part is in) and 2 older than 60 months; a
  price for the signed-in customers' group alone (half, product 6); the Smaily mock's five
  workflows; the prefix `c7-` that the engine mock assigns to the next connection; a throwaway
  payment module in the container (`walkpay`, DEV_ENVIRONMENT 4b), never in the repository.
- **Browser.** Headless Chromium through playwright-core 1.64: one context for the employee, a
  fresh context for every shopper, every request to another origin than the shop refused. The
  scripts are in `build/walk-8-11/` of the worktree (git ignores `build/`): `run-lane.sh` runs a
  lane from `fresh.sh` on; every check was appended to a result file per lane
  (`keep/final-single-<lane>.jsonl`), and this document is written from those files.
- **Mocks.** The engine mock, the Smaily mock and the subscription mock of the tests behind
  `tests/egress-guard`, installed and checked before the module was installed in every shop. The
  Smaily mock accepts one invented API user. What reached Campaign Intelligence is read from the
  engine mock's state: every request it received, and what it keeps per engine store.
- **The tick.** Every background step ran through the tick's real URL with its token (masked
  everywhere), asked for inside the shop container with the shop's host name.
- **Shoppers.** Thirteen invented people per lane, all on `example.test`: nine staged, four made
  in the storefront.
- **Nothing left the machine.** The proxy's log at the end of the 8.2.8 shop: 53 tunnels to the
  engine mock, 24 to the Smaily mock (`walkmain`), 2 to the subscription mock, 1 probe of the
  guard, and 6 refusals (`api.github.com`, `api.prestashop-project.org`, the guard's probe). On
  9.2.0: 60, 26 and 3 tunnels, and 122 refusals, nearly all of modules that the 9.2.0 image ships
  (`mbo-api.prestashop.com`, `sentry.io`, `a.klaviyo.com`). The two-shop shop and the upgrade
  shop show the same pattern (`walkshop2` and `example-store` among the Smaily tunnels).
  `check.sh` answered `guard active` at the end of every shop.
- **Crashes.** No PHP-FPM crash line and no `core` file in any of the four shops. Two of PrestaShop's own back-office
  requests failed during the scripts' development on a machine that ran two other stacks (a 504
  of `common/notifications`, a 30-second timeout of the first Dashboard); no module route among
  them, none in the runs this document counts.

### Shortcuts

| Shortcut | Used for | What it stands in for |
|---|---|---|
| The tick state is edited so that named jobs are due, then the tick runs over its real URL with its token | every tick | waiting 60 s (the ingest flush, the import) to 15 minutes for a job's interval |
| The tick state is edited so that `engine_import`, `ingest_flush` and `engine_health` count as run just now | the minute after a connection was made | a merchant who looks at the page before the next background run; without it a back-office page's fallback tick starts the import before the walk has read the "Queued" cards |
| Customers, their addresses and 1166 orders of different ages are written as rows by script | the imports | years of trading |
| The engine mock's switches: the prefix of the next connection, the list of automations, a shopper's recommendations | capabilities 8, 9, 11 | the engine |
| A theme template gets one line (`consentOverride`), by a copy inside the container, and is put back | capability 10 | a store's own consent adapter |
| A throwaway payment module confirms an order in a request without cookies | capability 10 | a payment provider's server call |

No shortcut replaces the thing proven: every connection was made and every setting saved on a
screen, every shopper act was done in the storefront, every delivery went through the tick over
its URL.

## 2. The Initial setup

Fresh install, two languages, one shop. Steps 1 to 3 in a browser: the Smaily account `walkmain`
("Connected! Continue to the next step."), Contacts "Subscribers only" without guests, no store
automation.

| Step | Seen | 8.2.8 | 9.2.0 |
|---|---|---|---|
| Step 4 before a connection | the invitation card and the setup field; no Check again, no Disconnect, no import card | pass | pass |
| A pasted setup link and Connect, on step 4 | "Connected: Mock Store (engine 1.0.0)"; the three notices "The catalog / customers / orders import has started" with "Hold back the import"; the confirmation unticked; one call to the mock, the setup exchange | pass | not walked here: 9.2.0 connects on Settings (section 3) |
| Finish | the Overview: "Campaign Intelligence Mock Store CONNECTED", the confirmation "Off", "Historical imports Catalog: Queued · Customers: Queued · Orders: Queued" | pass | pass (no connection yet) |
| The Dashboard | the Background tasks card created the tick URL's token | pass | pass |

## 3. Capability 9 — Campaign Intelligence automations, listed from the engine (10 of 10 on each version)

Settings > Automations, on the connection that a screen made (step 4 on 8.2.8, Settings >
Intelligence on 9.2.0), after the imports of capability 8.

| # | Check | Seen | 8.2.8 | 9.2.0 |
|---|---|---|---|---|
| 9.1 | The card lists the engine's automations under the engine's own names and descriptions, in its order, each Off | "Replenishment due", "Win-back" | pass | pass |
| 9.2 | The list is asked of the engine after the page loaded | `GET /api/v1/automations/catalog`, `GET /api/v1/automations/config`, nothing else | pass | pass |
| 9.3 | The workflows offered are the Smaily account's own, read at this load | the five workflows of `walkmain`, asked of `walkmain.sendsmaily.net` | pass | pass |
| 9.4 | Enabled without a workflow is not saved | "A workflow is required when this trigger is enabled."; "1 automation(s) could not be saved. …"; no `PUT` at the mock | pass | pass |
| 9.5 | An automation switched on opens in test mode | Test mode ticked, cooldown 7, no cap | pass | pass |
| 9.6 | Win-back enabled with the workflow "Walk win-back" (21), Save Intelligence Automations | "Saved."; the row shows Test mode and its workflow; the other row Off | pass | pass |
| 9.7 | The mock holds it | one `PUT /api/v1/automations/config` with both rows; `winback_rescue` enabled, `test_mode` true, `automation_map` `{"id":"21"}`, cooldown 7; `replenish_due` off; no real sends | pass | pass |
| 9.8 | A third automation added at the mock ("Walk anniversary") appears at the next load | three rows; the new one Off under the engine's name; Win-back still Test mode with workflow 21 | pass | pass |
| 9.9 | The store keeps no copy | no mapping row, no configuration key | pass | pass |
| 9.10 | No module page or endpoint answered 5xx | — | pass | pass |

## 4. Capability 8 — the connection with catalog, customer and order imports

### 4.1 One shop (27 of 27 on 8.2.8, 30 of 30 on 9.2.0)

The three checks more on 9.2.0 are the connection made on Settings > Intelligence (8.1, 8.3,
8.4); on 8.2.8 step 4 of the Initial setup made it (section 2). What the mock must hold was
written out by the walk on its own (SQL over PrestaShop's tables and the prices of the storefront
pages), not taken from the module's code.

| # | Check | Seen (the same on both versions unless said) | 8.2.8 | 9.2.0 |
|---|---|---|---|---|
| 8.1 | Settings > Intelligence without a connection | the invitation card and the setup field | step 4 | pass |
| 8.2 | The connection on Settings > Intelligence | "Connected: Mock Store (engine 1.0.0)", Check again, Disconnect | pass | pass |
| 8.3 | A connection made on the tab tells that the three imports started | three notices, "Hold back the import" | step 4 | pass |
| 8.4 | The connection cost one call | `POST /api/setup/exchange` | step 4 | pass |
| 8.5 | The exchange | no key; `plugin_info` names `smaily-connect-prestashop`, the platform, PrestaShop's version and the shop's address; `capabilities: ["connection_prefix"]`; User-Agent `SmailyConnect-PrestaShopModule/3.0.0` | pass | pass |
| 8.6 | One connection row for the installation | prefix `c7-` as the mock sent it, the key stored encrypted | pass | pass |
| 8.7 | The three imports are queued | each card Pending, "Queued — the import starts on the next scheduled run…", Cancel import | pass | pass |
| 8.8 | The confirmation shows unticked | `SMAILY_CONNECT_CONSENT_CONFIRMED` 0 | pass | pass |
| 8.9 | Before a tick nothing of the store was sent | no ingest call | pass | pass |
| 8.10 | Seven ticks over the URL ran the three imports to the end, one after another | catalog running → done, customers running → done, orders running (two ticks) → done; after the first tick the card reads Running | pass | pass |
| 8.11 | The catalog at the mock | 47 rows for 19 products: one per combination (39) and one per product without (8); every key `c7-ps-<product>` or `c7-ps-<product>-<combination>` | pass | pass |
| 8.12 | Every row | `tags.product_id` `c7-<product>`, `external_id` without the prefix, a price in EUR, a name and an address per language | pass | pass |
| 8.13 | The price is the one a visitor who is not signed in sees on the product page | five rows compared with the page: a discounted combination (19.12, compare price 23.90 = the crossed-out price), two combinations of one product with different prices (29.00 and 79.00), two plain products | pass | pass |
| 8.14 | The group price is not sent | a signed-in customer sees 5.95 for product 6, a visitor 11.90; the catalog carries 11.90 | pass | pass |
| 8.15 | The customers sent are exactly those in scope | 7 in one request: the subscribed, and the registered with an order in the last 60 months. Not sent: the unsubscribed customer without an order, the customer whose two orders are older than 60 months, the guest with two orders | pass | pass |
| 8.16 | A customer row | address, names, country and phone of the first address, language, the customer id; no consent value | pass | pass |
| 8.17 | The orders sent are exactly the orders of the last 60 months | 1169 in 24 requests of at most 50, keys `c7-<order>`; the 2 older orders stayed | pass | pass |
| 8.18 | Newest first | the 27 orders of the last 18 months, then the older ones; no order out of order | pass | pass |
| 8.19 | Each order | its address (the guest's too), the total as paid, the status by the order's state (582 completed, 293 processing, 148 cancelled, 146 refunded), lines with catalog keys | pass | pass |
| 8.20 | Progress calls | three, under `presta-2026-11-23-3`: `started`, `start_part_complete`, `complete`, with `horizon_start` and the counts (catalog 19, customers 7, orders 1169), no address | pass | pass |
| 8.21 | The cards afterwards | Done: "Done, 19 of 19 synced.", "Done, 7 of 7 synced.", "Done, 1169 of 1169 synced.", Run again | pass | pass |
| 8.22 | No Campaign Intelligence row failed or waits | — | pass | pass |
| 8.24 | A merchant changes a product's price on the product page of the back office | one catalog row for that product, whatever number of hooks the save fired | pass | pass |
| 8.25 | The tick sends it | the mock holds the product at 12.50, the price a visitor now sees | pass | pass |
| 8.26 | A customer changes her name in her account; the tick sends her | the customer row with the new name | pass | pass |
| 8.27 | A customer orders in the storefront; the tick sends the order | `c7-<order>`, her address, the line `c7-ps-7`, status processing, no attribution | pass | pass |
| 8.28 | A merchant deletes a product in the product list; the tick sends the removal | `POST /api/v1/ingest/catalog/remove` with `["c7-19"]`; the mock's row is no longer recommendable | pass | pass |
| 8.29 | No Campaign Intelligence row failed or waits | — | pass | pass |
| 8.23, 8.30 | No module page or endpoint answered 5xx | — | pass | pass |

On 8.2.8 the product page is `sell/catalog/products-v2/8/edit`, on 9.2.0
`sell/catalog/products/8/edit`. The save of 8.24 was already sent when the walk looked on 9.2.0
(a back-office page's fallback tick took it); 8.25 reads the call either way.

### 4.2 Two shops as separate stores, one connection each (9.2.0, once)

A third shop made from scratch on 9.2.0, with a second shop ("Second shop", the catalog of shop
1, its own address) staged by script and four of the staged customers with their five orders
moved to it. The Initial setup answered the layout question with "separate stores". Shop 1 was
set up and connected on Settings > Intelligence (prefix `c7-`), shop 2 on step 4 of its own
Initial setup (Smaily account `walkshop2`, prefix `c8-`); each shop then ran the checks of
section 4.1 up to 8.23 for its own rows, and three checks for both.

| Check | Seen | 9.2.0 |
|---|---|---|
| Shop 1: the Initial setup and checks 8.1 to 8.23 | 32 of 33: its own 19 products as 47 rows `c7-…`, its 4 customers in scope, its 1166 orders newest first, `started`, `start_part_complete`, `complete`. One check did not pass, 8.17, on the walk's own premise: it also asks that the store holds orders older than 60 months, and both such orders went to shop 2 in the staging. What it is about held: 1166 orders sent, 1166 expected, none missing, none more | pass, but for the premise of 8.17 |
| Shop 2: the Initial setup with the connection on step 4, and checks 8.2 to 8.23 | 35 of 35: 47 rows `c8-…`; 3 customers (the subscribed one and the two with an order in 60 months; not the one whose two orders are older); 3 orders, the 2 older ones not sent; `started`, `start_part_complete`, `complete` under its own import id; the exchange names the shop's own address and `store_config.customer_account_scope: website` | pass |
| Every call of a shop's imports carried one key, the shop's own connection's | one key per shop; no row of one shop in the other's engine store | pass |
| Two connections, two engine stores | rows `1:c7-` and `2:c8-`, both connected; the mock holds 47 products and 1166 orders under `c7-`, 47 products and 3 orders under `c8-`, no key with the other prefix | pass |
| "All shops" selected on Settings > Intelligence | no panel: "These shops are set up as separate stores. Pick a shop in the shop selector at the top of the page and set each shop up on its own." | pass |
| Each shop's tab | its own connection and its own three cards: "Done, 19 of 19 / 4 of 4 / 1166 of 1166 synced." and "Done, 19 of 19 / 3 of 3 / 3 of 3 synced." | pass |

Not walked with two shops: the live changes (8.24 to 8.28), capabilities 9 to 11, and two shops
that share one setup (one installation-wide connection that serves both).

## 5. Capability 10 — browse tracking and attribution of email clicks to orders

### 5.1 The storefront (28 of 28 on each version)

A visitor's round is a product page, its category, a search for "mug", a cart add of a product
with combinations, each page held 7 seconds (the tracker sends a batch 5 seconds after its first
event).

| # | Check | Seen | 8.2.8 | 9.2.0 |
|---|---|---|---|---|
| 10.1 | The confirmation is off on Settings > Intelligence | unticked, key 0 | pass | pass |
| 10.2 | Off: the consent rule answers no | `consentGiven()` false, the page carries `consentConfirmed: false`, no override | pass | pass |
| 10.3 | Off: after a round the browser sent nothing | no request to the relay or the recommendations, no cookie of the module | pass | pass |
| 10.4 | Off: the visitor registers | no store-created token, no identity merge queued | pass | pass |
| 10.5 | Off: nothing of the visitor at the mock | no browse event, no customer, no merge | pass | pass |
| 10.6 | The merchant ticks "My store's cookie banner covers Smaily tracking" and saves | "Saved.", no question; the box stays ticked; key 1 | pass | pass |
| 10.7 | On: the rule answers yes | the session cookie (a UUID), no visitor token | pass | pass |
| 10.8 | The browser posts to the store's relay | five posts, each 200; a post holds the event id, the session id, the type and the store's own product id, nothing of a shopper | pass | pass |
| 10.9 | The product view at the mock | `product_view c7-ps-6` | pass | pass |
| 10.10 | The other events | `category_view accessories/home-accessories`, `search "mug"`, `cart_add c7-ps-1-1` (the combination's key), `checkout_start` | pass | pass |
| 10.11 | No event names a shopper | keys: `event_id`, `session_id`, `event_type`, `event_ts`, `sku`, `category_path`, `search_query` | pass | pass |
| 10.12 | A landing from an email link (`smaily_rec`, `smaily_ctx=newsletter`, `smaily_vt`) | one post to `land`, 204; the three cookies hold the link's values | pass | pass |
| 10.13 | She registers and orders in the same browser | the order's attribution row holds the recommendation id, `newsletter` and the token | pass | pass |
| 10.14 | The tick sends the order | at the mock: `smaily_rec_id`, `smaily_rec_ctx`, `smaily_visitor_token` | pass | pass |
| 10.15 | Her browsing is joined to her | her events carry the link's token, up to `checkout_complete`; the identity merge with that token reached the mock after her customer row | pass | pass |
| 10.16 | Another shopper lands from a link and walks the checkout up to the payment | the cart's attribution row holds the three values (`cart-reminder`) | pass | pass |
| 10.17 | The payment provider's server confirms the payment, in a request without the shopper's cookies | the order takes the cart's values; the cart's row is gone | pass | pass |
| 10.18 | The tick sends that order | the same attribution at the mock | pass | pass |
| 10.19 | A customer who is not subscribed and holds no token signs in | a `vs_` token of 22 characters in her cookie, on the merchant's confirmation alone (`newsletter` 0) | pass | pass |
| 10.20 | The tick sends her as a customer, then the identity merge | `user_logged_in`, her customer id, the token; the mock binds the token to her | pass | pass |
| 10.21 | Her browsing | before the sign-in without a token, after it with her token, under one session | pass | pass |
| 10.22 | A guest at the checkout, the newsletter box empty | the customer step sets a `vs_` token | pass | pass |
| 10.23 | The tick sends the guest's order | with that token; the mock binds it to the order's address; the guest is not sent as a customer | pass | pass |
| 10.24 | A theme that defines `window.smailyConnect.consentOverride()` answering false | the rule answers no although the merchant confirmed | pass | pass |
| 10.25 | With that override | no relay request, no session cookie, nothing at the mock | pass | pass |
| 10.26 | The theme put back | the rule answers yes again | pass | pass |
| 10.27 | No Campaign Intelligence row failed or waits | — | pass | pass |
| 10.28 | No module page or endpoint answered 5xx | — | pass | pass |

### 5.2 A genuine 2.2.0 store upgraded in place (8.2.8, once)

A fourth shop, without the `src/` mount: the 2.2.0 release built from its git tag and installed by
the console, then the settings of `tests/fixtures/legacy/2.2.0-configured.sql` (account
`example-store`, customer sync, opt-in and abandoned cart with their workflows, the RSS defaults).
The files of this code were then copied over the 2.2.0 folder, as an upload or an FTP copy leaves
them; nothing else ran by script.

| # | Check | Seen | 8.2.8 |
|---|---|---|---|
| u.1 | The store runs the genuine 2.2.0 with its settings | module row 2.2.0, 17 `SMAILY_*` keys, no table of this version | pass |
| u.2 | The feed address its merchant embedded answers under 2.2.0 | `…/SmailyRssFeed?limit=100&sort_by=price&sort_order=asc&category_id=3`: 200, the category's two products | pass |
| u.3 | 2.2.0 has no storefront tracking | a product page carries no script of the module | pass |
| u.4a | The new files alone change nothing | after a visitor's page request: no key, no table (2.2.0 registered no page hook, ARCHITECTURE 3.7) | pass |
| u.4 | The store's own cron line (the legacy customer cron URL with its stored token) runs the migration | HTTP 200; migration done; the account carried over; of the legacy keys only the two cron tokens are left | pass |
| u.5 | The upgrade left the confirmation off and made no connection | no `SMAILY_CONNECT_CONSENT_CONFIRMED` key, no connection row. **The check itself did not pass**: it also asked that the engine mock had received nothing, and the mock had one request, the egress guard's own probe (`GET /api/v1/ingest/ping` by curl, without a key), sent when the guard was installed. The module had called the engine not once | not passed, a fault of the walk |
| u.6 | Settings > Intelligence of the upgraded store | the invitation card and the setup field | pass |
| u.7 | A pasted setup link and Connect | "Connected: Mock Store (engine 1.0.0)"; the confirmation shows unticked; still no key | pass |
| u.8 | A visitor of the upgraded, connected store | the page carries the four scripts and `consentConfirmed: false`; the rule answers no; after a product, a search and a cart add no request to the relay and no cookie of the module | pass |
| u.9 | The visitor registers with the newsletter box ticked | no store-created token; after a tick no browse event and no identity merge at the mock | pass |
| u.10 | The merchant ticks the confirmation and saves ("Saved."); a new visitor | the rule answers yes; three posts to the relay, each 200; `product_view c7-ps-6`, `search "mug"`, `cart_add c7-ps-1-1` at the mock | pass |
| u.11 | The embedded feed address after the upgrade | the same products, names, links and prices, in the same order, as under 2.2.0 | pass |
| u.12 | No module page or endpoint answered 5xx | — | pass |

A first try of this section let visitors' page requests run the migration and waited in vain:
that path does not exist for a file copy over 2.2.0, as ARCHITECTURE 3.7 says. The shop was made
again and the store's cron line used, which is what such a store has.

## 6. Capability 11 — recommendations in the storefront, and the product feed (18 of 18 on each version)

After capability 10, with the confirmation on. The mock was given three products for a visitor
token and three for a customer, each slot with a recommendation id of its own.

| # | Check | Seen | 8.2.8 | 9.2.0 |
|---|---|---|---|---|
| 11.1 | The module registers the block on no hook; the merchant places it in Design > Positions ("Transplant a module" on 8, "Hook a module" on 9) on `displayHome` and `displayFooterProduct` | one row per hook afterwards | pass | pass |
| 11.2 | A visitor who landed with a visitor token opens the home page | "Recommended for you" with the mock's three products, in its order, as the theme's own product tiles (on 9.2.0 the container also carries the theme's `container` class) | pass | pass |
| 11.3 | Each product shows the price a visitor sees on its product page | 11.90, 19.12 (the discounted one), 12.50 (the price changed in 8.24) | pass | pass |
| 11.4 | Who asked whom | the browser asked the store once per page (the landing page, then the home page); the store asked the engine once: `smaily_visitor_token` and `limit: 4`, no address, no customer id | pass | pass |
| 11.5 | Each link of the block | `smaily_rec=<the slot's id>&smaily_ctx=storefront`, before the combination's fragment | pass | pass |
| 11.6 | A click on the first product | lands on its page with the recommendation id; the landing answers 204 and the cookies hold that id and `storefront` | pass | pass |
| 11.7 | The product page shows the block too | the same three; no second engine call (the store's cache) | pass | pass |
| 11.8 | The home page as served holds nothing about the visitor | the same empty `<div … data-smaily-connect-recs></div>` and the same `smailyConnectStorefront` definition for a visitor with a token and one without; no token, no recommendation id and no heading in the HTML | pass | pass |
| 11.9 | A signed-in customer | her own three products; the engine was asked by `customer_external_id` and `limit` alone | pass | pass |
| 11.10 | The module's part of the page served to her | the same as a stranger's | pass | pass |
| 11.11 | She switches personalized recommendations off on her Personalization page | no block on the home page or a product page; the engine is not asked about her (the store answers the browser an empty 200) | pass | pass |
| 11.12 | The merchant unticks the confirmation and saves | a visitor with a token sees no block; the browser asks neither the recommendations nor the relay (only the landing, which is not gated); no engine call | pass | pass |
| 11.13 | Saved on again | that visitor's token gets its block | pass | pass |
| 11.14 | The legacy feed address with its legacy parameters: `…/module/smailyforprestashop/SmailyRssFeed?limit=5&sort_by=name&sort_order=asc&category_id=<id>` | 200, `application/xml`, RSS 2.0; the category's five first products by name (compared with PrestaShop's tables) | pass | pass |
| 11.15 | The address without friendly URLs: `index.php?fc=module&module=smailyforprestashop&controller=SmailyRssFeed&limit=3&sort_by=price&sort_order=desc` | the three dearest products | pass | pass |
| 11.16 | The feed in the shop's languages | under `/en/…` every product's English name, under `/et/…` the Estonian one ("Koolibri pildiga T-särk") with links under `/et/` | pass | pass |
| 11.17 | Without parameters | every active product (18), newest change first, each with name, link, picture and price ("12.50€") | pass | pass |
| 11.18 | No module page or endpoint answered 5xx | — | pass | pass |

The section of the upgraded 2.2.0 store (section 5.2) compares the feed of a genuine 2.2.0 with
the feed after the upgrade, under the address its merchant embedded.

Two notes on 9.2.0. The phase ran twice: its first run placed the block (11.1) and showed it
(11.2, 11.3) and then stopped in the walk's own reader of the mock's state (section 7); the second
run walked 11.2 to 11.18. Check 11.16 first asked for `/en/` in the English links and failed on
9.2.0, where PrestaShop writes the default language's product links without a prefix; it was run
again with the expectation put right (every English name, no link under `/et/`).

## 7. Walk-tool faults (so that the counts can be trusted)

- The staging check "three subscribed customers" failed on 9.2.0, whose demo customer is not
  subscribed (two subscribed customers, both staged). No capability check rests on it.
- On 9.2.0 the "Hook a module" form names its fields `hook_module[…]` and its submit button has no
  `type` attribute; the walk's helper was written for 8.2.8 and missed both. The phase was started
  again on the same shop after the helper was put right; nothing had been placed.
- The engine mock rewrites its state file on every request; the walk once read it in the middle of
  a write and stopped. The reader now reads again.
- The two checks that did not pass in the runs this document counts are both the walk's: 8.17 for
  shop 1 of the two-shop run (section 4.2) and u.5 of the upgraded store (section 5.2). Each row
  says what the check is about and what was seen of it.
- Expectations put right during the scripts' development on 8.2.8, before the counted runs: the
  mock keeps an address for every order, so "customers sent" is read from the customers calls; a
  category path is the slug path below the home category; a combination's own page is
  `/<product>-<combination>-<name>.html`; a theme shortens a long product name in a tile.

## Screenshots and result files

In `build/walk-8-11/` of the worktree (git ignores `build/`): `shots/<version>-<phase>-….png`
(`s1-` the setup, `c8-` the Intelligence tab, the imports and the product pages, `c9-` the
automations card, `c10-` the storefront and the saved confirmation, `c11-` the block, the
positions and the RSS tab), `keep/` the result files and logs of each lane, `out/` the suite
logs (`keep/shots-single`, `-multi` and `-upgrade` per shop). The tick token is in no file: the
tick is asked for inside the container and its answer is masked, and the screenshots of the
Dashboard, whose Background tasks card prints the cron line, were deleted.
