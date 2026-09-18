# Deploying to collecto.cissytech.com

This project is prepared for an Apache hosting account where the folder mapped to `https://collecto.cissytech.com` is the application root (for example, `public_html`).

## 1. Prepare the configuration

On the server, copy `.env.example` to `.env`. Keep `.env` private and never commit it to Git.

Set these values in `.env`:

```ini
APP_URL="https://collecto.cissytech.com"
APP_DEBUG="false"
STORAGE_PATH="storage/data.json"

CYBERSOURCE_ENV="live"
CYBERSOURCE_MERCHANT_ID="your_production_mid"
CYBERSOURCE_KEY_ID="your_rest_key_id"
CYBERSOURCE_SHARED_SECRET="your_rest_shared_secret"
CYBERSOURCE_WEBHOOK_KEY_ID="your_webhook_key_id"
CYBERSOURCE_WEBHOOK_SHARED_SECRET="your_webhook_shared_secret"
```

Use separate production REST and webhook credentials from `https://businesscenter.cybersource.com`. The application accepts `live` (and maps `production` to `live` for compatibility).

For PegPay, add the test or production API URL, vendor code, password and the server-only private-key path to `.env`. Send Pegasus the matching public `.cer` or `.crt` file. If you generated `pegasus-public.crt`, send that file to Pegasus; it matches `private.key`. Never upload the private key to Git or place it in the web-accessible project folder.

## 2. Upload with WinSCP

Upload the entire contents of this project into the folder served as the Collecto domain root, including the hidden `.htaccess` file. Do not upload `.env` from your computer if it contains development-only values; create the server `.env` in step 1 instead.

The resulting server structure should be:

```text
public_html/
├── .env                     # private server configuration
├── .htaccess                # routes every browser request to public/
├── app/                     # shared foundation
├── card/                    # payment dashboard, CyberSource, views, assets and schema
├── efris/                   # separate EFRIS gateway module
├── public/
└── storage/
```

The supplied root `.htaccess` routes public requests through `public/index.php`. It requires Apache `mod_rewrite` and `AllowOverride FileInfo` (common on cPanel hosting).

## 3. Set permissions

The PHP user must be able to write to `storage/`. Set that folder to `755` first; use `775` only if your host requires group write access. Do not make it world-writable unless your host specifically requires it.

## 4. Database (recommended)

Create a MySQL database and user in the hosting panel, import `card/database/schema.mysql.sql` followed by `pegasus/database/schema.mysql.sql`, then set:

```ini
DB_DSN="mysql:host=localhost;dbname=your_database_name;charset=utf8mb4"
DB_USER="your_database_user"
DB_PASSWORD="your_database_password"
```

The SQL file does not create or select a database. Import it while `cissytechweb_vault` is selected in phpMyAdmin.

Confirm that PHP has the `pdo_mysql` extension enabled. If these values are blank, the application uses `storage/data.json`; this is suitable for small testing only, not multi-user production use.

All physical application tables use the `tbl_` prefix: `tbl_app_state`, `tbl_pegasus_transactions`, and `tbl_pegasus_api_logs`. If the database was already used by an earlier project version, run `card/database/migrate-to-tbl-prefix.mysql.sql` once before deploying so that `app_state` is safely renamed to `tbl_app_state`.

## 5. Verify before adding CyberSource webhooks

Open these URLs after upload:

```text
https://collecto.cissytech.com/links
https://collecto.cissytech.com/developers/api
https://collecto.cissytech.com/api/v1/openapi.json
https://collecto.cissytech.com/webhooks/cybersource/health
https://collecto.cissytech.com/payment/return
https://collecto.cissytech.com/api/v1/pegasus/openapi.json
```

The health URL must return a success JSON response over valid HTTPS before CyberSource can validate the webhook subscription.

## 6. Configure the CyberSource webhook

In CyberSource Business Center, use:

```text
Callback URL: https://collecto.cissytech.com/webhooks/cybersource
Health URL:   https://collecto.cissytech.com/webhooks/cybersource/health
```

Create and configure a **separate Webhooks Digital Signature Key**. It is different from the REST Shared Secret used to create payment links. Subscribe to the `customerInvoicing` events already listed in the README.

## Important security notes

- The dashboard is intentionally open right now, as requested. Before accepting real payments, put it behind authentication or restrict it by IP address in the host panel.
- Keep the `.env` file, log files, backups, and database credentials private.
- Use CyberSource-hosted checkout for third-party integrations. Do not enable direct-card handling unless the calling system and this server meet PCI DSS requirements.
