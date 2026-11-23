# Smaily installation report — integration contract v1

**Status**: Approved v1 — Erkki, 2026-11-05 (SUB-3). Plugins build against this version. 1.1.0 (PrestaShop) approved by Erkki, 2026-11-20 (SUB-28). 1.2.0 (the latest version and a notice in the answer) approved by Erkki, 2026-11-20 (SUB-30).
**Version**: 1.2.0
**Last updated**: 2026-11-20
**Linear**: SUB-3 (this contract), SUB-1 (Epic), CON-290 (WooCommerce), CON-234 (Magento 2), CON-231 (Shopify), SUB-28 (PrestaShop), SUB-30 (answer fields)

---

## Overview

A Smaily product sends the Smaily subscription service an **installation
report**. The report says which product runs where, on which versions, on
which Smaily accounts, with which features switched on, whether each Smaily
account and Campaign Intelligence connection works, and when it last synced.
Smaily staff see from it who has what installed, whether it is connected, and
whether the other end is there and works.

An **installation** is one copy of a product: one WordPress site with Smaily
Connect, one Magento installation with Smaily Connect, one PrestaShop
installation with Smaily Connect, or one Shopify shop that installed the Smaily
Connect app.

There is one call: `POST /v1/reports`. The installation proves itself with the
**Smaily API credentials it already holds** for a connected Smaily account. A
plugin that is connected to Smaily can show that, and nobody else can. The
service checks the credentials with one Smaily API call and never stores, logs
or forwards them.

Campaign Intelligence does not receive installation reports. The service stores
report data in the EU.

## Table of contents

1. [Base context](#base-context)
2. [Products](#products)
3. [Authentication](#authentication)
4. [When an installation sends a report](#when-an-installation-sends-a-report)
5. [Installation states](#installation-states)
6. [Report body](#report-body)
7. [Endpoint: POST /v1/reports](#endpoint-post-v1reports)
8. [Answer fields](#answer-fields)
9. [Error handling](#error-handling)
10. [What the plugin does with each answer](#what-the-plugin-does-with-each-answer)
11. [What a report never carries](#what-a-report-never-carries)
12. [Suggested wording for the plugin's readme](#suggested-wording-for-the-plugins-readme)
13. [Versioning](#versioning)
14. [Kept for later](#kept-for-later)
15. [Not in v1](#not-in-v1)
16. [Decisions (2026-11-05)](#decisions-2026-11-05)
17. [Changelog](#changelog)

---

## Base context

**Base URL**: `https://subscription.smaily.com`. The name is a Smaily DNS
record that points at the hosting platform, so the platform can change without
a plugin release. Every path in this contract is relative to it.

**Transport**: HTTPS only, TLS 1.2 or newer. The plugin verifies the server
certificate. Plain HTTP is not supported: the hosting platform may answer a
plain-HTTP request with a redirect to HTTPS (`308`), and the service never
accepts a report over plain HTTP. The plugin always sends to the `https` base
URL and never follows a redirect when it sends a report.

**Content-Type**: every request and every response uses
`application/json; charset=utf-8`. The service answers every response with
`Cache-Control: no-store`.

**Body size**: a request body is at most 64 KiB.

**Encoding**: strings are UTF-8. JSON keys are lowercase with underscores.

**Timestamps**: ISO 8601 in UTC with `Z`, for example `2026-11-05T09:14:00Z`.
The service refuses a timestamp without a zone.

**Unknown fields**: the service ignores a request field this contract does not
name. It does not store it. The plugin ignores a response field this contract
does not name, so a later version can add fields to the answer without
breaking anyone.

**`installation_id`**: a UUID v4 the service gives an installation the first
time it accepts a report for it. The service derives the installation from
`product` and `installation_url` (see [Addresses](#addresses)), so every later
report of that installation gets the same `installation_id`.

---

## Products

Every report names its product by an id from this registry. The registry also
says which platforms the product reports, whether a PHP version applies, and
which feature ids the product uses.

| `product` | Product | Hosting | `platforms[].name` | `php_version` | Feature ids |
|---|---|---|---|---|---|
| `connect_woocommerce` | Smaily Connect for WordPress and WooCommerce | self-hosted | `wordpress` (always), `woocommerce` (when WooCommerce is active) | required | `subscriber_sync`, `welcome_automation`, `first_order_automation`, `abandoned_cart`, `campaign_intelligence`, `browse_tracking`, `transactional_emails` |
| `connect_magento` | Smaily Connect for Magento 2 | self-hosted | `magento` | required | `subscriber_sync`, `welcome_automation`, `first_order_automation`, `abandoned_cart`, `campaign_intelligence`, `browse_tracking`, `transactional_emails` |
| `connect_prestashop` | Smaily Connect for PrestaShop | self-hosted | `prestashop` | required | `subscriber_sync`, `welcome_automation`, `first_order_automation`, `abandoned_cart`, `campaign_intelligence`, `browse_tracking` |
| `connect_shopify` | Smaily Connect for Shopify | hosted | `shopify` | not sent | `subscriber_sync`, `welcome_automation`, `first_order_automation`, `abandoned_cart`, `campaign_intelligence`, `browse_tracking` |

The registry is not limited to plugins. Later rows can name other products of
the family, for example Campaign Intelligence or Smaily accounts.

**Hosting**:

- **self-hosted** — the product runs on the store's own server. The store's
  server sends the reports.
- **hosted** — the product runs on Smaily's server, on behalf of many stores.
  That server sends one report per store, with that store's own Smaily
  credentials. Each store is its own installation.

**Roles**: every connection has a `role`.

- **`marketing`** — a Smaily account the product syncs contacts and runs
  automations with. Every product has these.
- **`transactional`** — a separate Smaily Transactional account for order and
  shipping emails. WooCommerce and Magento can connect one. PrestaShop and
  Shopify have no transactional account, so their connections are always
  `marketing`.

**Feature ids**: a feature id is in a connection's `features` list when that
feature is switched on for that connection.

| Feature id | Switched on when |
|---|---|
| `subscriber_sync` | The product syncs store contacts to this Smaily account. |
| `welcome_automation` | The welcome email is mapped to a Smaily workflow and switched on. |
| `first_order_automation` | The first-order email is mapped to a Smaily workflow and switched on. |
| `abandoned_cart` | The abandoned-cart reminder is mapped to a Smaily workflow and switched on. |
| `campaign_intelligence` | The connection is connected to Campaign Intelligence. |
| `browse_tracking` | Storefront browse tracking for Campaign Intelligence is switched on. |
| `transactional_emails` | On a `transactional` connection: order and shipping emails through this Smaily Transactional account are switched on. |

**Platform versions**:

| `platforms[].name` | `version` holds |
|---|---|
| `wordpress` | The WordPress version, for example `6.8.3`. |
| `woocommerce` | The WooCommerce version, for example `9.9.5`. |
| `magento` | The Magento version, for example `2.4.7-p3`. |
| `prestashop` | The PrestaShop version, for example `8.2.8`. |
| `shopify` | The Shopify Admin API version the app calls, for example `2026-10`. |

**Product version**: the version the product shows to its users. A hosted
product without a version number sends its release identifier (for example the
release date `2026.11.05` or a short commit hash).

**Adding a product**: a new product, platform or feature id is added by a minor
version of this contract (see [Versioning](#versioning)). The new registry row
gives the id, hosting, platforms, whether PHP applies and the feature ids. The
row exists before the product sends its first report.

---

## Authentication

### What the plugin sends

Every Connect plugin already calls the Smaily API at
`https://{subdomain}.sendsmaily.net/api/{endpoint}.php` with HTTP Basic
authentication: the API user name and password of a Smaily account. The report
uses the same credentials:

```
Authorization: Basic base64(<api user name>:<api password>)
```

The top-level field `smaily_subdomain` in the body names the Smaily account
these credentials belong to. In a `snapshot` report it equals the
`smaily_subdomain` of at least one `marketing` connection in the report.

### Which account authenticates the report

One report covers the whole installation, also when the installation is
connected to several Smaily accounts (Magento websites, PrestaShop shops,
per-language accounts, a Transactional account). The report carries the
credentials of **one** account, the **main account**:

- WooCommerce: the `default` account.
- Magento: the account of the default scope.
- PrestaShop: the account set up for all shops. An installation whose shops
  are set up separately has no main account.
- Shopify: the shop's `default` account.
- When the installation has no main account, the account of the first
  `marketing` entry in `connections`.

The main account is always a `marketing` connection. A separate Smaily
Transactional account never authenticates a report.

The service marks every `marketing` connection whose `smaily_subdomain` equals
the authenticated one as **verified**. It stores the other connections, every
`transactional` connection among them, as **declared**. A later contract
version can verify each of them (see [Kept for later](#kept-for-later)).

One report per installation is the thinner choice: one snapshot, one Smaily
check a day, and the connections stay together. One report per Smaily account
would split an installation into several reports for the service to merge, and
would add one Smaily check per account, for no information v1 needs.

### How the service checks the credentials

1. The service builds the host only from a `smaily_subdomain` that passes the
   subdomain rule (see [Report body](#report-body)).
2. The service calls, with the request's Basic credentials:

   ```
   GET https://{smaily_subdomain}.sendsmaily.net/api/workflows.php?trigger_type=form_submitted
   ```

   This is the read the Magento and Shopify plugins make to validate
   credentials when a store connects. The WooCommerce plugin validates with
   `GET autoresponder.php?status=ACTIVE`, which proves the same thing. The call
   reads a short list and changes nothing in Smaily.
3. The service reads Smaily's answer:

   | Smaily answers | The service |
   |---|---|
   | `2xx` | Accepts the credentials. |
   | `403` with body code `227` ("A paid package is required") | Accepts the report and marks it **"package blocks the API"**. No connection of that report is verified. |
   | Any other `4xx` except `429` (a wrong subdomain answers `404`) | Refuses: `401 invalid_smaily_credentials`. |
   | `429`, `5xx`, a timeout of 3 seconds, a network error | Answers `503 smaily_unreachable` with `Retry-After`. |

   Smaily answers code `227` for every call to an account whose package has no
   API, for right credentials, wrong ones and none: it checks the package
   before the credentials (WooCommerce PRO-1686, Shopify S-92). An account
   moved to such a package after it connected stays connected in the plugin
   and keeps reporting. Code `227` proves the subdomain exists, not the
   credentials, so anyone can send a report for such an account. Nothing in v1
   grants access or money on a report.
4. The service caches a positive result for up to 24 hours. The cache key is
   an HMAC (keyed with a secret of the service) of subdomain, user name and
   password together. The cache holds no plain value. It does not cache a
   refusal or a code `227` answer.

### Rules for the credentials

The Smaily API credentials are 🔴 credentials. The service treats them like
passwords:

- It uses them only for the check above.
- It never stores them, never writes them to a log line, a trace or an error
  report, and never sends them anywhere except that one Smaily API call.
- It sends them to Smaily over TLS only.

### What the plugins know about other Smaily platforms

Every Connect plugin knows only the v1 Smaily API on `sendsmaily.net`. It holds
no credential for the v2 platform API (`smaily.app`, Bearer tokens). A product
that connects to a v2 or v3 account through that API needs a new way to prove
itself. A later contract version adds it (see [Not in v1](#not-in-v1)).

---

## When an installation sends a report

An installation sends a report **only when at least one Smaily account is
connected** (wordpress.org plugin guideline 7). An installation with no
connected Smaily account sends nothing. When the last Smaily account is
disconnected, reports stop. A transactional account never authenticates a
report, so an installation whose only connected account is a Smaily
Transactional account sends nothing either.

A `snapshot` report is sent at two moments:

1. **Setup saved.** The store saves the product's setup (the setup wizard or
   the settings page) and a Smaily account is connected. Several saves within
   5 minutes send one report, at the end of those 5 minutes.
2. **Once a day.** The daily report is due 24 hours after the last report
   attempt. The first daily report is due at a random moment 0–24 hours after
   the first report. This spreads thousands of stores across the day. The daily
   report is sent even when nothing changed.

Rules for every send:

- **Background only.** The report is sent from a background job: WP-Cron or
  Action Scheduler (WooCommerce), cron or the message queue (Magento), the app's
  job runner (Shopify). It is never sent inside a page request that a shopper or
  an admin waits on. [Events](#events) are the one exception.
- **Short timeout.** Connect timeout 3 seconds, total timeout 5 seconds.
- **A failed report never breaks or slows the store.** The plugin catches every
  error. It shows no admin notice for a failed report.
- **Each report is a full snapshot.** The plugin does not queue failed reports.
  The next report carries the current state.
- **A hosted server sends at most 10 requests per second** to the service, for
  all its stores together.

### Events

An installation also sends a report at two events. The body field `event`
names it. The endpoint and the authentication are the same as for a
`snapshot` report.

- **`disconnected`** — the merchant removes a connection: a Smaily account or
  Campaign Intelligence. The plugin sends the report **before** it deletes
  that connection's credentials or key, with the main account's credentials it
  still holds. The report carries the connections that remain. `connections`
  is empty when the last Smaily account goes.
- **`deactivated`** — the product stops running for the store. The report
  carries the connections as they are.

| Product | `deactivated` is sent from | `disconnected` is sent from |
|---|---|---|
| WooCommerce | The plugin's deactivation hook (`register_deactivation_hook`, `Smaily\Connect\Deactivation::run`). WordPress deactivates a plugin before it deletes it. | The Campaign Intelligence disconnect (`POST /rec-engine/disconnect`, `RecEngineSettings::disconnect`), and wherever the plugin deletes a Smaily account's stored credentials. |
| Magento 2 | The module's removal, before it deletes the settings: `Setup\Uninstall` on `bin/magento module:uninstall`, and `RemoveSettingsOnUninstall::revert()` on `module:uninstall --non-composer`. `bin/magento module:disable` runs no module code, so a disabled module sends nothing and its installation turns `at_risk`. | `bin/magento smaily:engine:disconnect` (`Disconnector`), and wherever the module deletes a Smaily account's stored credentials. |
| Shopify | The app server's `app/uninstalled` webhook handler (`webhooks.app.uninstalled.tsx`), before `uninstallShop`. The server still holds the shop's Smaily credentials then. | `disconnectSmaily`, `disconnectSmailyLanguage` and `disconnectEngine`. |

Rules for an event report:

- **Sent once, inline.** The plugin sends it from the code path above, not
  from a background job, because afterwards it no longer holds the credentials
  or no longer runs. Same short timeout, no retry.
- **One event per removal.** A deactivation that also ends connections (the
  Magento removal and the Shopify uninstall end the Campaign Intelligence
  connection) sends only `deactivated`.
- **Best effort.** The service never relies on an event arriving. An
  installation whose event is lost goes silent and turns `at_risk`.

---

## Installation states

The service gives every installation one state. The staff list of
installations (SUB-4) shows the same states.

| State | When |
|---|---|
| `active` | The service accepted a report of the installation within the last 3 days, and neither state below applies. |
| `at_risk` | No accepted report for 3 days. A store whose scheduler runs rarely may show as `at_risk` briefly; this is an accepted trade-off. |
| `disconnected` | The latest accepted report is a `disconnected` event that leaves no connection. |
| `deactivated` | The latest accepted report is a `deactivated` event. |

A `disconnected` event that leaves connections updates them, and the
installation stays `active`. A later accepted `snapshot` report returns a
`disconnected` or `deactivated` installation to `active`. A `disconnected` or
`deactivated` installation does not turn `at_risk`: its silence is expected.

The service deletes an installation's record and its report history **24
months** after its last accepted report, whatever its state.

---

## Report body

### Top-level fields

| Field | Type | Required | Rules |
|---|---|---|---|
| `product` | string | yes | An id from the [Products](#products) registry. |
| `product_version` | string | yes | 1–32 characters. |
| `platforms` | array of objects | yes | 1–5 items. The names the registry lists for the product. Each name at most once. |
| `platforms[].name` | string | yes | A name from the registry row of the product. |
| `platforms[].version` | string | yes | 1–32 characters. |
| `php_version` | string | when the registry says "required" | The server's PHP version (`PHP_VERSION`), 1–32 characters, for example `8.2.14`. Not sent by a hosted product. |
| `installation_url` | string | yes | The installation's main address. See [Addresses](#addresses). The service uses it, with `product`, to recognise the installation. |
| `smaily_subdomain` | string | yes | The Smaily account whose credentials the request carries (see [Authentication](#authentication)). Same rule as `connections[].smaily_subdomain`. In a `snapshot` report it equals the `smaily_subdomain` of at least one `marketing` connection. |
| `event` | string | no | `snapshot` (the default when absent), `disconnected` or `deactivated`. See [Events](#events). |
| `trigger` | string | when `event` is `snapshot` | `setup_saved` or `daily`. Not sent with another event. |
| `sent_at` | string (timestamp) | yes | When the plugin built the report. |
| `connections` | array of objects | yes | 1–100 items; 0–100 in a `disconnected` report. See [Connections](#connections). |

### Connections

A **connection** is one store address joined to one Smaily account in one
role. One report carries every connection of the installation.

| Field | Type | Required | Rules |
|---|---|---|---|
| `role` | string | yes | `marketing` or `transactional` (see [Products](#products)). |
| `store_url` | string | yes | The address of the store this connection serves. See [Addresses](#addresses). |
| `smaily_subdomain` | string | yes | The connected Smaily account's subdomain only (`example`, not a full host name). 1–63 characters, `^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`. |
| `website` | string | Magento and PrestaShop | 1–64 characters. Magento: the website `code`, exactly as Magento stores it. PrestaShop: the shop id as a string (for example `1`), the same value the module sends Campaign Intelligence as the website code. Not sent by another product. |
| `language` | string | when the product connects one Smaily account per language | The language this account serves, a two-letter ISO 639-1 code in lowercase (`et`, `en`). |
| `features` | array of strings | yes | 0–50 feature ids, each at most once, each `^[a-z0-9_]{1,64}$`. An empty list means no feature is switched on. |
| `smaily` | object | yes | The health of this connection's Smaily account. |
| `smaily.status` | string | yes, inside `smaily` | `ok` or `failing`. `failing` when the most recent Smaily API request of this connection failed. |
| `smaily.last_sync_at` | string (timestamp) or `null` | yes, inside `smaily` | The last time a Smaily API request of this connection that writes data (contacts, workflow triggers, transactional emails) succeeded. `null` when none has succeeded yet. |
| `smaily.last_error_code` | string or `null` | yes, inside `smaily` | The [error code](#error-codes-in-a-report) of the failure that makes `status` `failing`. `null` when `status` is `ok`. |
| `intelligence` | object | when Campaign Intelligence is set up on this connection | The Campaign Intelligence engine store this connection sends to. Campaign Intelligence is set up when the plugin holds an engine store for the connection. Not sent otherwise. |
| `intelligence.tenant_id` | string | yes, inside `intelligence` | The engine store's identifier as the plugin holds it (a UUID that Campaign Intelligence issued at setup). Never its API key. It is the join key to Campaign Intelligence's own records. |
| `intelligence.connected` | boolean | yes, inside `intelligence` | `true` when the plugin holds a working connection to the engine store. `false` when Campaign Intelligence refused it (for example the key was revoked or the store was paused). |
| `intelligence.status` | string | yes, inside `intelligence` | `ok` or `failing`. `failing` when the most recent Campaign Intelligence request of this connection failed. |
| `intelligence.last_sync_at` | string (timestamp) or `null` | yes, inside `intelligence` | The last time Campaign Intelligence accepted an ingest request of this connection. `null` when none has been accepted yet. |
| `intelligence.last_error_code` | string or `null` | yes, inside `intelligence` | The [error code](#error-codes-in-a-report) of the failure that makes `status` `failing`. `null` when `status` is `ok`. |

Each connection is unique by `role`, `store_url`, `website` and `language`
together. Several connections can name the same `intelligence.tenant_id`
(Magento websites of one Smaily account share one engine store).

A feature id that is not in the registry is accepted and stored as sent. So a
plugin release is never refused because the registry is one version behind.

### Error codes in a report

`smaily.last_error_code` and `intelligence.last_error_code` carry a code, never
an error message, a response body or text a person typed. The plugins reuse
the codes they already have:

- **Smaily side** — the three causes of a refused Smaily request that
  WooCommerce's `RefusalReason` names (PRO-1686) and Magento and Shopify also
  tell apart:
  - `plan_blocked` — Smaily answered code `227`: the account's package has no
    API.
  - `credentials_rejected` — any other `4xx` except `429` (a wrong subdomain
    answers `404`).
  - `unreachable` — `429`, `5xx`, a timeout or a network error.
- **Campaign Intelligence side** — the `error` code of Campaign Intelligence's
  error body as the plugin received it (for example `api_key_revoked` or
  `rate_limit_exceeded`), or `network_error` when no answer came (the code
  WooCommerce's client already uses).

Any other code that matches `^[a-z0-9_]{1,64}$` is accepted and stored as
sent.

### How each product fills `connections`

- **WooCommerce, one Smaily account**: one connection. `store_url` is the
  site's address. No `website`, no `language`.
- **WooCommerce, one Smaily account per language**: one connection per language
  account, each with the site's address and its `language`.
- **Magento, one website**: one connection, with `website`.
- **Magento, several websites**: one connection per website that has a
  connected Smaily account. Each connection carries its own website's address,
  `website` code, Smaily account, features and engine store. Websites that
  share one Smaily account give one connection each, with the same
  `smaily_subdomain`. A website with no connected Smaily account is left out.
- **PrestaShop, one shop**: one connection, with `website`.
- **PrestaShop, several shops**: one connection per shop that has a connected
  Smaily account. Each connection carries its own shop's address, `website`,
  Smaily account, features and engine store. Shops that share one Smaily
  account give one connection each, with the same `smaily_subdomain`. A shop
  with no connected Smaily account is left out.
- **PrestaShop, one Smaily account per language**: one connection per language
  account of the shop, each with the shop's address, its `website` and its
  `language`.
- **Shopify**: one installation per shop. One connection per Smaily account of
  the shop: one connection with no `language`, or one connection per language
  account, each with its `language`.

Every connection above has `role` `marketing`. A separate Smaily Transactional
account (WooCommerce, Magento) adds one connection with `role` `transactional`
for each store address, `website` and `language` it serves. Its `features`
holds `transactional_emails` when order and shipping emails are switched on.

WooCommerce and Shopify hold one engine store per site or shop. Every connection
with `intelligence` names that one engine store.

### Addresses

`installation_url` and `store_url` follow the same rules:

- An absolute `https` or `http` URL, at most 255 characters.
- No user name or password, no query and no fragment. A path is allowed (a
  WordPress site in a subfolder).
- `installation_url` is the WordPress site address (WooCommerce), the base URL
  of the default scope (Magento), the main URL of the default shop (PrestaShop)
  or `https://{shop}.myshopify.com` (Shopify).
- The service compares addresses after it lowercases the scheme and the host
  and removes one trailing `/`.

A copy of a site (a staging clone) has a different `installation_url`, so it
is its own installation. A store that moves to a new address becomes a new
installation, and the old one goes silent.

### Example — WooCommerce, one Smaily account and a Transactional account

The request carries the credentials of `example`, the `default` account. The
transactional connection is declared.

```json
{
  "product": "connect_woocommerce",
  "product_version": "3.21.1",
  "platforms": [
    { "name": "wordpress", "version": "6.8.3" },
    { "name": "woocommerce", "version": "9.9.5" }
  ],
  "php_version": "8.2.14",
  "installation_url": "https://example-store.test",
  "smaily_subdomain": "example",
  "event": "snapshot",
  "trigger": "daily",
  "sent_at": "2026-11-05T09:14:00Z",
  "connections": [
    {
      "role": "marketing",
      "store_url": "https://example-store.test",
      "smaily_subdomain": "example",
      "features": ["subscriber_sync", "abandoned_cart", "campaign_intelligence"],
      "smaily": {
        "status": "ok",
        "last_sync_at": "2026-11-05T08:55:12Z",
        "last_error_code": null
      },
      "intelligence": {
        "tenant_id": "0b7e4f2a-9c1d-4e8b-a3f6-5d2c8e1b7a90",
        "connected": true,
        "status": "ok",
        "last_sync_at": "2026-11-05T09:02:40Z",
        "last_error_code": null
      }
    },
    {
      "role": "transactional",
      "store_url": "https://example-store.test",
      "smaily_subdomain": "example-transactional",
      "features": ["transactional_emails"],
      "smaily": {
        "status": "ok",
        "last_sync_at": "2026-11-05T08:41:07Z",
        "last_error_code": null
      }
    }
  ]
}
```

### Example — Magento, two websites with their own Smaily accounts

The request carries the credentials of `example`, the default scope's account.
The second connection is declared. Smaily refused its credentials the last time
the module called it.

```json
{
  "product": "connect_magento",
  "product_version": "3.0.0",
  "platforms": [
    { "name": "magento", "version": "2.4.7-p3" }
  ],
  "php_version": "8.3.6",
  "installation_url": "https://example-store.test",
  "smaily_subdomain": "example",
  "trigger": "setup_saved",
  "sent_at": "2026-11-05T10:00:03Z",
  "connections": [
    {
      "role": "marketing",
      "store_url": "https://example-store.test",
      "website": "base",
      "smaily_subdomain": "example",
      "features": ["subscriber_sync", "welcome_automation", "campaign_intelligence"],
      "smaily": {
        "status": "ok",
        "last_sync_at": "2026-11-05T09:48:00Z",
        "last_error_code": null
      },
      "intelligence": {
        "tenant_id": "6d1f0c3e-2a7b-4c59-8e14-b9a0f3d5c217",
        "connected": true,
        "status": "ok",
        "last_sync_at": "2026-11-05T09:50:10Z",
        "last_error_code": null
      }
    },
    {
      "role": "marketing",
      "store_url": "https://second-example-store.test",
      "website": "second",
      "smaily_subdomain": "example-second",
      "features": ["subscriber_sync"],
      "smaily": {
        "status": "failing",
        "last_sync_at": null,
        "last_error_code": "credentials_rejected"
      }
    }
  ]
}
```

### Example — PrestaShop, two shops with their own Smaily accounts

The shops are set up separately, so the installation has no main account. The
request carries the credentials of `example`, the account of the first
`marketing` connection. The second connection is declared. `website` holds
each shop's id.

```json
{
  "product": "connect_prestashop",
  "product_version": "3.0.0",
  "platforms": [
    { "name": "prestashop", "version": "8.2.8" }
  ],
  "php_version": "8.1.30",
  "installation_url": "https://example-store.test",
  "smaily_subdomain": "example",
  "trigger": "daily",
  "sent_at": "2026-11-20T07:30:00Z",
  "connections": [
    {
      "role": "marketing",
      "store_url": "https://example-store.test",
      "website": "1",
      "smaily_subdomain": "example",
      "features": ["subscriber_sync", "abandoned_cart", "campaign_intelligence"],
      "smaily": {
        "status": "ok",
        "last_sync_at": "2026-11-20T07:12:00Z",
        "last_error_code": null
      },
      "intelligence": {
        "tenant_id": "2c9d7e41-5f3a-4b86-9d02-7a1e6c4f8b35",
        "connected": true,
        "status": "ok",
        "last_sync_at": "2026-11-20T07:20:15Z",
        "last_error_code": null
      }
    },
    {
      "role": "marketing",
      "store_url": "https://second-example-store.test",
      "website": "2",
      "smaily_subdomain": "example-second",
      "features": ["subscriber_sync", "welcome_automation"],
      "smaily": {
        "status": "ok",
        "last_sync_at": "2026-11-20T07:12:04Z",
        "last_error_code": null
      }
    }
  ]
}
```

### Example — Shopify, one Smaily account per language

```json
{
  "product": "connect_shopify",
  "product_version": "2026.11.05",
  "platforms": [
    { "name": "shopify", "version": "2026-10" }
  ],
  "installation_url": "https://example-store.myshopify.com",
  "smaily_subdomain": "example",
  "trigger": "daily",
  "sent_at": "2026-11-05T13:31:20Z",
  "connections": [
    {
      "role": "marketing",
      "store_url": "https://example-store.myshopify.com",
      "language": "et",
      "smaily_subdomain": "example",
      "features": ["subscriber_sync", "first_order_automation"],
      "smaily": {
        "status": "ok",
        "last_sync_at": "2026-11-05T13:10:00Z",
        "last_error_code": null
      }
    },
    {
      "role": "marketing",
      "store_url": "https://example-store.myshopify.com",
      "language": "en",
      "smaily_subdomain": "example-en",
      "features": ["subscriber_sync", "first_order_automation"],
      "smaily": {
        "status": "ok",
        "last_sync_at": "2026-11-05T13:10:02Z",
        "last_error_code": null
      }
    }
  ]
}
```

---

## Endpoint: POST /v1/reports

Sends an installation report.

**URL**: `POST https://subscription.smaily.com/v1/reports`

**Auth**: `Authorization: Basic …` with the main account's Smaily API
credentials (see [Authentication](#authentication)).

**Body**: the [report body](#report-body).

**Response 200 OK** — the service accepted the report:

```json
{
  "installation_id": "3f8a1c52-7b9e-4d1a-9c3e-2a6f5b8d0e17"
}
```

The body always carries `installation_id`. The plugin may store it. Since
1.2.0 the body can also carry `latest_version` and `notice` (see
[Answer fields](#answer-fields)). The plugin ignores any other field.

The service also answers `200` to a report whose main account answers Smaily
code `227`. It marks that report "package blocks the API" (see
[How the service checks the credentials](#how-the-service-checks-the-credentials)).
The answer looks the same to the plugin.

**Errors**: `400 invalid_json`, `400 validation_failed`, `400 unknown_product`,
`401 invalid_smaily_credentials`, `413 body_too_large`, `429 rate_limited`,
`503 smaily_unreachable`, `5xx` (see [Error handling](#error-handling)).

---

## Answer fields

The fields of the `200` answer (since 1.2.0, SUB-30):

| Field | Type | Present when | Rules |
|---|---|---|---|
| `installation_id` | string | always | See [Base context](#base-context). |
| `latest_version` | string | staff saved a latest released version for the report's `product` | 1–32 characters, in the shape `^\d+(\.\d+)*(-[0-9A-Za-z.]+)?$`. One value per product. |
| `notice` | object | staff saved a notice for the report's `product`, and the report's `product_version` is lower than the notice's "below version" | Carries `text` and `url`. |
| `notice.text` | string | always inside `notice` | 1–300 characters of plain text in one paragraph: no line break, no control character, no `<` and no `>`. It is not HTML and not Markdown. |
| `notice.url` | string | always inside `notice` in this version | An absolute `https` URL, at most 255 characters, default port, no user name, no password, no fragment. The host is `smaily.com` or a subdomain of `smaily.com`. The service saves only a host on its own, shorter list. |

**Lower** means release order: numbers compare as numbers, and a pre-release
comes before its release (`3.0.0-rc21` < `3.0.0` < `3.10.0`). A
`product_version` outside the shape of `latest_version` gets no notice.

Example, a store on 3.21.1 after staff saved both (the text and the address
are invented):

```json
{
  "installation_id": "3f8a1c52-7b9e-4d1a-9c3e-2a6f5b8d0e17",
  "latest_version": "3.24.0",
  "notice": {
    "text": "Smaily Connect 3.24.0 is out. It fixes the abandoned cart reminder.",
    "url": "https://smaily.com/connect/woocommerce/3-24"
  }
}
```

- A field that does not apply is left out. It is never `null`.
- `latest_version` is the latest released version of the report's
  `product`, as Smaily staff set it. The plugin compares it with its own
  version by its platform's version comparison. A hosted product gets none.
- `notice` is a short message from Smaily to the merchant of this store.
  Smaily chooses the stores by product and reported version. The plugin
  shows a `notice` that is present; it does not decide who gets one.
- `notice.text` is plain text. The plugin escapes it for the place it shows
  it in and never renders it as HTML or Markdown.
- The plugin shows `notice.url` only when it is an `https` URL whose host is
  `smaily.com` or ends with `.smaily.com`. When the host fails this check,
  the plugin drops the whole notice. When `url` is absent, the plugin shows
  the text without a link.
- The plugin keeps the `latest_version` and the `notice` of its latest `200`
  answer to a `snapshot` report, and nothing older. A `200` answer without
  a field clears the kept one. Any other answer changes nothing. The plugin
  also clears both when its own version changes.
- The plugin reads the answer in the background job that sent the report.
  It never calls the service to show a notice.
- How the plugin shows the latest version and the notice is the plugin's
  own design.

The answer is the same for every accepted report of a product and version:
a `snapshot` report, an event report, and a report the service marked
"package blocks the API".

---

## Error handling

### Error body

Every error answer has this body:

```json
{
  "error": "validation_failed",
  "message": "One or more fields failed validation.",
  "details": {
    "errors": [
      { "field": "connections[1].smaily_subdomain", "code": "invalid_value" }
    ]
  }
}
```

- `error` — the machine-readable code. The plugin branches on it.
- `message` — a sentence for a log line. The plugin never branches on it.
- `details` — only for `validation_failed`. `details.errors[].code` is one of
  `required`, `invalid_type`, `invalid_value`, `too_long`, `too_many`,
  `duplicate`, `not_allowed`.

The service validates the body before it calls Smaily. A body that fails
validation never reaches the credential check.

### Error codes

| HTTP | `error` | Meaning | Kind |
|---|---|---|---|
| 400 | `invalid_json` | The body is not JSON, or the Content-Type is not JSON. | refused |
| 400 | `validation_failed` | A field is missing, has the wrong type, is too long or breaks a rule. This includes a `snapshot` report whose top-level `smaily_subdomain` no `marketing` connection names. | refused |
| 400 | `unknown_product` | `product` is not in the registry. | refused |
| 401 | `invalid_smaily_credentials` | The `Authorization` header is missing or not Basic, or Smaily refused the credentials for `smaily_subdomain`. | refused |
| 413 | `body_too_large` | The body is larger than 64 KiB. | refused |
| 429 | `rate_limited` | Too many requests. The answer carries `Retry-After`. | cannot take in now |
| 500 | `internal_error` | The service failed. | cannot take in now |
| 503 | `smaily_unreachable` | The service could not check the credentials because the Smaily API did not answer in time or failed. The answer carries `Retry-After`. | cannot take in now |
| 503 | `unavailable` | The service cannot take reports now. The answer may carry `Retry-After`. | cannot take in now |

A `502` or `504` from a proxy in front of the service, a timeout and a network
error count as "cannot take in now".

A main account whose package blocks the API (Smaily code `227`) is not an
error. The service accepts the report with `200`.

### Rate limits

| Request | Limit |
|---|---|
| Reports | 60 per installation per hour |
| Refused credential checks | 10 per Smaily subdomain per hour. Then `429` for that subdomain until the hour ends. |

The second limit keeps the service from becoming a way to guess a Smaily
password. `Retry-After` is a whole number of seconds.

---

## What the plugin does with each answer

| Answer | Plugin action |
|---|---|
| `200` | Keeps `latest_version` and `notice` as [Answer fields](#answer-fields) says; a plugin that does not read them does nothing more. The next daily report is due 24 hours later. A report the service marked "package blocks the API" gets the same `200`, and the plugin does the same. |
| `400` (any code), `413` | Do not retry this body. Write one line to the product's log with `error`. The next scheduled report tries again. |
| `401 invalid_smaily_credentials` | Do not retry. Write one line to the product's log. The next scheduled report tries again. The plugin's own connection check tells the merchant about wrong credentials; the report adds no notice. |
| `3xx` (any redirect) | Do not follow it and do not retry. Write one line to the product's log. The next scheduled report tries again. A redirect means the plugin's base URL is wrong. |
| `429`, `5xx` (also `503 smaily_unreachable`), timeout, network error | Retry up to 3 times, after 60, 120 and 240 seconds, each plus a random 0–30 seconds. If `Retry-After` is larger, wait `Retry-After` instead. If `Retry-After` is more than 1 hour, give up and wait for the next scheduled report. After the last try, give up. |
| Any answer to a `disconnected` or `deactivated` report | Do not retry. The plugin goes on with the disconnect or the deactivation, whatever the answer. |

In every case the store keeps working as before. The plugin never shows a
merchant an admin notice about a report. A `notice` in the answer is
Smaily's message about the product, not about the report.

---

## What a report never carries

A report holds only the fields this contract names. It never carries:

- **Customer or subscriber data**: no email address, name, phone number,
  address, customer ID, consent state or contact count of any person.
- **Order data**: no order, cart, product, price or revenue.
- **Credentials in the body**: no Smaily API user name or password, no Campaign
  Intelligence API key, no Shopify access token, no other password, token or
  key. The only credentials in a request are the main account's Smaily API user
  name and password, in the `Authorization` header, and the service only checks
  them.
- **Admin user data**: no name or email address of a store administrator or
  staff member.
- **Free text**: no field holds text a person typed, except the store
  addresses and the Smaily subdomains.
- **Error messages**: a connection's health carries error codes only, never an
  error message or a response body.

The service ignores and does not store a field this contract does not name.

---

## Suggested wording for the plugin's readme

Each product's readme or user guide says in one sentence what it sends (per
CON-290). Suggested wording for the WooCommerce `readme.txt`, under "External
services":

> When a Smaily account is connected, Smaily Connect sends Smaily an
> installation report when you save the settings, once a day, and when you
> remove a connection or deactivate the plugin, authenticated with the
> connected Smaily account's API credentials, which Smaily only checks and
> never stores: the site address, the WordPress, WooCommerce, PHP and plugin
> versions, the connected Smaily accounts, the switched-on features, whether
> each connection works (error codes only) and the time of the last successful
> sync — never customer, subscriber or order data. Smaily's answer can carry
> the latest version of the plugin and a short notice from Smaily, which the
> plugin shows in the admin.

Magento and Shopify use the same sentence with their own platform names
(Magento and PHP, "remove the module"; Shopify without PHP, "the app" in place
of "Smaily Connect", "uninstall the app"). The sentence about Smaily's answer
(since 1.2.0) is for the self-hosted products: a hosted product's answer
carries neither field.

---

## Versioning

- The path carries the major version: `/v1/...`.
- **Minor** version (`1.1.0`): an additive change. A new optional field in the
  request, a new field in the response, a new product, platform or feature id
  in the registry, a new error code for a new case. A plugin built against
  `1.0.0` keeps working without a change.
- **Breaking** change: a removed or renamed field, a new required field, a
  changed meaning or type. A breaking change gets a new path version (`/v2/...`).
  The service keeps serving `/v1` while installations still send to it.
- Each version of this contract lists its changes in the [Changelog](#changelog).

---

## Kept for later

- The service keeps **every accepted report** as history, not only the latest
  one, until the deletion in [Installation states](#installation-states). So
  version adoption and feature changes over time can be reported later.
- The **Smaily subdomain** is the account key. It later maps to the account
  ids of Smaily v1, v2 and v3.
- Each stored connection keeps whether it was **verified** or **declared**. A
  later version can verify a declared account, for example with that account's
  own credentials.
- The **engine store's `tenant_id`** is the join key to Campaign Intelligence's
  own records.
- **Plans** (free or paid) of the Smaily account and of Campaign Intelligence
  are not known to the plugin, so a report does not carry them. They come later
  from Smaily's and Campaign Intelligence's own feeds, joined by the Smaily
  subdomain and `tenant_id`. Campaign Intelligence is to be bundled into paid
  Smaily accounts later (an opt-in on v1, built in on v3), which is why both
  join keys matter.

---

## Not in v1

Later contract versions add these. v1 does not carry them.

- **Feature access answers in the reply**: which features a customer may use
  (entitlements).
- **Packages**: the customer's Smaily package and its changes.
- **Invoices and payments**.
- **Campaign Intelligence tenants and plans.** The plugin does not send them.
  Campaign Intelligence reports its tenants and plans to the service later, as
  its own product, joined by `tenant_id`.
- **Proof for a v2 or v3 platform account** (Bearer tokens on `smaily.app`).

---

## Decisions (2026-11-05)

Erkki answered the open questions on 2026-11-05.

- **A → A2.** An installation turns `at_risk` (in place of "stopped") after 3 days without an accepted report; its record and report history are deleted 24 months after the last accepted report.
- **B → B1.** The base URL is `https://subscription.smaily.com`, a Smaily DNS record that points at the hosting platform.
- **C → C2.** A separate Smaily Transactional account is reported as a connection with `role` `transactional`; it is declared and never authenticates a report.
- **D → D1.** A report whose main account answers Smaily code `227` is accepted, marked "package blocks the API" and not cached.

---

## Changelog

- **1.0.0-draft** (2026-11-05) — first draft (SUB-3). Simplified the same day:
  a report authenticates with the connected Smaily account's API credentials,
  which the service checks with one Smaily API call; registration, installation
  keys, product keys and the account-verification question are removed; one
  endpoint `POST /v1/reports` answers `200` with `installation_id`; each
  connection carries an optional `intelligence` object.
- **1.0.0-draft** (2026-11-05, decisions) — Erkki's answers A2, B1, C2, D1 applied, and his additions: the optional `event` field (`snapshot`, `disconnected`, `deactivated`), per-connection health (`smaily` and `intelligence` `status`, `last_sync_at`, `last_error_code`, `intelligence.connected`), the installation states `active`, `at_risk`, `disconnected`, `deactivated`, and plans kept for later.
- **1.0.0** (2026-11-05) — approved by Erkki (SUB-3).
- **1.0.1** (2026-11-12) — approved by Erkki (SUB-3). Transport: the hosting
  platform answers plain HTTP with a `308` redirect to HTTPS and cannot be set
  to refuse it, so the contract no longer promises a refusal. The plugin
  always uses the `https` base URL and never follows a redirect; a `3xx`
  answer is logged and not retried. No plugin was built against 1.0.0.
- **1.1.0** (2026-11-20) — approved by Erkki (SUB-28). PrestaShop is added:
  the registry row `connect_prestashop` with the platform `prestashop`, PHP
  version required, no transactional account; `connections[].website` is
  required for PrestaShop too and holds the shop id; the main account, the
  `installation_url` and the connections of a PrestaShop installation; an
  example of two shops with their own Smaily accounts. Additive: a report of
  WooCommerce, Magento or Shopify does not change.
- **1.2.0** (2026-11-20) — approved by Erkki (SUB-30). The `200` answer can
  carry `latest_version` and `notice` (`text`, `url`). Additive: a plugin
  that does not read them keeps working, and a product with no setting gets
  the answer of 1.1.0.
