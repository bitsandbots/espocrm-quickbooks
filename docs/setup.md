# Setup Guide

## Prerequisites

| Requirement | Version | Notes |
|-------------|---------|-------|
| PHP | 8.3+ | CLI and FPM must match. Extensions: pdo_mysql, curl, json, mbstring, openssl |
| MySQL | 8.0+ | or MariaDB 10.3+ |
| EspoCRM | 9.x | Installed and running |
| Composer | 2.x | For EspoCRM dependencies |
| Node.js | 22 | Only needed for JS transpilation |
| Cron | — | Required for scheduled jobs |
| HTTPS | — | Mandatory for QuickBooks OAuth |

---

## Installation

### Option A: From Release ZIP (Recommended)

```bash
# Download the release archive
curl -LO https://github.com/coreconduit/espocrm-quickbooks/releases/download/v1.0.0/espocrm-quickbooks-v1.0.0.zip

# Extract
unzip espocrm-quickbooks-v1.0.0.zip -d /tmp/qb-module

# Install
bash /tmp/qb-module/scripts/install.sh --espo-path /path/to/espocrm
```

### Option B: From Source

```bash
git clone https://github.com/coreconduit/espocrm-quickbooks.git
cd espocrm-quickbooks
bash scripts/install.sh --espo-path /path/to/espocrm
```

The install script:
1. Checks PHP 8.3+ is available
2. Copies `custom/Espo/Modules/QuickBooks` to EspoCRM
3. Copies `client/custom/modules/quick-books` to EspoCRM
4. Runs `php command.php rebuild` and `clear-cache`
5. Prints post-install instructions

---

## HTTPS Setup

QuickBooks OAuth requires HTTPS. For local development, use [mkcert](https://github.com/FiloSottile/mkcert):

```bash
# Install mkcert
sudo apt install libnss3-tools
curl -LO "https://dl.filippo.io/mkcert/latest?for=linux/amd64" -o mkcert
chmod +x mkcert && sudo mv mkcert /usr/local/bin/

# Install local CA
mkcert -install

# Generate certificate
mkcert your-local-domain.test
# Creates: your-local-domain.test.pem and your-local-domain.test-key.pem
```

Example nginx config:

```nginx
server {
    listen 8443 ssl;
    server_name your-local-domain.test;

    ssl_certificate     /path/to/your-local-domain.test.pem;
    ssl_certificate_key /path/to/your-local-domain.test-key.pem;

    root /path/to/espocrm;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    add_header X-Frame-Options SAMEORIGIN;
    add_header X-Content-Type-Options nosniff;
}
```

Enable and reload:

```bash
sudo ln -s /etc/nginx/sites-available/espocrm /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Update EspoCRM's site URL:

```bash
cd /path/to/espocrm
php command.php set-config --name siteUrl --value "https://your-local-domain.test:8443"
php command.php clear-cache
```

---

## QuickBooks Developer App

1. Go to [developer.intuit.com](https://developer.intuit.com) → **My Apps** → **Create an App**
2. Choose **QuickBooks Online and Payments**
3. Under **Keys & credentials** → copy **Client ID** and **Client Secret**
4. Under **Redirect URIs**, add:
   ```
   https://your-espocrm-domain.com/?entryPoint=QuickBooksOauthCallback
   ```
5. Scope: `com.intuit.quickbooks.accounting` (set automatically by the integration)

For production, publish the app or add your user to the app's allowed-users list during development mode.

---

## Configuring the Integration

1. Log in to EspoCRM as an administrator
2. Go to **Admin → Integrations → QuickBooks**
3. Enable the integration
4. Enter your **Client ID** and **Client Secret**
5. Optionally enter a **Default QB Item ID** (fallback for invoices without explicit line items)
6. Click **Save**
7. Click **Connect to QuickBooks** — a popup opens to authorize with Intuit
8. Complete the Intuit authorization flow
9. The popup closes and the status section shows "✓ Connected"

---

## Scheduled Jobs

Enable in **Admin → Scheduled Jobs**:

| Job Name | Recommended Schedule | Purpose |
|----------|---------------------|---------|
| QuickBooks: Sync from QuickBooks | `0 2 * * *` (2 AM daily) | Pull customer and payment updates from QB |
| QuickBooks: Reconcile | `0 3 * * *` (3 AM daily) | Push EspoCRM-side changes not yet in QB |

Run Reconcile at least 30 minutes after Sync to avoid race conditions.

---

## Cron Configuration

EspoCRM requires its cron runner every minute for scheduled jobs to fire:

```bash
# Add as the web server user (e.g., www-data)
crontab -u www-data -e
```

Add:
```
* * * * * php /path/to/espocrm/cron.php > /dev/null 2>&1
```

Verify cron is running:
```bash
sudo systemctl status cron
```

---

## Frontend Transpilation

If you modify JavaScript source files under `client/custom/modules/quick-books/src/`, regenerate the transpiled AMD output:

```bash
cd /path/to/espocrm
node js/transpile.js
```

Output lands in `client/custom/modules/quick-books/lib/transpiled/src/`. The source `src/` files are not loaded by the browser directly.

---

## CLI Reference

Run from the EspoCRM root directory:

| Command | Purpose |
|---------|---------|
| `php command.php rebuild` | Rebuild metadata cache (required after module changes) |
| `php command.php clear-cache` | Clear application cache |
| `php command.php run-job SyncFromQuickBooks` | Run sync job manually |
| `php command.php run-job ReconcileQuickBooks` | Run reconcile job manually |

---

## Running Tests

From the project root. Requires EspoCRM with Composer dependencies installed:

```bash
ESPO_PATH=/path/to/espocrm vendor/bin/phpunit --configuration phpunit.xml
```

Expected: **39 tests, 0 failures**.

---

## Building a Release

```bash
# Full build (transpile + test + package)
./scripts/release.sh --version 1.0.0 --espo-path /path/to/espocrm

# Skip transpilation if source hasn't changed
./scripts/release.sh --version 1.0.0 --espo-path /path/to/espocrm --skip-transpile

# Output: releases/espocrm-quickbooks-v1.0.0.zip
```

---

## Logs

| Location | Contents |
|----------|---------|
| `/path/to/espocrm/data/logs/espo.log` | Application log — hook warnings, job errors |
| Admin → Integrations → QuickBooks | `Last Sync Error` field shows the most recent job failure |

---

## Troubleshooting

**"QuickBooks integration is not enabled"**
Enable the integration in Admin → Integrations → QuickBooks.

**"No access token. Please connect via Admin → Integrations → QuickBooks."**
OAuth has not been completed. Click "Connect to QuickBooks".

**"Cannot sync invoice — linked Account has no QB Customer ID. Sync the Account first."**
Save the invoice's linked Account first so it syncs and gets a QB Customer ID.

**"realmId not set. Please reconnect the integration."**
Re-authorize by clicking Connect to QuickBooks.

**Popup blocked**
Allow popups for your EspoCRM domain in browser settings, then click Connect again.

**Hook sync failed (warning in espo.log)**
Non-fatal — the entity saved successfully. Common causes: expired tokens (reconnect) or QB API downtime (Reconcile job will retry).

**Rebuild fails after install**
Confirm PHP CLI and PHP-FPM versions match: `php --version`. They must be the same minor version.

**QB token refresh failing after 100 days**
Refresh tokens expire after 100 days of inactivity. Re-authorize via Connect to QuickBooks.

**Sync not running nightly**
Confirm cron is running (`crontab -l` as the web server user) and the scheduled jobs are enabled in Admin → Scheduled Jobs.
