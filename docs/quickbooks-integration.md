# QuickBooks Integration Reference

## Integration Entity Fields

Stored on the `Integration` entity with ID `QuickBooks`.

| Field | Type | Purpose |
|-------|------|---------|
| `clientId` | varchar | Intuit developer app Client ID |
| `clientSecret` | password | Intuit developer app Client Secret (encrypted at rest) |
| `realmId` | varchar | QB Company ID — set automatically by OAuth |
| `accessToken` | text | Current OAuth access token (1-hour lifetime) |
| `refreshToken` | text | Long-lived refresh token (100-day lifetime) |
| `accessTokenExpiresAt` | datetime | Token expiry — refreshed 30 seconds before expiry |
| `connectedAt` | datetime | Timestamp of last successful OAuth authorization |
| `lastSyncAt` | datetime | Timestamp when `SyncFromQuickBooks` last completed |
| `lastSyncError` | text | Most recent job error string (null on clean run) |
| `defaultItemId` | varchar | Fallback QB Item ID for invoices without line items |
| `oauthState` | varchar | Ephemeral CSRF token — cleared after use |

---

## Sync Matrix

| EspoCRM Entity | QB Entity | Direction | Trigger |
|---------------|-----------|-----------|---------|
| Account | Customer | EspoCRM → QB | `afterSave` hook (real-time) |
| Account | Customer | QB → EspoCRM | `SyncFromQuickBooks` job (nightly) |
| Contact | Customer | EspoCRM → QB | `afterSave` hook (real-time) |
| Invoice | Invoice | EspoCRM → QB | `afterSave` hook (real-time) |
| Invoice (status=Voided) | Invoice (void) | EspoCRM → QB | `afterSave` hook when status transitions to Voided |
| QB Payment | Invoice.status=Paid | QB → EspoCRM | `SyncFromQuickBooks` job (nightly) |

---

## QB Fields Added to EspoCRM Entities

### Account

| Field | Type | Description |
|-------|------|-------------|
| `qbCustomerId` | varchar(64) | QB `Customer.Id` |
| `qbCustomerSyncToken` | varchar(32) | QB optimistic concurrency token |
| `qbSyncedAt` | datetime | Timestamp of last successful sync to QB |

### Contact

| Field | Type | Description |
|-------|------|-------------|
| `qbCustomerId` | varchar(64) | QB `Customer.Id` |
| `qbCustomerSyncToken` | varchar(32) | QB optimistic concurrency token |
| `qbSyncedAt` | datetime | Timestamp of last successful sync to QB |

### Invoice

| Field | Type | Description |
|-------|------|-------------|
| `qbInvoiceId` | varchar(64) | QB `Invoice.Id` |
| `qbInvoiceSyncToken` | varchar(32) | QB optimistic concurrency token |
| `qbSyncedAt` | datetime | Timestamp of last successful sync to QB |
| `qbPaymentId` | varchar(64) | QB `Payment.Id` (set when payment pulled from QB) |
| `qbPaymentDate` | date | QB payment transaction date |

---

## QuickBooksService API

All methods are on `Espo\Modules\QuickBooks\Services\QuickBooksService`.

### `upsertCustomer(string $entityType, Entity $entity): void`

Creates or sparse-updates a QB Customer from an EspoCRM Account or Contact.

- If `qbCustomerId` and `qbCustomerSyncToken` are set on the entity, sends a sparse update (includes `Id`, `SyncToken`, `sparse: true`). Otherwise creates new.
- Saves `qbCustomerId`, `qbCustomerSyncToken`, and `qbSyncedAt` back to the entity using `['skipQuickBooksSync' => true, 'silent' => true]`.
- Throws `Error` if integration is disabled.

### `upsertInvoice(Entity $invoice): void`

Creates or sparse-updates a QB Invoice.

- Requires the invoice's linked Account to have a `qbCustomerId`. Throws `Error` if missing ("sync the Account first").
- Builds line items from `invoice.lineItems` JSON array. Falls back to a single line using `invoice.amount` if no line items.
- Per-item: uses `item.qbItemId` if set, otherwise falls back to Integration `defaultItemId`. No `ItemRef` sent if neither is available.
- Saves `qbInvoiceId`, `qbInvoiceSyncToken`, `qbSyncedAt` back to invoice.

### `voidInvoice(Entity $invoice): void`

Voids a QB Invoice using `POST /invoice?operation=void&minorversion=65`. No-ops silently if `qbInvoiceId` is not set on the invoice (never synced).

### `pullPaymentsSince(string $sinceDate): void`

Queries QB: `SELECT * FROM Payment WHERE TxnDate >= '$sinceDate'`. For each payment:

1. Traverses `Line[0].LinkedTxn` for entries with `TxnType = Invoice`.
2. Finds EspoCRM Invoice where `qbInvoiceId` matches `TxnId`.
3. Sets `status = Paid`, `qbPaymentId`, `qbPaymentDate`.

### `pullCustomersSince(string $sinceDate): void`

Queries QB: `SELECT * FROM Customer WHERE MetaData.LastUpdatedTime >= '$sinceDate'`. For each customer:

1. Finds EspoCRM Account where `qbCustomerId` matches QB `Customer.Id`.
2. Compares QB `MetaData.LastUpdatedTime` with EspoCRM `qbSyncedAt`.
3. Skips if QB timestamp ≤ `qbSyncedAt` (EspoCRM already has this state or is newer).
4. Applies `name`, `emailAddress`, `phoneNumber`; updates `qbCustomerSyncToken` and `qbSyncedAt`.

---

## Field Mapping

### Account → QB Customer

| EspoCRM Field | QB Field |
|--------------|---------|
| `name` | `CompanyName`, `DisplayName` |
| `emailAddress` | `PrimaryEmailAddr.Address` |
| `phoneNumber` | `PrimaryPhone.FreeFormNumber` |
| `website` | `WebAddr.URI` |
| `billingAddressStreet` | `BillAddr.Line1` |
| `billingAddressCity` | `BillAddr.City` |
| `billingAddressState` | `BillAddr.CountrySubDivisionCode` |
| `billingAddressPostalCode` | `BillAddr.PostalCode` |
| `billingAddressCountry` | `BillAddr.Country` |

### Contact → QB Customer

| EspoCRM Field | QB Field |
|--------------|---------|
| `firstName` | `GivenName` |
| `lastName` | `FamilyName` |
| `firstName` + `lastName` | `DisplayName` (space-joined; falls back to `name`) |
| `emailAddress` | `PrimaryEmailAddr.Address` |
| `phoneNumber` | `PrimaryPhone.FreeFormNumber` |

### QB Customer → EspoCRM Account (Pull)

| QB Field | EspoCRM Field |
|---------|--------------|
| `CompanyName` or `DisplayName` | `name` |
| `PrimaryEmailAddr.Address` | `emailAddress` |
| `PrimaryPhone.FreeFormNumber` | `phoneNumber` |
| `SyncToken` | `qbCustomerSyncToken` |

---

## QB API Endpoints Used

Base URL: `https://quickbooks.api.intuit.com/v3/company/{realmId}`

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/customer` | Create or sparse-update Customer |
| `POST` | `/invoice` | Create or sparse-update Invoice |
| `POST` | `/invoice?operation=void&minorversion=65` | Void Invoice |
| `GET` | `/query?query=SELECT%20*%20FROM%20Payment%20WHERE%20...` | Pull payments by date |
| `GET` | `/query?query=SELECT%20*%20FROM%20Customer%20WHERE%20...` | Pull customers by last-updated date |

Token endpoint: `https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer`

---

## Conflict Resolution

`ConflictResolver::resolve(?string $qbLastUpdated, ?string $espoSyncedAt): string`

| QB `LastUpdatedTime` | EspoCRM `qbSyncedAt` | Returns |
|---------------------|---------------------|--------|
| More recent | — | `WINNER_QB` |
| Less recent or equal | — | `WINNER_ESPO` |
| Null | Set | `WINNER_ESPO` |
| Set | Null | `WINNER_QB` |
| Null | Null | `WINNER_NONE` |

Helper: `isQbNewer(?string $qbLastUpdated, ?string $espoSyncedAt): bool`

---

## Background Jobs

### SyncFromQuickBooks

**Class:** `Espo\Modules\QuickBooks\Jobs\SyncFromQuickBooks`
**Schedule:** Daily at 2 AM (`0 2 * * *`)

1. Reads `lastSyncAt` from Integration entity.
2. Defaults to 7 days ago on first run.
3. Pulls customers since that date → applies to Accounts.
4. Pulls payments since that date → applies to Invoice statuses.
5. Updates `lastSyncAt` and `lastSyncError` on Integration entity.

### ReconcileQuickBooks

**Class:** `Espo\Modules\QuickBooks\Jobs\ReconcileQuickBooks`
**Schedule:** Daily at 3 AM (`0 3 * * *`) — run after SyncFromQuickBooks

1. Queries Accounts with `qbCustomerId` set (batch: 50).
2. Pushes accounts where `modifiedAt > qbSyncedAt`.
3. Queries non-Paid, non-Voided Invoices with `qbInvoiceId` set (batch: 50).
4. Pushes invoices where `modifiedAt > qbSyncedAt`.
5. Stores error summary in `Integration.lastSyncError`.

---

## API Routes

Registered in `Resources/routes.json`:

| Method | Route | Controller Action |
|--------|-------|------------------|
| `POST` | `/api/v1/QuickBooksIntegration/initOAuth` | `QuickBooksIntegration::postActionInitOAuth` |
| `POST` | `/api/v1/QuickBooksIntegration/runSync` | `QuickBooksIntegration::postActionRunSync` |

Both routes require an authenticated admin session (`User::isAdmin()` check in constructor).

---

## Known Limitations

| Limitation | Impact | Priority |
|-----------|--------|---------|
| No QB API pagination | Silent data loss when >1000 customers or payments match the since-date query | High |
| ReconcileQuickBooks batch=50, no offset | Only first 50 stale records reconciled per run | Medium |
| No disconnect endpoint | Cannot cleanly revoke tokens or switch QB companies from UI | High |
| No webhook support | QB changes appear in EspoCRM only after nightly pull, not in real-time | Low |
| No QB→EspoCRM invoice sync | Invoices created in QB are not reflected in EspoCRM | Low |
| Tax fields not mapped | Invoice tax amounts are not sent to or received from QB | Low |

See `docs/gap-analysis.md` for full details and implementation effort estimates.

---

## Adding Sync for a New Entity

1. Add QB fields to `Resources/metadata/entityDefs/{Entity}.json` (follow Account pattern).
2. Create `Hooks/{Entity}/Sync.php` implementing `AfterSave`, with `skipQuickBooksSync` guard.
3. Add sync method to `QuickBooksService` following `upsertCustomer()` pattern.
4. Optionally extend `SyncFromQuickBooks::run()` and `ReconcileQuickBooks::run()` for pull/reconcile.
5. Add side panel to `Resources/metadata/clientDefs/{Entity}.json` if UI status is needed.
