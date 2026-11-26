# Smaily Connect for PrestaShop

[Smaily](https://smaily.com) email marketing, automations and Campaign
Intelligence for PrestaShop. The module syncs your store's contacts with
Smaily both ways, starts your Smaily workflows from store events, and
connects the store to **Smaily Campaign Intelligence**, which turns the
catalog, customers, orders and browsing into personalized product
recommendations for your emails and your storefront. Feature-aligned with
the Smaily Connect connectors for WooCommerce, Magento and Shopify.

**Status: 3.0.0 — release candidate.** Not released yet; the latest
release is 2.2.0 (Smaily for PrestaShop). The module's home is the
official repository
[`sendsmaily/smaily-prestashop-module`](https://github.com/sendsmaily/smaily-prestashop-module).

## Features

- **Upgrade in place** — a store on 2.x upgrades without reinstalling and
  keeps its Smaily account, contact sync, automations with their
  workflows, RSS defaults, cron URLs and feed addresses, and the record of
  reminded carts. Settings that 1.6.x left behind are carried over too.
- **Smaily account connection** — subdomain and API user, with a
  connection test before anything is saved. A store without a Smaily
  account sees an invitation to connect one or to create one.
- **Multilingual stores** — one Smaily account for every language, a
  Smaily account per language, one account with a workflow per language,
  or one workflow that branches on the contact's language.
- **Multishop stores** — the shops run as one store in several versions,
  set up once (a shop can still have its own account, contact settings or
  automations), or as separate stores, each set up on its own.
- **Consent modes** — subscribers only (consent), all customers
  (legitimate interest) or checkout opt-in only, with an "include guests"
  choice. An upgraded store starts in subscribers only.
- **Two-way contact sync** — contacts reach Smaily in the background, and
  unsubscribes in Smaily come back to the store. A first import sends the
  existing contacts. The Log lists every delivery, with its details and
  retry for failed rows.
- **Store automations** — welcome, first order and abandoned cart start
  your Smaily workflows, per language where the store has several. Each
  can carry a single-use discount code made from one of your cart rules.
- **Campaign Intelligence** *(optional)* — connect with the setup link
  from Smaily; the catalog, the customers and the orders are imported once,
  and every later change follows in the background.
- **Campaign Intelligence automations** — the automated emails that
  Campaign Intelligence sends itself, listed live from it, each with its
  Smaily workflow, cooldown, daily cap and a test mode with test
  addresses.
- **Browse tracking and attribution** — product, category and search
  views and cart changes, only with the visitor's consent; an order is
  credited to the Smaily email or the recommendation the shopper clicked.
- **Recommendations and the product feed** — a "Recommended for you" block
  for the storefront, placed in Design > Positions or with a theme widget,
  and the product RSS feed for the Smaily template editor, by language,
  category, limit and sort order.
- **Installation report** — tells Smaily which versions and features the
  store runs (see [Privacy and data](#privacy-and-data)).
- **Privacy** — export and erasure of a customer's data, through
  PrestaShop's Official GDPR compliance module or the Log's "Privacy
  request" form, and a Personalization page where a signed-in customer can
  opt out of personalized recommendations.
- **English and Estonian** — the administration pages and the texts the
  module shows to shoppers.

## Requirements

| Component | Supported |
|---|---|
| PrestaShop | 8.0 – 9.x |
| PHP | 8.0 or newer (PrestaShop 9 itself needs 8.1 or newer) |
| Database | MySQL or MariaDB, as your PrestaShop version requires |
| Smaily | An account whose package includes API access |
| Background tasks | A cron line every minute (recommended), or visitors' page views |

The background tasks — contact sync, automations, imports and deliveries
to Campaign Intelligence — run after a page of the store is sent, while
visitors browse it. A cron line, which the Dashboard's Background tasks
card shows, runs them every minute whatever the traffic; a server that
cannot send a page before the work is done (PHP without PHP-FPM or
LiteSpeed) needs it.

PrestaShop 1.7 cannot run Smaily Connect 3; such a store stays on version
1.6.x of the module.

## Installation and upgrading

1. Download `smailyforprestashop.zip` from the repository's
   [Releases](https://github.com/sendsmaily/smaily-prestashop-module/releases).
2. In the back office, open **Modules > Module Manager**, click **Upload a
   module** and drop the zip there.
3. Open **Customers > Smaily Connect**. A new store starts in the
   five-step Initial setup: Connect, Contacts, Automations, Intelligence,
   Overview.

**Upgrading from 2.x:** upload the new zip over the old module in the same
way. **Do not uninstall the old version first** — uninstalling removes
every setting of the module, the cron tokens and the record of reminded
carts. The module keeps its technical name `smailyforprestashop` and its
folder, so PrestaShop upgrades it in place. Your settings carry over, and
your cron URLs and RSS feed addresses keep working; a 2.x cron URL with no
token gets a new one, and a notice says so. Notices on the Smaily Connect
pages tell what else the upgrade changed or left switched off. The
[upgrade section](https://smaily.com/connect-presta/#upgrading) of the
guide has the details.

## Documentation

The merchant guide is at **[smaily.com/connect-presta](https://smaily.com/connect-presta/)**,
in English and Estonian. Its source is
[`docs/site/index.html`](docs/site/index.html), one self-contained page.

| Section | What it covers |
|---|---|
| [Getting started](https://smaily.com/connect-presta/#getting-started) | Installation and the five steps of the Initial setup |
| [Settings](https://smaily.com/connect-presta/#settings) | Every tab: Connection, Contacts, Automations, Intelligence, RSS |
| [Email fields](https://smaily.com/connect-presta/#email-fields) | The fields each automation sends to your Smaily templates |
| [Importing](https://smaily.com/connect-presta/#importing) | The first contact import and the Campaign Intelligence imports |
| [Background tasks](https://smaily.com/connect-presta/#background) | Their states, the cron line and the cron URLs of 2.x |
| [The Log and errors](https://smaily.com/connect-presta/#errors) | Reading the Log, every error message and retry |
| [FAQ](https://smaily.com/connect-presta/#faq) | Common questions |
| [Data and privacy](https://smaily.com/connect-presta/#privacy) | What is sent where, cookies, export and erasure |
| [Upgrading from 2.x](https://smaily.com/connect-presta/#upgrading) | What the upgrade carries over and what changes |

For developers: [CONTRIBUTING.md](CONTRIBUTING.md) and
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) (how the module works
inside). Changes by version are in [CHANGELOG.md](CHANGELOG.md).

Questions about Smaily Connect or your Smaily account: info@smaily.com.
Bugs and feature requests:
[GitHub issues](https://github.com/sendsmaily/smaily-prestashop-module/issues).

## Privacy and data

- **Smaily** (`https://<subdomain>.sendsmaily.net`) receives the contacts
  that your consent mode covers — the email address, the subscription
  status, the language and the extra fields you tick — and the fields of
  the automations you switch on.
- **Campaign Intelligence** (`https://intelligence.smaily.com`) receives
  data only while the store is connected: the products, the customers
  (email address, name, language, country and phone number; no street
  address or birthday), the orders and, with consent, browse events.
- **Browse tracking and the recommendations block are off** until you
  confirm in Settings that your store's cookie banner covers Smaily
  tracking, or your theme's `window.smailyConnect.consentOverride()`
  allows it. The cookies of an email click, which credit an order to that
  email, are listed in the guide's
  [cookies section](https://smaily.com/connect-presta/#pr-cookies).
- **The installation report** goes to `https://subscription.smaily.com`
  while a Smaily account is connected: when you save the setup or the
  Settings, once a day, and when you remove a connection or uninstall or
  reset the module. It holds the shop addresses, the PrestaShop, PHP and
  module versions, the connected Smaily accounts, the switched-on
  features, whether each connection works (error codes only) and the time
  of the last successful sync — never customer, subscriber or order data.
  It is authenticated with the Smaily account's API credentials, which
  Smaily only checks and never stores.
- **The new-version check** asks GitHub once a day for the latest release
  and sends no store data.
- The Smaily API password is stored encrypted, and the Log never stores or
  shows a password or key. Uninstalling removes every setting, table and
  cache file of the module.

## Development

```bash
composer install --working-dir src/
src/vendor/bin/phpunit                                    # unit tests; integration tests skip on the host
(cd src && vendor/bin/php-cs-fixer fix --dry-run --using-cache=no)
node --test tests/js/                                     # storefront scripts and the guide page
composer --working-dir src package                        # writes dist/smailyforprestashop.zip
```

The integration tests run inside a PrestaShop shop in Docker, and
`tests/ci/validate.sh` installs the built zip on a fresh shop and runs
them there. [CONTRIBUTING.md](CONTRIBUTING.md) starts the development
environment; [`docs/DEV_ENVIRONMENT.md`](docs/DEV_ENVIRONMENT.md) has the
full setup, the test commands and the upgrade walk from 2.x.

## License

GPL-3.0 — see [LICENCE.txt](LICENCE.txt).
