# USER GUIDE

This guide will help you set up and configure Smaily for PrestaShop module.

## Installation

1. You can get the **Smaily for PrestaShop module** from Smaily's [GitHub repository](https://github.com/sendsmaily/smaily-prestashop-module/releases).

2. Download the .zip file for the latest release. Extracted contents of the archive to a `smailyforprestashop` folder.

3. Upload the `smailyforprestashop` folder to your site's `/modules/` directory.

4. Navigate to **Modules -> Module Catalog**. Search for "smaily" and click **Install**.

![Module install](assets/1_module_installation.png)

## Connecting module to Smaily account

1. Open **Customers -> Smaily Connect** in the back office. The **Configure** button of the module in **Modules -> Module Manager** opens the same pages. A new store starts in the **Initial setup**; afterwards the account is under **Settings -> Connection**.

2. Enter your Smaily API credentials - **subdomain, username, and password**.
   You can follow our [API user creation guide](https://smaily.com/help/api/general/create-api-user/). If your Smaily address is `https://demo.sendsmaily.net`, the subdomain is `demo`; pasting the full address also works.

3. Click **Test connection** to check the credentials without saving them.

4. Click **Save Connection**. The **Status** line shows whether the store is connected. A saved password is never shown again: leave the password field empty to keep it.

A store with several languages also chooses how languages are handled: **Single language** (every language the same), **Per-language Smaily accounts**, **One account, per-language workflows**, or **One workflow branching by language**.

### Stores with several shops

A multishop store first answers **How are your shops run?**

- **One store in several versions**: the shops are country or language versions of one business, on one Smaily account. You set the store up once. To give one shop its own Smaily account, its own Contacts settings or its own automations, pick the shop in the shop selector at the top of the page and tick **Use its own Smaily account on this shop**, **Use its own Contacts settings on this shop** or **Use its own automations on this shop**. A shop on another Smaily account than the one saved for all shops always uses its own automations: pick its workflows under **Settings -> Automations**.
- **Separate stores**: each shop is a business of its own, with its own Smaily account, contacts and automations. Pick a shop in the shop selector at the top of the page and set each shop up on its own.

**Change** next to the answer in **Settings** switches between the two. From one store to separate stores, each shop keeps the settings it uses now, as its own. From separate stores to one store, all shops then use the settings of the shop you have selected, and the other shops' own settings are removed; the page asks you to confirm first.

## Adding newsletter subscription form to your store

Smaily's module can synchronize customers, who have opted in for a newsletter subscription during registration.
We however recommend you place a Newsletter Subscription form on your website, to collect subscribers directly.

1. Navigate to **Design -> Positions** and click on **Transplant a module**.

![Transplant module section](assets/6_transplant_a_module.png)

2. Select **Newsletter subscription** for **Module** and the preferred form location for **Transplant to**. Notice that Smaily uses the built-in Newsletter Subscription form and extends it's functionality. When the **Welcome** automation is on (**Settings -> Automations**), users who subscribe trigger its workflow.
3. Click **Save** and the Newsletter subscription form should be on your store at the specified location!

## Customer synchronization

Contact synchronization is set under **Settings -> Contacts**.

1. Turn on **Sync contacts to Smaily**.
2. Choose who is synced. This is a lawful-basis choice: pick the option that matches how you have asked your customers for permission.
   - **Subscribers only (consent)**: only customers who opted into the newsletter are synced. Unsubscribes in Smaily are mirrored back to PrestaShop.
   - **All customers (legitimate interest)**: every registered customer is synced.
   - **Checkout opt-in only**: nobody is synced automatically, only shoppers who tick the newsletter checkbox at checkout.
3. **Include guests** also syncs newsletter subscribers without an account and customers who checked out as guests.
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

A store that handles its languages with **One account, per-language workflows** or **Per-language Smaily accounts** also sees **Per-language workflows**: pick a workflow for each language, and a **Default fallback** for contacts whose language has none. With per-language Smaily accounts, each language lists the workflows of its own account.

After an upgrade from version 2.x, a store whose opt-in automation had no workflow chosen keeps working as before: the Welcome automation shows **Smaily's opt-in trigger (from 2.x)**. It stays until you pick another workflow and save.

## Log

**Customers -> Smaily Connect -> Log** lists everything the module sent to Smaily: contact syncs, automation triggers and contact imports, newest first. If something seems missing in Smaily, look here first.

1. Each row shows its **Status**: Pending, Sending, Sent, Failed, Withdrawn (a cart reminder that was called back because the shopper ordered) or Skipped (nothing was sent; **Last Error** says why). A contact import shows Queued, Running, Done, Failed or Cancelled.
2. Filter the list under the column headings by source, type, entity, status, last error and the days a row was created or updated, then click **Search**. In a store with several shops, the list shows the shops selected in the header.
3. A failed row is retried on its own for up to 5 attempts. When rows failed in the last 24 hours, a banner above the list counts them, and **Show failed (24h)** lists the failed rows.
4. **Details** shows the attempts, what happens next, the last error, the data that was sent and Smaily's answer. The contact's data is shown in full; passwords and API keys are never stored or shown.
5. After you have fixed the cause of a failure, for example new API credentials, click **Send again** on the row, or tick the failed rows and click **Retry**. **Select all** retries every failed row that the filter lists. A row is left alone when sending it again would not be safe, for example when a later email of the same kind already reached the contact, and the answer says how many rows were skipped.

Sent and skipped rows are kept for 30 days, failed rows for 90 days.

## RSS

Smaily's module provides an RSS feed of the store's products. **Settings -> RSS** builds the feed URL:

1. **Category ID (optional)**: only products of that category are in the feed. Category IDs are shown in **Catalog -> Categories**.
2. **Number of products**: the maximum number of products in the feed, between 1 and 250.
3. **Sort by**: date created, date updated, product name, price or product ID.
4. **Sort order**: descending or ascending.

Click **Copy** next to **Feed URL** and paste the URL into the RSS block of the Smaily template editor to show products in your newsletter. For this, you can follow our [RSS feed manual](https://smaily.com/help/user-manual/templates/adding-rss-feed-to-template/). **Save** keeps the chosen values as the builder's defaults. Feed URLs that you copied from an earlier version of the module keep working.
