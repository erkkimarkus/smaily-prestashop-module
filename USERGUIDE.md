# USER GUIDE

This guide will help you set up and configure Smaily Connect for PrestaShop (the module `smailyforprestashop`, version 3.0.0).

## Installation

1. You can get the **Smaily Connect module** from Smaily's [GitHub repository](https://github.com/sendsmaily/smaily-prestashop-module/releases).

2. Download the .zip file for the latest release. Extracted contents of the archive to a `smailyforprestashop` folder.

3. Upload the `smailyforprestashop` folder to your site's `/modules/` directory.

4. Navigate to **Modules -> Module Catalog**. Search for "smaily" and click **Install**.

![Module install](assets/1_module_installation.png)

## Upgrading from version 2.x

1. Install the new version over the old one: **Modules -> Module Manager -> Upload a module**, with the .zip file of the release. Do not uninstall the old version first: uninstalling removes every setting of the module.

2. The upgrade carries your settings over: the Smaily account, contact synchronization, the opt-in and abandoned cart automations with their workflows, the RSS defaults, and the record of the carts that were already reminded. The password of the Smaily account is stored encrypted from now on. Your cron URLs and your RSS feed URLs keep working. The files of the old version that Smaily Connect does not use are deleted from the module's folder; if one cannot be deleted, a notice names it, so that you can delete it by hand.

3. Notices at the top of the Smaily Connect pages tell what the upgrade changed and what it left switched off, with the reason. Close each notice once you have read it; it then goes away for every administrator. Until then the other back-office pages show one line with a link to the Dashboard.

4. If the upgrade was interrupted, the Smaily Connect pages show **The upgrade to Smaily Connect 3.0.0 is not finished** with the button **Finish upgrade**. Smaily Connect stays paused and your previous settings stay in place until the upgrade finishes. It also finishes on its own with the next run of the cron URL.

The Initial setup of an upgraded store is filled in with the carried-over settings and is not required: every page is open at once.

## Initial setup

A new store opens in the **Initial setup**, a guided setup in five steps. **Back** and **Continue** move between the steps, and each step saves when you continue.

1. **Connect**: the Smaily account (see [Connecting module to Smaily account](#connecting-module-to-smaily-account)). A store with several shops first answers how its shops are run. A store without a Smaily account finds the link **Create a Smaily account** here.
2. **Contacts**: who is synced to Smaily, and the first import (see [Customer synchronization](#customer-synchronization)).
3. **Automations**: the store events that start Smaily workflows (see [Automations](#automations)).
4. **Intelligence**: optional.
5. **Overview**: a summary of what you set up, each line with **Edit** to go back to its step, and links to **Settings** and the **Log**.

You can open the Initial setup again at any time under **Customers -> Smaily Connect -> Initial setup**; your settings are kept. Every setting is also in **Settings**. In a store whose shops are run as one store, the Initial setup shows and saves the settings of all shops, whichever shop is selected; a shop's own settings are changed in **Settings**.

## Dashboard

**Customers -> Smaily Connect -> Dashboard** shows at a glance whether everything works.

1. The line at the top names the one thing that needs your attention, with a button that leads to it: an unfinished setup, a missing Smaily account, background tasks that are not running, or deliveries that failed in the last 24 hours.
2. The cards show the state of the **Smaily** connection, **Campaign Intelligence**, **Browse tracking** and **Background tasks**. In a store with several shops, pick a shop in the shop selector at the top of the page to see its connection.
3. The tiles count the **Contact syncs** delivered in the last 30 days, the events **Queued today** that wait to be sent, and the events that **Failed** in the last 24 hours.
4. **Recent activity** lists the latest deliveries; **View full log** opens the Log.

When a newer version of the module is out, the Smaily Connect pages say so, with links to download it and to read what is new. The module asks GitHub for the latest version once a day and sends no store data.

## Background tasks and the cron line

Smaily Connect sends contacts and automation emails in the background. The **Background tasks** card of the Dashboard shows when the tasks last ran and one of three states:

- **Running**: a cron line runs the tasks; its last run is at most 5 minutes old.
- **Visitors only**: no cron line runs them, so the tasks run only when visitors open pages of your store.
- **Not running**: the tasks have not run for 15 minutes or more. Nothing goes to Smaily until they run again.

To set the cron line up:

1. Copy the cron line from the card. While the state is **Visitors only** or **Not running**, the card shows the line; while it is **Running**, click **Show the cron line**. Only an administrator who may change the Smaily Connect settings sees the line and the button.
2. Add the line to the crontab of your server, for example in the cron jobs of your hosting control panel. The line calls one address of your store every minute. A cron service that calls an address for you can call that address instead.

One cron line runs every task. The two cron lines of version 2.x keep working; the one line replaces both.

## Connecting module to Smaily account

1. Open **Customers -> Smaily Connect** in the back office. The **Configure** button of the module in **Modules -> Module Manager** opens the same pages. A new store starts in the **Initial setup**; afterwards the account is under **Settings -> Connection**.

2. Enter your Smaily API credentials - **subdomain, username, and password**.
   You can follow our [API user creation guide](https://smaily.com/help/api/general/create-api-user/). If your Smaily address is `https://demo.sendsmaily.net`, the subdomain is `demo`; pasting the full address also works.

3. Click **Test connection** to check the credentials without saving them.

4. Click **Save Connection**. The **Status** line shows whether the store is connected. A saved password is never shown again: leave the password field empty to keep it.

A store with several languages also chooses how languages are handled: **Single language** (every language the same), **Per-language Smaily accounts**, **One account, per-language workflows**, or **One workflow branching by language**.

With **Per-language Smaily accounts**, the default fallback account is also the account of the store itself. The page asks before you leave **Per-language Smaily accounts** or **One account, per-language workflows**: when you save, the per-language accounts are removed and the per-language workflows are no longer used. The automations keep their workflows through such a change, and when you save another Smaily account. A workflow belongs to the Smaily account it was picked from, so afterwards open **Settings -> Automations**: a workflow that shows as **Workflow #N (not in your Smaily list — kept)** was picked from another account, and you pick a workflow of the account saved now in its place.

### Stores with several shops

A multishop store first answers **How are your shops run?**

- **One store in several versions**: the shops are country or language versions of one business, on one Smaily account. You set the store up once. To give one shop its own Smaily account, its own Contacts settings or its own automations, pick the shop in the shop selector at the top of the page and tick **Use its own Smaily account on this shop**, **Use its own Contacts settings on this shop** or **Use its own automations on this shop**. A shop on another Smaily account than the one saved for all shops always uses its own automations: pick its workflows under **Settings -> Automations**. A shop with its own automations sends what its own Automations tab shows: an automation whose workflow is **-- Not Selected --** there starts nothing for that shop, also when all shops share a workflow for it.
- **Separate stores**: each shop is a business of its own, with its own Smaily account, contacts and automations. Pick a shop in the shop selector at the top of the page and set each shop up on its own.

The shop selector applies to every open back-office tab. When the selected shop changes after a Smaily Connect page was opened, for example in another browser tab, the page saves nothing and asks you to reload it, so that a setting never lands on another shop than the one the page shows.

**Change** next to the answer in **Settings** switches between the two. From one store to separate stores, each shop keeps the settings it uses now, as its own. From separate stores to one store, all shops then use the settings of the shop you have selected, and the other shops' own settings are removed; the page asks you to confirm first.

## Adding newsletter subscription form to your store

Smaily's module can synchronize customers, who have opted in for a newsletter subscription during registration.
We however recommend you place a Newsletter Subscription form on your website, to collect subscribers directly.

1. Navigate to **Design -> Positions** and click on **Transplant a module**.

![Transplant module section](assets/6_transplant_a_module.png)

2. Select **Newsletter subscription** for **Module** and the preferred form location for **Transplant to**. Notice that Smaily Connect uses PrestaShop's built-in Newsletter Subscription form. When the **Welcome** automation is on (**Settings -> Automations**), users who subscribe trigger its workflow.
3. Click **Save** and the Newsletter subscription form should be on your store at the specified location!

## Customer synchronization

Contact synchronization is set under **Settings -> Contacts**.

1. Turn on **Sync contacts to Smaily**.
2. Choose who is synced. This is a lawful-basis choice: pick the option that matches how you have asked your customers for permission.
   - **Subscribers only (consent)**: only customers who opted into the newsletter are synced. Unsubscribes in Smaily are mirrored back to PrestaShop.
   - **All customers (legitimate interest)**: every registered customer is synced.
   - **Checkout opt-in only**: nobody is synced automatically, only shoppers who tick the newsletter checkbox at checkout.
3. **Include guests** also syncs customers who checked out as guests. A newsletter subscriber without an account, who signed up in the newsletter block, is synced whatever this setting says, except in **Checkout opt-in only**.
4. Under **Extra fields to sync with each contact**, pick the fields that personalize your newsletters: first name, last name, date of birth, website. Empty values are never sent.
5. Click **Save Contacts**. When the save changes who is synced, the page tells you what will be sent from now on and asks you to confirm.
6. Under **Initial contact import**, **Start import** sends the contacts that your Contacts settings sync to Smaily once, in the background. New and changed contacts are synced on their own afterwards.

## Automations

**Settings -> Automations** maps store events to Smaily automation workflows. The Initial setup has the same step.

1. Create the workflows in Smaily first, each with the _form submitted_ trigger. You can follow our [creating automation workflows tutorial](https://smaily.com/help/user-manual/automations/automation-workflows/). The lists on the page come live from your Smaily account; click **Refresh workflows** after you have added one.

2. Each automation has an **Enabled** switch and a **Smaily Workflow**:
   - **Welcome** fires when a shopper subscribes to the newsletter in your store.
   - **First order** fires on a customer's first purchase.
   - **Abandoned cart** fires when a cart is left behind. **Wait (minutes)** is how long a cart must be inactive before it counts as abandoned, from 10 to 1435 minutes. A cart older than 24 hours is never reminded.

3. Every abandoned cart reminder carries the customer's first and last name and the name, description, SKU, price, base price and quantity of up to 10 products in the cart, so the template can send personalized reminder emails. If another module also sends abandoned cart emails, switch those emails off there, so a shopper does not get two reminders for one cart.

4. Click **Save Automations**. An automation that is switched on without a workflow sends nothing; the **Log** shows it as skipped.

### Sending a discount code

Each of the three automations can send the shopper a discount code of their own with its email.

1. Make the discount in PrestaShop first: **Catalog -> Discounts -> Add new cart rule**. Set the discount (a percentage, an amount or free shipping) and its conditions as for any cart rule. Leave **Status** off, or give the rule a code that you do not share and leave **Highlight** off: PrestaShop applies an enabled cart rule without a code to every cart, and anyone who knows the code of an enabled rule can use it. The codes that Smaily Connect makes work either way.
2. In the automation's card, switch on **Send a discount code**, pick the rule under **Cart price rule** and set **Valid for (days)**, from 1 to 365. Click **Refresh workflows** when a rule that you just made is missing from the list. A rule that has ended, that has no uses left (**Total available** is 0), that is restricted to other shops, or that is limited to a single customer is not listed: a voucher that PrestaShop made for one customer, for a refund or as the rest of a voucher that was spent in part, is that customer's own.
3. In the Smaily template, use the fields of the automation: `welcome_coupon_code` and `welcome_coupon_expires_at`, `first_order_coupon_code` and `first_order_coupon_expires_at`, or `abandoned_cart_coupon_code` and `abandoned_cart_coupon_expires_at`. The first holds the code, the second its last valid day as `YYYY-MM-DD` in the shop's time.

Every email gets a new code that works once, with the discount and the conditions of your cart rule. It is valid until the end of the day that many days after the email, and never after the end date of the cart rule itself. It is also never valid before the cart rule's own start date: you can pick a rule that starts later, and a code that was mailed earlier works from the rule's start date on. The codes do not use up the rule's **Total available**, and they work whether the rule's **Status** is on or off. A customer with an account gets a code that only that customer can use after signing in; the customer also finds it under **Your account -> Vouchers**. A shopper without an account, for example a newsletter subscriber, gets a code that works for whoever enters it, once.

The codes show in **Catalog -> Discounts** with a name that starts with "Smaily Connect". A code that nobody used is removed 30 days after its last valid day; a used code stays with its order. When the cart rule was deleted, has ended, has no uses left, starts only after the code would end, is limited to a single customer or is not for the shop, the email still goes, without a code, and the **Details** of its row in the **Log** say so. Uninstalling the module leaves the codes in place.

A store that handles its languages with **One account, per-language workflows** or **Per-language Smaily accounts** also sees **Per-language workflows**: pick a workflow for each language, and a **Default fallback** for contacts whose language has none. With per-language Smaily accounts, each language lists the workflows of its own account, and a contact whose language has no workflow gets the **Default fallback**, else the workflow of the default fallback account's language; where none of them is picked, no email is sent and the **Log** shows the event as skipped.

After an upgrade from version 2.x, a store whose opt-in automation had no workflow chosen keeps working as before: the Welcome automation shows **Smaily's opt-in trigger (from 2.x)**. It stays until you pick another workflow and save.

## Log

**Customers -> Smaily Connect -> Log** lists everything the module sent to Smaily: contact syncs, automation triggers and contact imports, newest first. If something seems missing in Smaily, look here first.

1. Each row shows its **Status**: Pending, Sending, Sent, Failed, Withdrawn (a cart reminder that was called back because the shopper ordered) or Skipped (nothing was sent; **Last Error** says why). A contact import shows Queued, Running, Done, Failed or Cancelled.
2. Filter the list under the column headings by source, type, entity, status, last error and the days a row was created or updated, then click **Search**. In a store with several shops, the list shows the shops selected in the header.
3. A failed row is retried on its own for up to 5 attempts. When rows failed in the last 24 hours, a banner above the list counts them, and **Show failed (24h)** lists the failed rows.
4. **Details** shows the attempts, what happens next, the last error, the data that was sent and Smaily's answer. The contact's data is shown in full; passwords and API keys are never stored or shown.
5. After you have fixed the cause of a failure, for example new API credentials, click **Send again** on the row, or tick the failed rows and click **Retry**. **Select all** retries every failed row that the filter lists. A row is left alone when sending it again would not be safe, for example when a later email of the same kind already reached the contact, and the answer says how many rows were skipped.

Sent and skipped rows are kept for 30 days, failed rows for 90 days. Contact imports stay in the list.

## Product recommendations in your store

With Campaign Intelligence connected, your store can show each shopper the block **Recommended for you**: up to four products that Campaign Intelligence picked for that shopper, the same ones as in the shopper's emails. The products are drawn as your theme draws its own product lists, with your store's own names, pictures and prices.

You decide where the block shows. The module puts it nowhere by itself.

1. **On a page position.** Open **Design -> Positions** and click **Transplant a module** (PrestaShop 8) or **Hook a module** (PrestaShop 9). Pick **Smaily Connect** as the module and a position, for example `displayHome` for the home page or `displayFooterProduct` for the product page, and save.
2. **In a theme template.** A theme developer writes `{widget name='smailyforprestashop'}` where the block belongs.

The block shows only to a shopper whom the store knows and who has recommendations: a logged-in customer, or a visitor who came from one of your Smaily emails or shopped with you before. For everyone else, and whenever Campaign Intelligence has no answer, nothing shows at all: no heading and no empty frame. A customer who switched personalization off on their account page never sees the block. The block also needs the visitor's consent, as browse tracking does (below).

A theme can restyle the block by overriding the module's template `views/templates/front/recommendations.tpl`.

## Browse tracking and cookies

With Campaign Intelligence connected, the store can tell it which products, categories and searches a visitor looks at and what goes into the cart, so that the recommendations fit the visitor. The events go through your own store's server; no key or password of your store is ever in a page.

**Browse tracking and the recommendations block are off until you confirm that your store's cookie banner covers them**, with the switch **My store's cookie banner covers Smaily tracking** in **Settings -> Intelligence**. PrestaShop has no cookie-consent setting that a module can read, so the module cannot see what a visitor answered to your banner. When you confirm, the module treats every visitor of that shop as having agreed to Smaily tracking and recommendations: you answer for the banner, so make sure that it asks for this. A new store and a store upgraded from version 2.x start with this off.

The confirmation switches on, for that shop:

- browse tracking for every visitor: the product, category and search views and the cart changes;
- the recommendations block;
- the link between a customer and their browsing. When a customer signs in or registers, the store sends that customer to Campaign Intelligence and gives the browser the cookie `smaily_rec_uid`, so that what the customer looked at counts for their recommendations. This applies to every customer who signs in, also to one who does not subscribe to the newsletter. A guest gets the cookie at checkout only with the newsletter box ticked.

Who is synced to Smaily as a contact does not change with this switch: that follows the consent mode in **Settings -> Contacts**. A customer who switched personalization off on their account page is not linked.

If your store's developer can read the banner's answer, they can give the module each visitor's own choice: define the function `window.smailyConnect.consentOverride()` in the theme, returning `true` only for a visitor who agreed. Where it is defined, its answer decides, whatever the setting says. When the answer changes on an open page, fire the event `smaily:consent-changed` on `document`.

What a visitor's browser stores:

| Cookie | What it holds | When it is set | Kept for |
|---|---|---|---|
| `smaily_anon_sid` | A random number for this browser's visits | Only with consent, by the tracker | 30 days |
| `smaily_rec_id`, `smaily_rec_ctx` | Which recommendation in a Smaily email, or in the block, the visitor clicked | When the visitor lands from such a link | 30 days |
| `smaily_rec_uid` | A token that stands for the visitor, so that the store can ask for their recommendations | When the visitor lands from a Smaily email link. Also when a customer signs in or registers, or a guest ticks the newsletter box at checkout, but only once you have confirmed the cookie banner | 365 days |

The cookies of an email click are set without the consent switch, as in every Smaily store connector: they record that the visitor came from your own email, so that the order can be credited to it. Without consent nothing else is stored and no browsing is sent.

While a visitor has a cart, the store also keeps the values of these cookies with that cart, by the same rules. An order that a payment provider confirms from its own server, without the visitor's browser, is then credited like any other order. The store deletes its copy when the order exists, or a day after the visitor's last visit with that cart.

## Privacy requests

A customer can ask for a copy of their personal data, or to have it erased. Smaily Connect answers both for the data that the module itself keeps, and for the customer's data in Campaign Intelligence.

**With PrestaShop's Official GDPR compliance module** (`psgdpr`) the module needs no setup: the export and the deletion of that module include Smaily Connect. A customer who downloads their data on the store's **GDPR - Personal data** page gets the module's part in the same file, and "Remove data" in the GDPR module's Personal data management erases it.

**Without that module**, the **Log** page has the form **Privacy request** under the list, for an employee who may change the Log:

1. Type the customer's **Email address**.
2. **Export** saves a file with what the module holds about that address, and nothing of anybody else.
3. **Erase** asks for confirmation and then erases it. It cannot be undone.

The form handles the module's data only. The customer account, the orders and the Smaily contact stay as they are: delete or anonymize the account in PrestaShop, and the contact in Smaily. A customer whose account stays in the store is synced again with their next change.

**What an export lists:** the rows of the Log that were queued for the address (type, status and time, never the message), its abandoned-cart reminders, the email click that led to an order or that is kept with a cart, the discount codes that the module issued to the customer with whether each was used, the personalization preference, and what each Campaign Intelligence connection holds. When Campaign Intelligence does not answer, the export says "Not available" for that connection; run the export again later.

**What an erasure does:**

- Messages that are still waiting to be sent are deleted.
- Rows of the Log that were already sent, skipped or failed stay as a record, without the contact: their Entity reads `[erased]`, the data that was sent and Smaily's answer are removed, and such a row cannot be sent again.
- The address is taken off the customer's cart reminders, and a cart that was about to be reminded gets no reminder.
- The email click and the visitor token that are kept with the customer's carts are deleted. An order keeps the email click that led to it, without the visitor token.
- The discount codes that the module issued to the customer and that no order used are removed. A used code stays with its order, and so does the remaining credit of a code that was spent in part. PrestaShop's GDPR module itself deletes every voucher of a customer account that it deletes.
- Campaign Intelligence is asked to delete the customer. The request waits in the Log as `engine.customer_delete` and is retried until Campaign Intelligence answers.
- The customer's choice about personalization is kept, without the address, so that an opt-out still holds if the same address orders again.
- Smaily contacts are not deleted. Delete the contact in your Smaily account.

## Installation report

When a Smaily account is connected, Smaily Connect sends Smaily an installation report when you save the setup or the Settings, once a day, and when you remove a connection or uninstall or reset the module, authenticated with the connected Smaily account's API credentials, which Smaily only checks and never stores: the shop addresses, the PrestaShop, PHP and module versions, the connected Smaily accounts, the switched-on features, whether each connection works (error codes only) and the time of the last successful sync — never customer, subscriber or order data. A store without a connected Smaily account sends nothing. The report has no setting; a report that Smaily does not take in changes nothing in your store and leaves one line in PrestaShop's log (**Advanced Parameters -> Logs**).

## Personalization

In a store that is connected to Campaign Intelligence, a signed-in customer finds **Personalization** in their account. The page **Personalized Recommendations** has one checkbox, **Allow personalized recommendations**, and the button **Save Preference**. A customer who unticks the box is left out of personalized recommendations; the newsletters then show generic content. The customer can tick it again at any time, and the last choice is the one that counts.

A customer who unsubscribes from the newsletter is also left out of personalization, and is included again after subscribing again, unless they had unticked the box themselves. The page is in English and Estonian. A store without a Campaign Intelligence connection has no such link and no such page.

## RSS

Smaily's module provides an RSS feed of the store's products. **Settings -> RSS** builds the feed URL:

1. **Category ID (optional)**: only products of that category are in the feed. Category IDs are shown in **Catalog -> Categories**.
2. **Number of products**: the maximum number of products in the feed, between 1 and 250.
3. **Sort by**: date created, date updated, product name, price or product ID.
4. **Sort order**: descending or ascending.

Click **Copy** next to **Feed URL** and paste the URL into the RSS block of the Smaily template editor to show products in your newsletter. For this, you can follow our [RSS feed manual](https://smaily.com/help/user-manual/templates/adding-rss-feed-to-template/). **Save** keeps the chosen values as the builder's defaults. Feed URLs that you copied from an earlier version of the module keep working.
