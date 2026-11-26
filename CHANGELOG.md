# Changelog

### Unreleased — 3.0.0

- Upgrades a 2.x store in place: the Smaily account, contact synchronization, the automations with their workflows, the RSS defaults and the record of reminded carts are carried over. Notices on the Smaily Connect pages tell what the upgrade changed or left switched off. An interrupted upgrade finishes with "Finish upgrade" on those pages, or on its own with the next run of a cron URL. A Smaily account that you save in Settings while the upgrade is not finished stays: the upgrade does not put the 2.x account back in its place.
- Stores the Smaily API password encrypted. The upgrade encrypts the stored password and removes the plain-text copy.
- A cron URL answers at once and runs the background tasks after its answer, so a slow answer of Smaily no longer makes the web server end the cron's request with an error (nginx: 504 after 30 seconds). Where the server cannot send an answer early (Apache with mod_php), the URL answers when the tasks have run, as before.
- Refuses a cron URL request whose token is empty or wrong. On upgrade, an empty cron token is replaced with a new random token, so the cron URL of those stores changes.
- Runs every background task from one cron line. The Dashboard's Background tasks card shows it while the tasks do not run from a cron line, and under "Show the cron line" while they do; it replaces the two crontab lines of 2.x, which keep working with their tokens.
- Runs the background tasks also when visitors open pages of the store, after the page is sent, so a store without a cron line still sends its contacts and automation emails. The Background tasks card then shows "Visitors only". On a server that cannot send a page before the work is done (PHP without PHP-FPM or LiteSpeed), the tasks need the cron line.
- The upgrade deletes the files of versions 2.x and 1.6.x that 3.0.0 no longer uses from the module's folder: the old settings forms, templates, scripts and classes. A file that cannot be deleted stays, and a notice on the Smaily Connect pages names it, so that you can delete it by hand.
- No longer installs an override of the Newsletter Subscription module (`ps_emailsubscription`). The upgrade removes the override file that 2.x installed, and leaves any other file in place.
- Uninstall removes all module data: every setting, the cron tokens, the module's database tables and its cache files.
- The module is named Smaily Connect in the back office (2.x: Smaily for PrestaShop). Its technical name `smailyforprestashop` and its folder stay as they are.
- Module Manager shows the module with the new Smaily icon, as the WooCommerce and Magento connectors do: a pink rounded square with a white "S", 140 × 140 pixels as PrestaShop recommends (2.x: a flat 32 × 32 square).
- Replaces the 2.x configuration page with four pages under Customers > Smaily Connect: Dashboard, Initial setup, Settings and Log.
- Step 1 of the Initial setup continues only after the Smaily connection test passes ("Test your Smaily connection to continue."), and saves no Smaily account that Smaily refuses. A saved account counts as tested once Smaily accepts it when the page opens. Until then the step list opens no later step, and no later step saves while no Smaily account is saved; the first contact import and the Campaign Intelligence connection then start nothing either, also from Settings. An edit of the subdomain, username or password clears the connection status, in the Initial setup and in Settings > Connection, until a test or a save answers again.
- Settings > Connection saves no Smaily account that Smaily refuses, as step 1 of the Initial setup: "Save Connection" waits beside "Test your Smaily connection to continue." until the test passes, and a saved account that Smaily accepts when the page opens counts as tested.
- After a change of the Smaily account (another subdomain) or of the multilingual mode, an automation whose workflow the account does not have loses that workflow and sends nothing until you pick a workflow again. Before a change of account, while an automation has a workflow picked, the page asks first. Settings > Automations and step 3 of the Initial setup then show "Some automations have no workflow" with their names, and such an automation that is on reads "No workflow". Another API user of the same subdomain keeps the workflows.
- Settings has the tabs Connection, Contacts, Automations and RSS. The Connection tab holds the Smaily account, with Test connection and the connection's status; the Contacts tab holds contact synchronization, the consent mode, the extra fields and the first import; the RSS tab builds the product feed URL.
- The Log is one list of every contact sync, automation trigger and contact import, with its status, attempts and last error, filters and the count of events that failed in the last 24 hours. Details show what was sent and what Smaily answered; passwords and API keys are never stored or shown. A failed event can be sent again, and failed rows can be retried together.
- The Log's introduction names what goes to Campaign Intelligence, in an info box, as the Magento connector's; its Details open as "Delivery details", with the row's heading and buttons in place while the body scrolls, and name the Campaign Intelligence store of a row where the installation has several; a row that waits while Campaign Intelligence does not accept the account says so. Its times show as the back-office language writes a date.
- The Dashboard follows the Magento connector's: the line at the top reads "Setup incomplete" while the initial setup is not finished, and shows nothing while the upgrade is not finished (its banner says so); each connection card has a green or grey dot; Recent activity shows a withdrawn reminder as "Withdrawn", as the Log does, and its times as the back-office language writes a date; "View full log" and the Quick links end in an arrow; the tiles "Contact syncs" and "Catalog items" count every contact sync and every catalog row of the last 30 days, without a "50000+" bound; the Background tasks card's "Last run", and the last run of the background tasks on the Initial setup's Overview, show as the back-office language writes a date; a Smaily account whose package does not include API access reads "Not connected" with "Package does not include API access". The line about a refused Campaign Intelligence connection no longer has a button, and the Dashboard no longer has a line about two shops in one Campaign Intelligence store. The invitation to connect a Smaily account hides its preview on a narrow screen instead of stacking.
- A contact change that is sent late, or retried after it failed, is skipped once a newer change of the same contact reached Smaily, so it never undoes the contact's newer choice: for example, a subscription whose retry comes after the contact's newer unsubscribe was delivered. The Log shows the row as skipped, with the sentence "Skipped: a newer change of this contact already reached Smaily, and this older one would undo it. Nothing was sent.", and "Send again" refuses such a row. An unsubscribe is never skipped in favour of an older subscription, nor of a newer change that did not touch the subscription.
- While a Smaily account is connected, sends Smaily an installation report: when you save the setup or the Settings, once a day, and when you remove a connection or uninstall or reset the module. It holds the shop addresses, the PrestaShop, PHP and module versions, the connected Smaily accounts, the switched-on features, whether each connection works (error codes only) and the time of the last successful sync, and no customer, subscriber or order data. It is authenticated with the Smaily account's API credentials, which Smaily only checks and never stores. A store without a connected Smaily account sends nothing.
- Checks once a day whether a newer version of the module is out, and says so on the Smaily Connect pages, with links to download it and to read what is new. The check asks GitHub for the latest release and sends no store data.
- A multishop store runs its shops as one store or as separate stores, and changes that in Settings. In one store, a shop can use its own Smaily account, its own Contacts settings or its own automations; a shop on another Smaily account than the shared one always uses its own automations, and Settings says so until its workflows are picked. A change from separate stores to one store gives every shop the settings of the selected shop and removes the other shops' own settings. A store that was set up before it had several shops and answers "Separate stores" in the Initial setup gets the same change as in Settings: each shop keeps the settings it uses now as its own, and the Campaign Intelligence connection becomes the selected shop's; the question names that shop and any connection that ends, and asks to confirm, as Settings does.
- An install from the command line (`php bin/console prestashop:module install smailyforprestashop`, which hosting images also use) in a multishop store switches the module on in every shop; 2.x switched it on in the default shop only, so the other shops' feed and cron URLs answered "page not found". An install from Module Manager switches it on in the shops you have selected, as before, and an upgrade leaves the module switched off in a shop where it was off.
- Syncs contacts by the chosen consent mode: subscribers only, all customers, or checkout opt-in only. An upgraded store starts in subscribers only. A Contacts save that changes who is synced to Smaily asks for confirmation first.
- In a multishop store whose shops sync contacts to one Smaily account with different consent modes, Settings > Contacts and step 2 of the Initial setup warn each of those shops: Smaily keeps one subscription status per email address, so a contact that one shop sends can be marked subscribed in another shop too.
- A finished first contact import says how many contacts Smaily refused and links the Log, whose Details of the import list them by customer or newsletter subscriber id with Smaily's reason (the first 50; the rest are counted).
- Gender (`user_gender`, Male or Female), Customer ID (`customer_id`), Customer group (`customer_group`) and Subscription type (`subscription_type`, "Subscriber" for every contact) can be synced with each contact, as in the Magento connector. They are off until ticked under "Extra fields to sync with each contact"; an upgraded store keeps the fields it had.
- The first contact import says how many contacts it sends in the consent mode you pick, from this shop or from all shops. Until the Contacts settings are saved, and in checkout opt-in only, "Start import" stays on the card, greyed out.
- The import cards (the first contact import and the Campaign Intelligence imports) show "X of Y" and the percentage under the bar, the outcome with its mark and the time it finished, as the back-office language writes a date. An import that stopped at an error adds "Details for your developer:" with where the error happened (its type, its place in the code and its code), in the back-office language. A contact import that nothing has moved for an hour shows "Stalled", and "Run again" starts a fresh one; a Campaign Intelligence import that waits behind a stalled one says which.
- Every step of the Initial setup lays out its sections on the page, not in cards, as in the Magento connector; only the import cards and a finished setup's connection summary stay boxed. Settings keeps its cards.
- An automation's discount block without a cart rule to pick links "Add a cart price rule" to PrestaShop's new cart rule page. With per-language Smaily accounts, Settings > Automations and step 3 warn "Some languages have no workflow" with the languages each automation would send nothing to. The Campaign Intelligence Automations card names the Smaily account whose workflows it lists only when that is not the shop's own account, and without a connection shows Magento's "i" icon.
- Settings > Intelligence and step 4 of the Initial setup say when two shops share one Campaign Intelligence store (the Dashboard said so before). Step 4's notice "Your Campaign Intelligence account is not active" has "Check again", as Settings has.
- In subscribers only, mirrors unsubscribes and re-subscribes in Smaily to the store. The first check after the upgrade takes only unsubscribes, from Smaily's list of unsubscribers as 2.x did, and marks nobody subscribed again.
- Sends guest-checkout customers to Smaily only when "include guests" is on. A guest who ticks the newsletter box at checkout is sent in every consent mode in any case, as in the WooCommerce and Magento connectors. An upgraded store has "include guests" on.
- Syncs a newsletter subscriber without an account (PrestaShop's newsletter block), and sends the welcome email, in subscribers only and in all customers whatever "include guests" says; never in checkout opt-in only.
- An unsubscribe in the store — in the newsletter subscription form, on the customer's account page or in the back office — is sent to Smaily as unsubscribed in every consent mode, while contact sync is on; a shopper who subscribes again is sent as subscribed again, in all customers too.
- The welcome email follows contact sync: it goes only while contact sync is on, to a shopper whom the consent mode syncs. A store upgraded with the 2.x opt-in on and the customer sync off keeps both as they were, and a notice says that the welcome email sends nothing until contact sync is switched on, with a link to Settings > Contacts.
- The Automations tab of Settings, and the same step of the Initial setup, hold the welcome, first order and abandoned cart automations: each has a switch and a Smaily workflow, and the abandoned cart its waiting time. The workflow lists come live from the Smaily account; "Refresh workflows" loads them again. A workflow that is not in the account's list is refused.
- A store with several languages picks a workflow per language and a default fallback, when it uses one account with per-language workflows or a Smaily account per language.
- An upgraded store whose 2.x opt-in automation had no workflow keeps Smaily's opt-in trigger, shown in the Welcome automation as "Smaily's opt-in trigger (from 2.x)", until another workflow is saved.
- The welcome, first order and abandoned cart emails can each carry a discount code. In the automation's card, switch on "Send a discount code", pick one of your cart rules (Catalog > Discounts) and the number of days a code is valid (1 to 365). Each email then gets its own single-use code with the rule's discount and conditions, in the Smaily fields `welcome_coupon_code`, `first_order_coupon_code` or `abandoned_cart_coupon_code`, and its last valid day in the matching `…_coupon_expires_at` field. A customer with an account gets a code that only that customer can use; the codes show in Catalog > Discounts under a name that starts with "Smaily Connect". An unused code is removed 30 days after its last valid day.
- The first order email goes only to a customer whose marketing consent the store's consent mode accepts: every customer in all customers; a newsletter subscriber, a ticked newsletter box at checkout or a subscribed Smaily contact in subscribers only; a ticked box at checkout or a subscribed Smaily contact in checkout opt-in only. A contact who unsubscribed in Smaily never gets it. The Log shows an order without that consent as skipped.
- The abandoned-cart reminder always carries the name, description, SKU, price, base price and quantity of up to 10 products, and the customer's first and last name when the store knows them. The choice of fields is gone: a store that had fields unticked in 2.x sends them all after the upgrade.
- The abandoned-cart waiting time is from 10 to 1435 minutes (2.x: at least 15), and 30 minutes on a new store. A cart older than 24 hours is never reminded. An upgraded store keeps its waiting time; a 2.x waiting time longer than 1435 minutes becomes 1435 minutes on upgrade, and a notice on the Smaily Connect pages says so.
- A cart gets one reminder. The record of a reminded or skipped cart is deleted 30 days after the cart's last change; a cart that the shopper changes again after that can be reminded once more. The reminders that 2.x sent are kept by the same rule: 30 days from the day 2.x sent them.
- The abandoned-cart reminder follows the consent mode by the same rule as the first order email, where 2.x reminded every customer with a cart. An upgraded store starts in subscribers only, so it may send fewer reminders than 2.x did; the Log shows each cart that got none as skipped.
- Keeps the email address that a visitor types at checkout, before an account or an order exists, for that cart's reminder only: it is not sent to Smaily as a contact. A cart whose customer was deleted in the store gets no reminder, and its typed address is removed. The address is deleted once the cart has not changed for 24 hours, also when its reminder failed, or 48 hours after the cart's last change when a reminder was sent or skipped. A store keeps at most 2000 such addresses an hour, all its shops together; when that limit is reached, the Log says so once an hour.
- Answers a customer's privacy request. The export and the deletion of PrestaShop's Official GDPR compliance module (`psgdpr`) include the module's data: the Log rows queued for the address, its cart reminders, the email click that led to an order or waits with a cart, the discount codes issued to the customer, the contact imports' entries of the contacts Smaily refused and the personalization preference, with what Campaign Intelligence holds. The Log page has the form "Privacy request", with Export and Erase for one email address, with or without that module: on PrestaShop 9 that module's own admin screen cannot show a customer's data.
- An erasure deletes the messages that still wait, takes the contact's data out of the Log rows that stay (their Entity reads `[erased]`, and they cannot be sent again), removes the customer's unused discount codes and asks Campaign Intelligence to delete the customer; that request is retried until Campaign Intelligence answers. Used discount codes, orders, the customer account and Smaily contacts are not changed.
- Sends the privacy requests to Campaign Intelligence (the deletion of a customer and the personalization choices) at most 10 a minute per store, as Campaign Intelligence allows, so a large unsubscribe goes out over a few minutes and none of it is lost. When Campaign Intelligence asks the store to slow down, the request waits as long as asked and keeps all its attempts. Such an answer of Campaign Intelligence no longer counts as a failure in the installation report.
- A signed-in customer of a store that is connected to Campaign Intelligence can allow or stop personalized recommendations on the page "Personalization" of their account. Unsubscribing from the newsletter also stops personalization; subscribing again starts it again, unless the customer stopped it themselves.
- Connects the store to Campaign Intelligence on the new Intelligence tab of Settings and on step 4 of the Initial setup: paste the setup link from Smaily and click Connect. A link that is not taken leaves the store unconnected, and the page says why. Check again asks Campaign Intelligence about the connection, and Disconnect ends it after a question. A store whose shops are run as one store has one connection for every shop; shops run as separate stores connect each on its own.
- Connecting sends the store's existing data to Campaign Intelligence once, in the background: the catalog, then the customers that contact synchronization sends and every customer with an order in the last 60 months, then the orders, newest first, the last 18 months and then back to 60 months. No older order is sent. "Hold back the import", clicked before the first background run, sends nothing. The Catalog, Customers and Orders cards show each import's state and count while the page is open; an import can be canceled and run again, and one that nothing has moved for an hour shows as stalled.
- A connected store sends its changes to Campaign Intelligence in the background, usually within a minute or two: a product when it is saved or its stock or price changes, with one row per combination, and a deleted product as a removal; a customer when the account is created or changed or the customer orders; an order when it is placed and each time its status changes, it is edited or a product of it is returned. Only a customer that contact synchronization sends to Smaily, or a registered customer with an order in the last 60 months, is sent, with the email address, name, language, country and phone number, and no street address or birthday. Once a night the store sends the list of its products with their stock, so that Campaign Intelligence stops recommending a product whose deletion or stock change did not arrive. While Campaign Intelligence does not accept the connection, the changes wait and are sent once it does. A store without a connection sends nothing.
- Browse tracking and the recommendations block are switched on with "I confirm that my store's cookie banner lets a visitor choose whether to allow Smaily to track their browsing." on the Intelligence tab. It is off after an install and after an upgrade.
- A store with a Smaily account and no Campaign Intelligence connection sees the Campaign Intelligence card, with "Contact Smaily", above the setup link on the Intelligence tab and on step 4 of the Initial setup, and on top of the Dashboard and of the other Settings tabs, where "Dismiss" hides it for 30 days for every administrator. A connected store does not see it.
- The Dashboard shows the Campaign Intelligence connection as it is: not connected, connected with the store's name, currently unreachable, account not active, key rejected or update needed. The Browse tracking card is on once the cookie banner is confirmed and the store is connected, and says what it waits for while it is off. The tile "Catalog items" counts the catalog rows that reached Campaign Intelligence in the last 30 days. The tiles "Queued today" and "Failed", and the failed deliveries that the line at the top names, count what goes to Campaign Intelligence too. The line at the top also names a refused or unreachable Campaign Intelligence, and a nightly product list that has not gone out for three nights.
- The Log and the Dashboard's Recent activity show the Source "Campaign Intelligence" for everything that goes there: products, customers, orders, the imports, the login link, a shopper's personalization choice and a shopper's deletion. An import to Campaign Intelligence shows the number of products, customers or orders it holds, and says in a sentence why it stalled or was cancelled.
- A change of how the shops are run keeps the Campaign Intelligence connection of the selected shop (the default shop with "All shops" selected) and ends every other connection: Campaign Intelligence is told, and their imports stop. The page names the connections before the change and asks for confirmation. After a change to one store, the cookie-banner confirmation is asked again, because the one connection then covers the visitors of every shop.
- Shows the block "Recommended for you" in the storefront: up to four products that Campaign Intelligence picked for the shopper, drawn by the theme's own product list. The merchant places it on a page position in Design > Positions, or a theme template includes it with `{widget name='smailyforprestashop'}`; nothing is placed automatically, and nothing shows when there is nothing to recommend.
- Sends a visitor's product, category and search views and cart changes to Campaign Intelligence through the store's own server, only with the visitor's consent. Consent is the answer of the store's own `window.smailyConnect.consentOverride()` where the theme defines it, else the merchant's confirmation that the store's cookie banner covers Smaily tracking. That confirmation is off on a new store and after an upgrade, so nothing is tracked until the merchant confirms.
- Once the merchant has confirmed the cookie banner, a customer who signs in or registers is sent to Campaign Intelligence and linked to their browsing, whether or not the customer subscribes to the newsletter. A guest's order is linked to the guest's browsing by the same rule, whatever the newsletter box at checkout says. Without the confirmation no customer or guest is linked, and no customer is sent at sign-in. Who is synced to Smaily as a contact still follows the consent mode.
- Credits an order to the Smaily email, or to the recommendations block, that the shopper clicked: the link's values are kept in three first-party cookies for 30 days (the visitor token for 365) and stored with the order. While the shopper has a cart they are kept with the cart too, so an order that a payment provider confirms from its own server is credited as well; that copy is deleted with the order, or a day after the shopper's last visit with the cart, and a privacy export and erasure cover it.
- The Automations tab of Settings has the card "Campaign Intelligence Automations" for a store that is connected to Campaign Intelligence: the automated emails that Campaign Intelligence sends itself, such as a reminder when a product is about to run out. The list comes from Campaign Intelligence each time the tab opens, so a new automation shows without a module update. Each automation has Enabled, Test mode, the Smaily workflow it starts, a cooldown in days, a daily cap and the test addresses, and the card saves on its own with "Save Intelligence Automations". Every automation is off until you enable it, and a newly enabled one is in test mode; Smaily switches real sends on after you confirm, and the card shows the state that Campaign Intelligence holds. An enabled automation needs a workflow. A workflow per language that another Smaily Connect store set up on the same Campaign Intelligence account is kept.
- The administration pages are in English and Estonian: every text of the Dashboard, the Initial setup, Settings, the Log and the upgrade notices has an Estonian translation, and the menu and the pages' tabs follow the employee's language from the first page. The Log shows each failure in the language of the employee who reads it, also one stored while the back office was in the other language, and Test connection says in that language why Smaily could not be reached. The texts that the module shows to shoppers are in Estonian too: the Personalization page, the heading of the recommendations block and the newsletter box of the customer forms.
- The Log words every reason of a skipped or refused row, and every failure of a row to Campaign Intelligence, as a sentence, where it showed a code such as `too_old` or `network_error`. A failure from Campaign Intelligence shows its HTTP status or its code, never Campaign Intelligence's own text.
- The cron line of the Background tasks card has no language prefix in its address, so it is the same line for every employee of a store with several languages.
- Keeps the product RSS feed at its 2.x address with its 2.x parameters (`category_id`, `limit`, `sort_by`, `sort_order`), so feed URLs in existing Smaily templates keep working, with or without a language in the address. The feed URL of the store's default language has no language prefix, so it is the same for every employee of a store with several languages; the Initial setup's Overview shows it. On a store with several languages, Settings > RSS has a Language select (as Magento's builder follows the store view): another language's URL lists the products in that language, and the select opens on the employee's language.
- The merchant guide is at https://smaily.com/connect-presta/, in English and Estonian, as the guides of the other Smaily Connect modules are. "How to add it →" under Settings > Intelligence opens its section on the recommendations block.
- The Initial setup follows the Magento module's: a finished setup opens step 1 marked "Completed", on a summary of the Smaily account (subdomain, API username and status), where Continue moves on and "Edit credentials" shows the form again. In a multishop store run as one store, the Connect, Contacts and Automations steps list the "Shops with their own values" with those values (every contact field the Contacts step offers); each keeps them unless you pick "Use this step's values" for it. The bar "Your shops" has "Change" in the Initial setup too, with the same change and questions as in Settings. Back is a button, the sentence on where to find the API credentials sits in the credentials card, and the Overview also lists the product RSS feed address, with a link to the URL builder, and the User Guide. Settings > Connection names the account in its status: Connected as "<subdomain>".

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
