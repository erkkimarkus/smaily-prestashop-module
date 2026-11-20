# Legacy module state fixtures

These files hold the database rows that earlier releases of Smaily for PrestaShop (`smailyforprestashop`)
leave in a shop. The 3.0.0 upgrade test loads one of them into a shop and then runs the upgrade.

| File | Release | How it was made |
|---|---|---|
| `2.2.0-fresh-install.sql` | 2.2.0 | **Observed.** Module installed with `bin/console prestashop:module install` on PrestaShop 9.2.0, rows dumped. |
| `2.2.0-configured.sql` | 2.2.0 | **Observed output, derived input.** The module's own write code ran in a PrestaShop 9.2.0 shop and the rows were dumped. The form input was built by hand (see below). |
| `1.6.2-configured.sql` | 1.6.2 | **Derived from code.** Written from `git show 1.6.2:src/...`. Loaded on 8.2.8 and 9.2.0 since (see "Loaded into a database"). |
| `2.2.0-multistore-shared.sql` | 2.2.0 | **Derived** from the observed write pattern (section "Multistore"). Multistore on, a minimal shop 2 in shop group 1 (no shared customers), and only the global rows of `2.2.0-configured.sql`: both shops share one setup. |
| `2.2.0-multistore-own-rows.sql` | 2.2.0 | **Derived** as above. The same global rows, plus a row for every key at shop 2 as a save in shop 2's context writes them: another account (`other-store`, `other-api-user`, `not-a-real-password-either`), opt-in workflow 103, abandoned cart workflow 104, other field lists. |
| `override-2.x.ps_emailsubscription.php.txt` | 2.0.0–2.2.0 | Byte copy of `src/overrides/ps_emailsubscription.php`, identical at every 2.x tag (SHA-256 `5fb063e2…`). The `.txt` name keeps code-style tools from rewriting it. |

All values are invented: subdomain `example-store`, user `api-user`, password `not-a-real-password`,
tokens `abc123def456` / `fed654cba321`, autoresponder ids 101 (opt-in) and 102 (abandoned cart).
No file holds real personal data. Customer 2 and carts 1-3 belong to the PrestaShop demo data.

## Loading a fixture

The files assume the table prefix `ps_` and MariaDB/MySQL. Each file first deletes the `SMAILY_*` rows,
then inserts its own rows, so it can be loaded over any earlier state:

    docker compose exec -T mysql mariadb -uprestashop -pprestashop prestashop < tests/fixtures/legacy/2.2.0-configured.sql

The fixtures hold data only. The module files of the matching release and its `ps_module` row must be
in place too: install that release first (for 2.2.0, see `docs/DEV_ENVIRONMENT.md`), then load the file.
`1.6.2-configured.sql` part 2 also sets `ps_module.version` to `1.6.2` and adds the 1.6.x tab and hooks.

Do not open the module configuration page while fixture credentials are loaded: 2.2.0 then asks the
live Smaily API (`https://example-store.sendsmaily.net`) for the autoresponder list.

## How `2.2.0-configured.sql` was produced

The admin account form calls the Smaily API before it stores the credentials, and the customer sync
and abandoned cart forms can only choose an autoresponder that the API returned. Without real
credentials, the form cannot reach the configured state. So a PHP script booted the shop
(`config/config.inc.php`) in the PrestaShop 9.2.0 container and called:

- `Configuration::set()` (the `prestashop.adapter.legacy.configuration` service) for `SMAILY_SUBDOMAIN`,
  `SMAILY_USERNAME`, `SMAILY_PASSWORD`, as `AccountFormDataConfiguration::updateConfiguration()` does on
  HTTP 200;
- `CustomerSyncFormDataConfiguration`, `AbandonedCartFormDataConfiguration` and
  `RssFeedFormDataConfiguration` `::updateConfiguration()`, unchanged module code;
- `SentCart::save()` three times, as `AbandonedCartController` does after it sent a reminder.

The form data had the types the Symfony forms submit: `SwitchType` and `CheckboxType` give booleans,
`ChoiceType` gives the autoresponder id as the API returned it (an integer), `NumberType` gives a float
(`45.0`, `100.0`), the category choice is a string (`'3'`). The disabled `store` checkbox comes in as
`false`; the module overwrites it with `true`. The script ran under `Shop::CONTEXT_ALL` with multistore
off, which is what the admin uses in a single shop.

## Keys

"Writes" names the release lines that write the key. 1.x means release line 1.6.x (the only 1.x line
this README covers); 2.x means 2.2.0. Install and form code changed in small ways between 2.0.0 and
2.2.0 (`git diff 2.0.0 2.2.0 -- src/src/Install src/src/Form`); this README does not cover 2.0.x/2.1.x.

| Key | Meaning | 2.x stored format | 1.6.x stored format | Writes |
|---|---|---|---|---|
| `SMAILY_SUBDOMAIN` | Smaily account subdomain | As typed, no normalisation (`example-store`). Empty: `NULL` | Only `[a-zA-Z0-9]`: the AJAX check strips the rest and takes the first label of a full URL or `x.sendsmaily.net` (`examplestore`). Empty: `''`/`NULL` | 1.x, 2.x |
| `SMAILY_USERNAME` | API user | As typed. Empty: `NULL` | Trimmed, `pSQL`-escaped | 1.x, 2.x |
| `SMAILY_PASSWORD` | API password, plain text | As typed. Empty: `NULL` | Trimmed, `pSQL`-escaped | 1.x, 2.x |
| `SMAILY_ENABLE_CUSTOMER_SYNC` | Customer sync on | `'1'` on, `NULL` off (a stored `false`) | not written | 2.x |
| `SMAILY_ENABLE_CRON` | Customer sync on (1.x name) | not written, not read | `'1'` / `'0'` | 1.x |
| `SMAILY_CUSTOMER_CRON_TOKEN` | Token of the customer sync cron URL | 12 hex characters at install (`bin2hex(random_bytes(6))`), any non-empty text after a save | `''` at install; a save stores the typed token or `uniqid()` (13 hex characters) when empty | 1.x, 2.x |
| `SMAILY_SYNCRONIZE_ADDITIONAL` | Customer fields to sync besides email | JSON map field => bool, always with `"store":true` first after a save: `{"store":true,"first_name":true,"last_name":true,"birthday":false,"website":true}`. `[]` at install | PHP-serialized list of ticked names: `a:2:{i:0;s:10:"first_name";...}`. `a:0:{}` at install. No `store` | 1.x, 2.x |
| `SMAILY_OPTIN_ENABLED` | Trigger opt-in on customer sign-up | `'1'` / `NULL` | `'1'` / `'0'` | 1.x, 2.x |
| `SMAILY_OPTIN_AUTORESPONDER` | Smaily workflow id for opt-in | Id as text (`'101'`); `NULL` when opt-in is off (the save clears it) | Id as text, `''` for "any" | 1.x, 2.x |
| `SMAILY_ENABLE_ABANDONED_CART` | Abandoned cart on | `'0'` at install (integer 0), `'1'` / `NULL` after a save | `'1'` / `'0'` | 1.x, 2.x |
| `SMAILY_CART_CRON_TOKEN` | Token of the abandoned cart cron URL | As the customer token | As the customer token | 1.x, 2.x |
| `SMAILY_CART_AUTORESPONDER` | Smaily workflow id for abandoned cart | Id as text; `NULL` when none | Id as text (1.5.0 upgrade turned the older serialized workflow into its id) | 1.x, 2.x |
| `SMAILY_ABANDONED_CART_TIME` | Minutes until a cart counts as abandoned (>= 15) | `'15'` at install, integer text after a save (`'45'`) | `''` at install, integer text after a save | 1.x, 2.x |
| `SMAILY_CART_SYNCRONIZE_ADDITIONAL` | Fields sent to the abandoned cart template | JSON map of `first_name,last_name,name,description,sku,price,base_price,quantity` => bool. `[]` at install | PHP-serialized list of the same names. `a:0:{}` at install | 1.x, 2.x |
| `SMAILY_RSS_CATEGORY_ID` | RSS feed category filter | Category id as text, `NULL` for all products | Category id as text, `''`/`NULL` for all products (stores `false`) | 1.x, 2.x |
| `SMAILY_RSS_LIMIT` | Products in the feed, 1-250 | `'50'` at install | `'50'` at install | 1.x, 2.x |
| `SMAILY_RSS_SORT_BY` | `date_add`, `date_upd`, `name`, `price`, `id_product` | `'date_upd'` at install | `'date_upd'` at install | 1.x, 2.x |
| `SMAILY_RSS_SORT_ORDER` | `asc` / `desc` | `'desc'` at install | `'desc'` at install | 1.x, 2.x |
| `NW_VERIFICATION_EMAIL` | ps_emailsubscription sends its verification mail | Set to `'0'` on every customer sync save with opt-in on | not written | 2.x (side effect) |
| `NW_CONFIRMATION_EMAIL` | ps_emailsubscription sends its confirmation mail | Set to `'0'`, same trigger | not written | 2.x (side effect) |
| `NW_VOUCHER_CODE` | ps_emailsubscription welcome voucher | Set to `NULL`, same trigger | not written | 2.x (side effect) |

The `NW_*` keys belong to ps_emailsubscription. 2.x never restores them, also not on uninstall. In the
PrestaShop 9.2.0 demo dump they already were `'0'`, `'0'`, `NULL`, so the observed run could not show a
change; the values above come from `CustomerSyncFormDataConfiguration::updateConfiguration()`.

`NULL` values: PrestaShop's `Configuration::updateValue()` inserts and updates with `null_values`, so a
stored `''`, `false` or `null` becomes SQL `NULL` (observed for every empty 2.2.0 default). An integer
`0` stays `'0'`. A float `45.0` is stored as `'45'`. `Configuration::get()` returns these as `false`/text.

## Other 2.2.0 state (observed)

- Hooks: `actionCustomerAccountAdd`, `actionCustomerAccountUpdate`, `actionNewsletterRegistrationAfter`.
- Tab: PrestaShop registers a tab `SmailyForPrestaShopConfiguration` (`id_parent` 0, `active` 0) from
  the `_legacy_controller` of `config/routes.yml`. The module does not create it itself.
- Override file `override/modules/ps_emailsubscription/ps_emailsubscription.php`, copied at install
  when no other module put one there.
- Table `ps_smaily_cart`. No release from 2.0.0 to 2.2.0 ships an `upgrade/` folder, so nothing converts
  1.6.x data on the way to 2.x.

The 2.2.0 rows are copied from the dumps. See "Loaded into a database" for what loading the files
showed.

## Other 1.6.x state (derived from code)

`1.6.2-configured.sql` part 2 writes these rows, which have not been run against a database:

- Tab `AdminSmailyforprestashopAjax`, hidden (`active` 0), named "Smaily for PrestaShop" in every active
  language, with its four `ROLE_MOD_TAB_ADMINSMAILYFORPRESTASHOPAJAX_*` authorization roles. `Tab::add()`
  also grants them to employee profiles in `ps_access`; the fixture leaves that out.
- Hooks: `displayBackOfficeHeader`, `displayFooterBefore`, `displayLeftColumn`, `displayRightColumn`
  (registered under their old names `backOfficeHeader`, `footerBefore`, `leftColumn`, `rightColumn`),
  `actionFrontControllerSetMedia`, `actionCustomerAccountAdd`. The resolution of the old names through
  `ps_hook_alias` is an assumption.
- No override, no `actionCustomerAccountUpdate` or `actionNewsletterRegistrationAfter` hook.

## Multistore fixtures

The two `2.2.0-multistore-*.sql` files add shop 2 themselves: a `shop` row copied from shop 1 and a
`shop_url` row (`virtual_uri` `second/`), and they set `PS_MULTISHOP_FEATURE_ACTIVE` to `1`. That is
enough for PrestaShop's configuration and shop classes and for the migration; it is no browsable
storefront (no `*_shop` association rows). Part 2 enables the module in shop 2. To undo: delete the
shop 2 rows of `shop`, `shop_url`, `module_shop` and `configuration`, and set the multistore switch
back to `0`.

## Multistore

**Observed** on PrestaShop 9.2.0: multistore switched on (`PS_MULTISHOP_FEATURE_ACTIVE` = 1) and a
second shop (id 2, group 1) added through the `Shop` and `ShopUrl` classes, then the same 2.2.0 write
code ran in two contexts:

- Shop context, shop 2: every written key got its own row with `id_shop_group` = 1, `id_shop` = 2,
  also for values equal to the global ones. The `NW_*` side-effect keys too.
- All shops context: the global rows (`id_shop_group` and `id_shop` `NULL`) changed. The shop 2 rows stayed.
- Reads: shop 1 and All shops read the global value; shop 2 reads its own row, through the adapter
  `Configuration::get()` and the legacy `Configuration::get()` alike.

So after an "All shops" save, a shop with its own rows keeps its old values. Install always writes
global rows (it switches to `Shop::CONTEXT_ALL`). With multistore off, PrestaShop stores every row global
whatever the context. Group context (`id_shop_group` set, `id_shop` `NULL`) follows from the same
`Configuration::updateValue()` code and was **not observed**.

1.6.x calls `Configuration::updateValue()` without shop ids, so it writes into the context shop in the
same way (**derived from code**).
