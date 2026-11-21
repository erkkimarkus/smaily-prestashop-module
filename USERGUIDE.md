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

2. The upgrade carries your settings over: the Smaily account, contact synchronization, the opt-in and abandoned cart automations with their workflows, the RSS defaults, and the record of the carts that were already reminded. The password of the Smaily account is stored encrypted from now on. Your cron URLs and your RSS feed URLs keep working.

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

## RSS

Smaily's module provides an RSS feed of the store's products. **Settings -> RSS** builds the feed URL:

1. **Category ID (optional)**: only products of that category are in the feed. Category IDs are shown in **Catalog -> Categories**.
2. **Number of products**: the maximum number of products in the feed, between 1 and 250.
3. **Sort by**: date created, date updated, product name, price or product ID.
4. **Sort order**: descending or ascending.

Click **Copy** next to **Feed URL** and paste the URL into the RSS block of the Smaily template editor to show products in your newsletter. For this, you can follow our [RSS feed manual](https://smaily.com/help/user-manual/templates/adding-rss-feed-to-template/). **Save** keeps the chosen values as the builder's defaults. Feed URLs that you copied from an earlier version of the module keep working.
