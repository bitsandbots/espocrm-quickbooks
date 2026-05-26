# QuickBooks Integration — Module Reference

## Overview

The QuickBooks module (`custom/Espo/Modules/QuickBooks/`) provides bidirectional sync between EspoCRM and QuickBooks Online (QBO) via the QBO REST API v3.

**What syncs:**

| EspoCRM | QB Online | Direction | Trigger |
|---------|-----------|-----------|---------|
| Account | Customer | → (push) | afterSave hook |
| Contact | Customer | → (push) | afterSave hook |
| Account | Customer | ← (pull) | Nightly job |
| Invoice (custom) | Invoice | → (push) | afterSave hook |
| — | Payment | ← (pull) | Nightly job → sets Invoice.status=Paid |

## File Reference

```
Services/QuickBooksService.php     Core: all QB API calls + token refresh
EntryPoints/QuickBooksOauthCallback.php  OAuth2 redirect handler
Hooks/Account/Sync.php             Account afterSave hook
Hooks/Contact/Sync.php             Contact afterSave hook
Hooks/Invoice/Sync.php             Invoice afterSave hook
Jobs/SyncFromQuickBooks.php        Nightly pull (customers + payments)
Jobs/ReconcileQuickBooks.php       Nightly reconciliation push
Tools/ConflictResolver.php         Conflict resolution logic
Entities/Invoice.php               Invoice entity class
Resources/metadata/integrations/QuickBooks.json   Admin UI config
Resources/metadata/entityDefs/Account.json        QB fields on Account
Resources/metadata/entityDefs/Contact.json        QB fields on Contact
Resources/metadata/entityDefs/Invoice.json        Invoice entity schema
```

## QuickBooksService API

### `upsertCustomer(string $entityType, Entity $entity): void`

Maps an Account or Contact to a QB Customer and creates or updates it.

- If `$entity->get('qbCustomerId')` is null → POST (create)
- If set → POST with `Id` + `SyncToken` (QB uses POST for updates with sparse flag)
- Writes `qbCustomerId`, `qbCustomerSyncToken`, `qbSyncedAt` back to entity
- Saves with `skipQuickBooksSync=true` to prevent hook re-entry

**Field mapping:**

| EspoCRM | QB Customer |
|---------|------------|
| `name` (Account) | `CompanyName`, `DisplayName` |
| `firstName` + `lastName` (Contact) | `GivenName`, `FamilyName`, `DisplayName` |
| `emailAddress` | `PrimaryEmailAddr.Address` |
| `phoneNumber` | `PrimaryPhone.FreeFormNumber` |
| `website` | `WebAddr.URI` |
| `billingAddress*` | `BillAddr.*` |

### `upsertInvoice(Entity $invoice): void`

Maps an EspoCRM Invoice to a QB Invoice. Requires the linked Account to have a `qbCustomerId`.

- Resolves `CustomerRef` from Account's `qbCustomerId`
- Maps `lineItems` JSON array → QB `Line[]` with `SalesItemLineDetail`
- Falls back to a single line with `amount` if `lineItems` is empty
- Writes `qbInvoiceId`, `qbInvoiceSyncToken`, `qbSyncedAt` back to entity

### `pullPaymentsSince(string $sinceDate): void`

Queries QB for all Payments with `TxnDate >= sinceDate`. For each payment:
1. Finds linked QB Invoice ID from `Line[0].LinkedTxn`
2. Finds EspoCRM Invoice by `qbInvoiceId`
3. Sets `status = Paid`, stores `qbPaymentId` and `qbPaymentDate`

### `pullCustomersSince(string $sinceDate): void`

Queries QB Customers updated since `sinceDate`. For each customer:
1. Finds EspoCRM Account by `qbCustomerId`
2. Checks `ConflictResolver` — only updates if QB is newer than `qbSyncedAt`
3. Updates `name`, `emailAddress`, `phoneNumber`

### Token Refresh

Tokens refresh automatically inside `getAccessToken()`:
- Checks `accessTokenExpiresAt` with 30-second margin
- If expired: HTTP Basic auth POST to `https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer` with `grant_type=refresh_token`
- Writes new `accessToken` + `accessTokenExpiresAt` to Integration entity

## Hooks

All three hooks follow the same pattern:

```php
public function afterSave(Entity $entity, SaveOptions $options): void
{
    if ($options->get('skipQuickBooksSync')) return;  // loop guard
    try {
        $service = $this->injectableFactory->create(QuickBooksService::class);
        $service->upsertCustomer('Account', $entity);
    } catch (Throwable $e) {
        $this->log->warning("QB sync failed: " . $e->getMessage());
        // Does NOT abort the CRM save
    }
}
```

Key properties:
- `static $order = 20` — runs after EspoCRM's own hooks (typically order 9–11)
- Failures are swallowed at `warning` level so CRM saves always succeed
- Hook is skipped if integration is disabled (throws inside `getIntegration()`)

## Background Jobs

### SyncFromQuickBooks

Reads `lastSyncAt` from Integration entity. Defaults to 7 days ago on first run. After a successful run, writes the current timestamp back to `lastSyncAt`.

### ReconcileQuickBooks

Finds records where `modifiedAt > qbSyncedAt` (EspoCRM was modified more recently than last sync). Pushes those to QB. Batch size: 50 records per run.

## Conflict Resolution

`Tools/ConflictResolver::resolve(?string $qbLastUpdated, ?string $espoSyncedAt): string`

Returns one of: `WINNER_QB`, `WINNER_ESPO`, `WINNER_NONE`.

Logic:
- Both null → NONE
- Only QB time known → QB wins
- Only EspoCRM time known → EspoCRM wins
- Both known → newer timestamp wins; tie goes to EspoCRM

## QB API Endpoints Used

All calls go to: `https://quickbooks.api.intuit.com/v3/company/{realmId}/`

| Operation | Method | Path |
|-----------|--------|------|
| Create Customer | POST | `customer` |
| Update Customer | POST | `customer` (with Id + SyncToken in body) |
| Query Customers | GET | `query?query=SELECT * FROM Customer WHERE ...` |
| Create Invoice | POST | `invoice` |
| Update Invoice | POST | `invoice` (with Id + SyncToken) |
| Query Payments | GET | `query?query=SELECT * FROM Payment WHERE ...` |
| Ping / verify | GET | `companyinfo/{realmId}` |

All requests require: `Authorization: Bearer {accessToken}`, `Accept: application/json`.

## Integration Entity Fields

The `Integration` entity (id=`QuickBooks`) stores all credentials in its `data` JSON column. Access via:

```php
$integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'QuickBooks');
$realmId = $integration->get('realmId');
```

Fields stored: `clientId`, `clientSecret`, `accessToken`, `refreshToken`, `accessTokenExpiresAt`, `realmId`, `connectedAt`, `lastSyncAt`, `oauthState`.

## Adding a New Entity Sync

To add sync for a new EspoCRM entity (e.g., Lead → QB Customer):

1. Add `qbCustomerId`, `qbCustomerSyncToken`, `qbSyncedAt` fields in `Resources/metadata/entityDefs/Lead.json`
2. Create `Hooks/Lead/Sync.php` — copy Contact hook, change entity type
3. Extend `QuickBooksService::buildCustomerPayload()` to handle `'Lead'` entity type
4. Add QB field mappings for Lead-specific fields
5. Run `php rebuild.php`
6. Add test coverage in `tests/unit/Espo/Modules/QuickBooks/`
