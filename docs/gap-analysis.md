# Gap Analysis

**Module version:** 1.0.0  
**Last reviewed:** 2026-05-26

---

## Implemented (v1.0.0)

| Feature | Status |
|---------|-------|
| OAuth 2.0 connect flow with CSRF state token | ✅ Complete |
| Account → QB Customer (real-time push via hook) | ✅ Complete |
| Contact → QB Customer (real-time push via hook) | ✅ Complete |
| Invoice → QB Invoice (real-time push via hook) | ✅ Complete |
| Invoice void → QB void (status=Voided trigger) | ✅ Complete |
| QB Customer → EspoCRM Account (nightly pull) | ✅ Complete |
| QB Payment → EspoCRM Invoice status=Paid (nightly pull) | ✅ Complete |
| Nightly reconciliation job (push stale local changes) | ✅ Complete |
| Conflict resolution (last-modified-wins via ConflictResolver) | ✅ Complete |
| Token auto-refresh (30s before expiry) | ✅ Complete |
| Admin UI: OAuth connect button, Sync Now, status display | ✅ Complete |
| QB side panel on Account/Contact detail view | ✅ Complete |
| Invoice entity with status lifecycle (Draft/Sent/Paid/Overdue/Voided) | ✅ Complete |
| Scheduled job registration (both jobs) | ✅ Complete |
| Loop guard (skipQuickBooksSync prevents re-entrant hooks) | ✅ Complete |
| Unit tests (39 tests) | ✅ Complete |
| Install script with PHP version check | ✅ Complete |
| Release packaging script | ✅ Complete |

---

## Open Gaps

### 🔴 High Severity — Blocks Production at Scale

#### 1. No QB API Pagination

**Symptom:** Silent data loss when >1000 customers or payments match the `since-date` query.

**Root cause:** `pullCustomersSince()` and `pullPaymentsSince()` issue a single QB query with no `STARTPOSITION`/`MAXRESULTS` paging. The QB API returns at most 1000 records per response; the rest are silently dropped.

**Affected:** `Services/QuickBooksService.php` — `pullPaymentsSince()`, `pullCustomersSince()`

**Fix:**
```php
$startPosition = 1;
do {
    $query = "SELECT * FROM Customer WHERE ... STARTPOSITION $startPosition MAXRESULTS 1000";
    $customers = $this->request('GET', $this->apiUrl("query?query=" . urlencode($query)))
        ['QueryResponse']['Customer'] ?? [];
    foreach ($customers as $customer) {
        $this->applyCustomerToAccount($customer);
    }
    $startPosition += 1000;
} while (count($customers) === 1000);
```

**Effort:** 2–3 hours

---

#### 2. No Disconnect / Re-authorize Endpoint

**Symptom:** No way to revoke tokens or switch QB companies from the admin UI. Changing QB companies requires directly editing the database.

**Root cause:** `QuickBooksIntegration` controller has `postActionInitOAuth` and `postActionRunSync` but no disconnect action. The admin view has no Disconnect button.

**Fix:**
- Add `postActionDisconnect()` to controller: clear `accessToken`, `refreshToken`, `realmId`, `accessTokenExpiresAt`, `oauthState`.
- Add Disconnect button to admin view (only shown when `isConnected === true`).
- Add route entry in `routes.json`.

**Effort:** 1–2 hours

---

### 🟡 Medium Severity — Significant Limitation

#### 3. ReconcileQuickBooks Not Paginated

**Symptom:** Only the first 50 Accounts and first 50 Invoices are checked per run. Large datasets may take many nightly cycles to fully reconcile after a bulk edit.

**Root cause:** `BATCH_SIZE = 50` with a single `limit(0, 50)` query. No offset loop.

**Affected:** `Jobs/ReconcileQuickBooks.php` — `reconcileAccounts()`, `reconcileInvoices()`

**Fix:** Add an offset loop: increment by `BATCH_SIZE` until a batch returns fewer than `BATCH_SIZE` records.

**Effort:** 1 hour

---

#### 4. No Per-Record Sync Audit Trail

**Symptom:** When a specific record is out of sync, the only diagnostic is to grep `data/logs/espo.log`. `Integration.lastSyncError` only stores the most recent job-level error.

**Fix options:**
- **Light:** Structured log entries in a dedicated file (`data/logs/quickbooks-sync.log`) with entity type, entity ID, direction, timestamp, result.
- **Full:** A `QbSyncLog` EspoCRM entity recording each sync event, queryable via the UI.

**Effort:** 4–8 hours

---

#### 5. QB→EspoCRM Invoice Sync Not Implemented

**Symptom:** Invoices created directly in QuickBooks do not appear in EspoCRM.

**Fix:** Add `pullInvoicesSince(string $sinceDate)` to `QuickBooksService` following the `pullCustomersSince` pattern. Query `FROM Invoice WHERE MetaData.LastUpdatedTime >= '$sinceDate'`, create or update matching EspoCRM Invoice records.

**Effort:** 3–4 hours

---

### 🟢 Low Severity — Nice to Have

#### 6. No Webhook Support (Poll-Only)

QB real-time webhooks would eliminate the nightly pull latency. Requires:
- New EntryPoint for QB webhook delivery (POST, HMAC-SHA256 signature verification)
- Async processing (queue webhook payload to a background job)
- Intuit developer app webhook subscription configuration

**Effort:** 8–12 hours

---

#### 7. No Health Check / Status Endpoint

A `GET /QuickBooksIntegration/status` endpoint returning token validity, realmId, last sync times, and last error would make it easy to monitor the integration from an external system or admin dashboard.

**Effort:** 1–2 hours

---

#### 8. Tax Handling Not Implemented

Invoice line items sync without tax information. QB requires `TaxCodeRef` on line items for companies with taxes enabled; omitting it may cause QB API rejection for certain company configurations.

**Effort:** 2–4 hours (depends on how EspoCRM stores tax rates)

---

#### 9. PDF Attachment Sync

QB invoices can have PDF renderings. There is no mechanism to sync QB invoice PDFs to EspoCRM Attachments.

**Effort:** 3–5 hours

---

## Recommended Priorities for v1.1.0

| # | Item | Severity | Effort |
|---|------|----------|--------|
| 1 | QB API pagination | High | 2–3 h |
| 2 | Disconnect endpoint | High | 1–2 h |
| 3 | ReconcileQuickBooks pagination | Medium | 1 h |
| 4 | QB→EspoCRM invoice sync | Medium | 3–4 h |

Total estimated effort for v1.1.0: ~7–10 hours.
