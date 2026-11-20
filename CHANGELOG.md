# Changelog

### Unreleased — 3.0.0

- Stores the Smaily API password encrypted. The upgrade encrypts the stored password and removes the plain-text copy.
- Refuses a cron URL request whose token is empty or wrong. On upgrade, an empty cron token is replaced with a new random token, so the cron URL of those stores changes.
- Runs every background task from one cron line. The Dashboard's Background tasks card shows it under "Show the cron line"; it replaces the two crontab lines of 2.x, which keep working with their tokens.
- No longer installs an override of the Newsletter Subscription module (`ps_emailsubscription`). The upgrade removes the override file that 2.x installed, and leaves any other file in place.
- Uninstall removes all module data: every setting, the cron tokens and the module's database tables.
- Syncs contacts by the chosen consent mode: subscribers only, all customers, or checkout opt-in only. An upgraded store starts in subscribers only.
- In subscribers only, mirrors unsubscribes and re-subscribes in Smaily to the store. The first check after the upgrade takes only unsubscribes, from Smaily's list of unsubscribers as 2.x did, and marks nobody subscribed again.
- Sends guest-checkout customers to Smaily only when "include guests" is on. In checkout opt-in only, a ticked newsletter box is sent in any case. An upgraded store has "include guests" on.
- A cron or tick URL that completes an interrupted upgrade answers "OK: ran migration."
- Replaces the 2.x configuration page with the Settings page under Customers > Smaily Connect. Its Connection tab holds the Smaily account, with Test connection and the connection's status; its Contacts tab holds contact synchronization, the consent mode, the extra fields and the first import; its RSS tab builds the product feed URL.
- Asks for confirmation before a Contacts save that changes who is synced to Smaily.
- Keeps the product RSS feed at its 2.x address with its 2.x parameters (`category_id`, `limit`, `sort_by`, `sort_order`), so feed URLs in existing Smaily templates keep working.
- A multishop store runs its shops as one store or as separate stores, and changes that in Settings. In one store, a shop can use its own Smaily account or its own Contacts settings. A change from separate stores to one store gives every shop the settings of the selected shop and removes the other shops' own settings.
- The abandoned-cart reminder always carries the name, description, SKU, price, base price and quantity of up to 10 products, and the customer's first and last name when the store knows them. The choice of fields is gone: a store that had fields unticked in 2.x sends them all after the upgrade.
- The abandoned-cart delay is from 10 to 1435 minutes (2.x: at least 15), and 30 minutes on a new store. A cart older than 24 hours is never reminded. An upgraded store keeps its delay; a store whose 2.x delay was longer than 1435 minutes sends no reminder until a shorter delay is saved.
- Adds the Log under Customers > Smaily Connect: one list of every contact sync, automation trigger and contact import, with its status, attempts and last error, filters and the count of events that failed in the last 24 hours. Details show what was sent and what Smaily answered; passwords and API keys are never stored or shown. A failed event can be sent again, and failed rows can be retried together.

### 2.2.0

- Adds PrestaShop 9.x support.
- Replaces the Guzzle HTTP client, which PrestaShop 9 no longer bundles, with the Symfony HTTP client.
- Requires PHP 8.0 or newer and refuses to install on older PHP. Version 2.0.0 already needed PHP 8 without saying so.

### 2.1.2

Fixes RSS-feed not rendering when using a category filter.

### 2.1.1

Adds `is_abandoned_cart` field to abandoned cart request payload
Does not opt-in unsubscribed customers who have received abandoned cart email

### 2.1.0

Improving customers synchronization by including subscribers from `ps_emailsubscription` table to subscriber synchronization. [#60](https://github.com/sendsmaily/smaily-prestashop-module/pull/60)

- Marks newsletter registrations as not active when subscriber has been unsubscribed in Smaily.
- Imports newsletter registrations with active status to Smaily during customer synchronization.
- Adds a `store` parameter to subscriber data allowing better filtering in Smaily UI.
- Updates customer subscription status in Smaily when Customer updates their account.

### 2.0.0

- Total rewrite of the module to support PrestaShop 8 new features and standards. [[#57](https://github.com/sendsmaily/smaily-prestashop-module/pull/57)]
- Module now uses the built-in Newsletter Subscription module to provide functionality for opt-in trigger instead of creating a custom template. This allows to keep internal subscriber addition logic and add required opt-in trigger functionality.

### 1.6.1

- Update user manual links [[#51](https://github.com/sendsmaily/smaily-prestashop-module/pull/51)]

### 1.6.0

- Align customer synchronization first and last name with abandoned cart [[#42](https://github.com/sendsmaily/smaily-prestashop-module/pull/42)]

### 1.5.0

- New feature to trigger opt-in if new customer joins with newsletter enabled [[#32](https://github.com/sendsmaily/smaily-prestashop-module/issues/32)]
- Fix missing template variables [[#33](https://github.com/sendsmaily/smaily-prestashop-module/issues/33)]
- Use PrestaShopLogger for logs created by module [[#36](https://github.com/sendsmaily/smaily-prestashop-module/issues/36)]

### 1.4.0

- Added RSS settings tab.
- New options for RSS product feed: order by, order direction, product category.
- Generate RSS feed in more user friendly manner.
- Fix bug which caused invalid CRON URLs, when Friendly URL option was disabled.
- Add Estonian translations for additional fields in abandoned cart settings.

### 1.3.0

- Standardize Abandoned Cart email template parameters across integrations
- `firstname` and `lastname` parameter changed to `first_name` and `last_name`
- `product_description_short` parameter changed to `product_description`
- Added `product_base_price` and `product_sku` parameter
- Removed `product_category` field

### 1.2.2

- Data batching for newsletter subscribers synchronization
- Validating credentials ajax call failing due to wrong post url

### 1.2.1

- Support for PHP 5.6

### 1.2.0

- Changes due to Smaily workflows automation
- Subdomain field parsed when full url entered
- Separate url-s to run customer and cart cron
- Settings page updated for better user-friendliness
- Cron tokens auto generated and url-example now dynamic
- Optimized newsletter subscribe form to use in left/right column of your webpage
- Estonian language translations
- You can now remove validated credentials from admin page
- Subscribe newsletter form sends user language
- Abandoned cart didn't erase email fields that were previously sent
- Customer cron did't get new state of unsubscribed customers before synchronizing with Smaily
- Rss-feed did't show discount and price correctly with taxes

### 1.1.0 - 2019

- New Feature. Added Abandoned cart support
- Changed admin page behaviour when API credentials allready validated

### 1.0.0 - 2018

- This is the first public release
