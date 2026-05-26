# EspoCRM QuickBooks Integration

Bidirectional sync between EspoCRM and QuickBooks Online.

- Accounts/Contacts ↔ QB Customers (bidirectional, conflict resolution via last-modified-wins)
- EspoCRM Invoices → QB Invoices (push on save)
- QB Payments → EspoCRM Invoice status (nightly pull, marks Invoice as Paid)
- Invoice voided in EspoCRM → QB void (hook-dispatched)

## Requirements

- EspoCRM 9.x
- PHP 8.3+
- HTTPS on your EspoCRM instance (required for QuickBooks OAuth)
- A QuickBooks Online developer app ([developer.intuit.com](https://developer.intuit.com))

## Installation

**From a release ZIP:**

```bash
unzip espocrm-quickbooks-v*.zip -d /tmp/qb-module
bash /tmp/qb-module/scripts/install.sh --espo-path /path/to/espocrm
```

**From source:**

```bash
git clone https://github.com/coreconduit/espocrm-quickbooks.git
cd espocrm-quickbooks
bash scripts/install.sh --espo-path /path/to/espocrm
```

## Configuration

1. Register a QuickBooks developer app at [developer.intuit.com](https://developer.intuit.com).
2. Add a redirect URI: `https://your-espocrm-domain.com/?entryPoint=QuickBooksOauthCallback`
3. In EspoCRM: **Admin → Integrations → QuickBooks**
   - Enter **Client ID**, **Client Secret**, and **Default QB Item ID**
   - Click **Save**, then **Connect to QuickBooks** — authorize in the popup
4. Enable scheduled jobs: **Admin → Scheduled Jobs**
   - `QuickBooks: Sync from QuickBooks` — nightly pull of QB Customers and Payments (2 AM)
   - `QuickBooks: Reconcile` — nightly conflict resolution (3 AM, after sync)
5. Configure cron (once per minute, as the web server user):
   ```
   * * * * * www-data php /path/to/espocrm/cron.php > /dev/null 2>&1
   ```

## Data Model

| EspoCRM Field | QuickBooks Field |
|---|---|
| Account.name | Customer.DisplayName / CompanyName |
| Account.qbCustomerId | Customer.Id |
| Contact.name | Customer.DisplayName |
| Invoice.amount | Invoice total |
| Invoice.status = Paid | Payment received |
| Invoice.status = Voided | Invoice voided |

New fields added to Account and Contact: `qbCustomerId`, `qbCustomerSyncToken`, `qbSyncedAt`.

## Development & Testing

Tests require a local EspoCRM installation for the `Espo\Core\*` namespace:

```bash
ESPO_PATH=/path/to/espocrm php /path/to/espocrm/vendor/bin/phpunit \
    --configuration phpunit.xml \
    --no-coverage
```

Expected: 39 tests, 0 failures.

To build a release ZIP:

```bash
./scripts/release.sh --version 1.0.0 --espo-path /path/to/espocrm
# Output: releases/espocrm-quickbooks-v1.0.0.zip
```

## Documentation

- [System architecture](docs/architecture.md)
- [Integration reference — field maps, API, conflict resolution](docs/quickbooks-integration.md)
- [Setup & deployment guide](docs/setup.md)
- [Gap analysis — implemented features and open issues](docs/gap-analysis.md)
- [Module internals](custom/Espo/Modules/QuickBooks/README.md)

## License

MIT — see [LICENSE](LICENSE).
