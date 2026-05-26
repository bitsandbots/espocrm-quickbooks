# QuickBooks Online Integration

Bidirectional sync between EspoCRM and QuickBooks Online. EspoCRM is the operational source of truth for CRM data; QuickBooks is the accounting source of truth for payments.

## What it syncs

| EspoCRM | QuickBooks | Direction |
|---------|-----------|-----------|
| Account | Customer | Push on save, pull nightly |
| Contact | Customer | Push on save, pull nightly |
| Invoice | Invoice | Push on save |
| QB Payment | Invoice status → Paid | Pull nightly |

Conflict resolution uses last-modified-wins: whichever record was modified more recently wins during the nightly reconciliation pass.

## Requirements

- EspoCRM 7.x or later
- A QuickBooks Online company (any paid tier)
- A developer app at [developer.intuit.com](https://developer.intuit.com) (free)
- PHP `curl` extension enabled

## Setup

### 1. Create a QuickBooks developer app

1. Sign in at [developer.intuit.com](https://developer.intuit.com) and create a new app.
2. Select **QuickBooks Online and Payments**.
3. Under **Keys & OAuth**, copy your **Client ID** and **Client Secret**.
4. Add a redirect URI:
   ```
   https://your-espocrm-site.com/?entryPoint=QuickBooksOauthCallback
   ```
   Replace the domain with your actual EspoCRM `siteUrl`.

### 2. Find your default QB Item ID

QuickBooks requires every invoice line item to reference a Product/Service record (called an Item). Look up the ID of the item you want to use as the default (e.g. "Services"):

- In QuickBooks: **Sales → Products and Services**, click the item, note the numeric ID in the URL (`/item/123`).
- Via the QB API: `GET /v3/company/{realmId}/query?query=SELECT * FROM Item`.

### 3. Activate the module

```bash
php console.php rebuild
```

This registers the Invoice entity, adds QB fields to Account and Contact, and loads module metadata.

### 4. Configure the integration

1. In EspoCRM: **Admin → Integrations → QuickBooks**.
2. Enter your **Client ID** and **Client Secret**.
3. Enter your **Default QB Item ID** (from step 2).
4. Click **Save**.
5. Click **Connect to QuickBooks** — a popup will open to authorize with Intuit.
6. After authorizing, the popup closes and the form shows "Connected" with your Realm ID.

### 5. Schedule the sync jobs

In **Admin → Scheduled Jobs**, enable and set a schedule for both jobs:

| Job name | Recommended schedule | Purpose |
|----------|---------------------|---------|
| QuickBooks: Sync from QuickBooks | Daily at 2:00 AM | Pulls QB Customer updates and Payment records |
| QuickBooks: Reconcile | Daily at 2:15 AM | Pushes EspoCRM Accounts and Invoices modified since last sync |

Run Reconcile after Sync so that any conflicts pulled from QB are already applied before the outbound push.

### 6. Rebuild again after connecting

```bash
php console.php rebuild
```

## Data model

### New entity: Invoice

| Field | Type | Notes |
|-------|------|-------|
| name | varchar | Invoice number or reference |
| status | enum | Draft · Sent · Paid · Overdue · Voided |
| amount | currency | Total; used as fallback if no line items |
| dueDate | date | |
| lineItems | jsonArray | Array of `{description, quantity, unitPrice, qbItemId}` |
| account | link → Account | Required for QB sync (determines QB Customer) |
| contact | link → Contact | Optional |
| qbInvoiceId | varchar | Set automatically after first sync |
| qbInvoiceSyncToken | varchar | QB sparse-update token; set automatically |
| qbPaymentId | varchar | Set when QB payment received |
| qbPaymentDate | date | |
| qbSyncedAt | datetime | Timestamp of last successful push |

### Fields added to Account and Contact

| Field | Notes |
|-------|-------|
| qbCustomerId | QB Customer ID; set after first sync |
| qbCustomerSyncToken | QB sparse-update token |
| qbSyncedAt | Timestamp of last successful push |

A **QuickBooks** panel appears on the Account and Contact detail views showing the Customer ID, last sync time, and a direct link into the QB app.

## How sync works

### Push (on save)

An `afterSave` hook fires whenever an Account, Contact, or Invoice is saved. It calls `QuickBooksService::upsertCustomer()` or `upsertInvoice()`, which posts to the QB API. Internal saves triggered by sync (e.g. writing back the QB ID) set `skipQuickBooksSync = true` to prevent loops.

Invoice sync requires the linked Account to already have a `qbCustomerId`. If the Account has not yet been synced, save the Account first (or let the nightly job handle it).

Invoices with status **Voided** are never pushed.

### Pull (nightly)

`SyncFromQuickBooks` queries QB for Customers and Payments updated since `lastSyncAt` (or the past 7 days on first run). Matching Accounts are updated; Invoices linked to received Payments are set to **Paid**.

### Reconciliation (nightly)

`ReconcileQuickBooks` scans Accounts and Invoices modified since their last `qbSyncedAt` and pushes any that are stale. Processes records in batches of 50.

## Line items and QB Items

Each entry in `lineItems` can include a `qbItemId` to pin it to a specific QuickBooks Product/Service. If `qbItemId` is omitted, the **Default QB Item ID** configured in the integration settings is used. If neither is set, the QB API will reject the invoice.

```json
[
  { "description": "Consulting — June", "quantity": 8, "unitPrice": 150.00, "qbItemId": "42" },
  { "description": "Expenses", "quantity": 1, "unitPrice": 75.00 }
]
```

The second line above will use the Default QB Item ID.

## OAuth security

The authorization flow is protected by a server-issued state token:

1. Clicking **Connect** calls `POST /api/v1/QuickBooksIntegration/initOAuth` (admin only), which generates a cryptographically random 32-character hex token, stores it in the Integration record, and returns it.
2. The frontend embeds that token in the QB authorization URL as `state=…`.
3. QuickBooks redirects back to `?entryPoint=QuickBooksOauthCallback` with the same `state`.
4. The callback rejects the request if `state` is absent, does not match the stored token, or the Integration has no stored token at all.
5. On success, the stored token is cleared.

## Troubleshooting

**"Cannot sync invoice — linked Account has no QB Customer ID"**
Save the Account record first to push it to QB, then save the Invoice again.

**Invoice sync rejected by QB API**
Check that your Default QB Item ID is set and references a valid, active Product/Service in your QB company. Inactive or deleted items cause a 400 error.

**"QuickBooks integration is not enabled"**
The integration is disabled in EspoCRM or the OAuth connection was lost. Go to Admin → Integrations → QuickBooks and reconnect.

**Token refresh failures**
QuickBooks refresh tokens expire after 100 days of inactivity. Reconnect via Admin → Integrations → QuickBooks if you see token errors in the application log.

**Sync job not running**
Confirm the scheduled jobs are enabled and that the EspoCRM cron is running:
```bash
* * * * * /usr/bin/php /path/to/espocrm/cron.php
```

## File map

```
custom/Espo/Modules/QuickBooks/
├── Controllers/
│   └── QuickBooksIntegration.php   POST /api/v1/QuickBooksIntegration/initOAuth
├── Entities/
│   └── Invoice.php
├── EntryPoints/
│   └── QuickBooksOauthCallback.php  ?entryPoint=QuickBooksOauthCallback
├── Hooks/
│   ├── Account/Sync.php             afterSave → upsertCustomer
│   ├── Contact/Sync.php             afterSave → upsertCustomer
│   └── Invoice/Sync.php             afterSave → upsertInvoice
├── Jobs/
│   ├── SyncFromQuickBooks.php       nightly pull
│   └── ReconcileQuickBooks.php      nightly push
├── Services/
│   └── QuickBooksService.php        all QB API calls and field mapping
├── Tools/
│   └── ConflictResolver.php         last-modified-wins logic
└── Resources/
    ├── metadata/
    │   ├── app/scheduledJobs.json
    │   ├── clientDefs/{Account,Contact,Invoice}.json
    │   ├── entityDefs/{Account,Contact,Invoice}.json
    │   ├── integrations/QuickBooks.json
    │   └── scopes/Invoice.json
    ├── layouts/Invoice/{detail,edit,list,filters}.json
    └── i18n/en_US/{Integration,Invoice}.json

client/custom/src/views/
├── admin/integrations/quick-books.js   connect button and OAuth popup
└── panels/quick-books-status.js        QB status panel on Account/Contact

tests/unit/Espo/Modules/QuickBooks/
├── ConflictResolverTest.php
├── QuickBooksIntegrationControllerTest.php
├── QuickBooksOauthCallbackTest.php
└── QuickBooksServiceFieldMappingTest.php
```
