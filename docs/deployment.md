# Deployment guide

This document covers a single-server Apache + PHP + MySQL deployment. The app
must be served over **HTTPS** in production.

## 1. Production checklist

- [ ] PHP 8.1+ with `pdo_mysql`, `mbstring`, `openssl`, `json`, `session`.
- [ ] MySQL 8 / MariaDB 10.4+ with InnoDB and `utf8mb4`.
- [ ] Apache 2.4 with `mod_rewrite`, `mod_headers` and `AllowOverride All`
      (or the equivalent rules in the VirtualHost).
- [ ] Frontend built with the production `VITE_*` values.
- [ ] `api/config.php` created, strong `session_secret`, `debug=false`,
      `env=production`.
- [ ] Database user limited to `SELECT, INSERT, UPDATE, DELETE` on the app
      database (plus the rights needed during migration).
- [ ] HTTPS certificate installed; HTTP redirects to HTTPS.
- [ ] `database/`, `tests/`, `docs/`, `legacy/`, `FRONTEND/`, `BACKEND/`,
      `api/src/`, `api/logs/` and `api/config.php` not reachable over HTTP.
- [ ] Backups configured and a restore has been tested.
- [ ] Log rotation and monitoring configured.
- [ ] Placeholder data and demo accounts removed or replaced.
- [ ] Privacy Policy and Terms reviewed by a Philippine lawyer.

## 2. Files and permissions

Deploy the project as the VirtualHost document root:

```
index.html                 built SPA entry point
assets/                    built JS/CSS
images/                    public images
api/                       PHP API
.htaccess                  SPA fallback + security headers
```

Keep these on the server but **outside** the web root where possible:

```
api/config.php             credentials and session secret
database/backups/          dumps
api/logs/                  error log
```

If they must stay inside the project folder, rely on the shipped `.htaccess`
deny rules and verify them with a direct HTTP request.

Linux permissions (example, user `www-data`):

```bash
chown -R www-data:www-data /var/www/lpg
find /var/www/lpg -type d -exec chmod 755 {} \;
find /var/www/lpg -type f -exec chmod 644 {} \;
chmod 600 /var/www/lpg/api/config.php
chmod 750 /var/www/lpg/api/logs /var/www/lpg/database/backups
```

Windows/XAMPP: ensure the Apache service account can read the files and write
to the system temp directory, `api/logs/` and `database/backups/`.

Sessions are written to `<system temp>/lpg_sessions` with mode `0700`. On
shared hosting, confirm PHP's `sys_get_temp_dir()` is writable and not shared
with untrusted users.

## 3. Database

### Fresh install

```bash
mysql -u root -p < database/schema.sql
php database/seed.php
```

`schema.sql` creates `lpg_db`; change the database name in the schema and
config if your host requires a prefixed name.

### Least-privilege database user

```sql
CREATE USER 'lpg_app'@'127.0.0.1' IDENTIFIED BY 'use-a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON lpg_db.* TO 'lpg_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

The migration runner needs DDL rights (`CREATE`, `ALTER`, `INDEX`, `DROP`) and
`mysqldump` needs read access. Use a separate migration account, or grant DDL
temporarily and remove it afterwards.

Update `api/config.php` with the app user's credentials. Do **not** run the
application as `root`.

### Upgrades

```bash
php database/migrate.php --dry-run
php database/migrate.php
php database/seed.php
```

`migrate.php`:

1. creates `schema_migrations` if needed;
2. applies pending `database/migrations/*.sql` in filename order;
3. skips `*_down.sql`;
4. writes a `mysqldump` backup to `database/backups/` before the first pending
   migration (unless `--no-backup`).

Migration `001_add_branches` moves legacy rows to `Main Branch (MAIN)`.
Migration `002_customer_home_branch` adds the optional customer home branch and
backfills it from each customer's earliest sale, order or payment.

DDL auto-commits in MySQL. If a migration fails halfway, **restore the backup**
rather than re-running the file.

### Rollback

Preferred:

```bash
mysql -u root -p lpg_db < database/backups/lpg_db_YYYYmmdd_HHMMSS_before_migrations.sql
```

The down scripts (`database/migrations/*_down.sql`) exist for development, but
they discard branch history, per-branch prices and customer home branches.
Restoring the backup is the supported lossless rollback.

## 4. Apache VirtualHost

```apache
<VirtualHost *:443>
    ServerName lpg.example.com
    DocumentRoot "/var/www/lpg"

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/lpg.example.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/lpg.example.com/privkey.pem

    <Directory "/var/www/lpg">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Optional: keep config outside the document root.
    SetEnv LPG_CONFIG_FILE "/etc/lpg/config.php"

    ErrorLog  ${APACHE_LOG_DIR}/lpg-error.log
    CustomLog ${APACHE_LOG_DIR}/lpg-access.log combined
</VirtualHost>

<VirtualHost *:80>
    ServerName lpg.example.com
    Redirect permanent / https://lpg.example.com/
</VirtualHost>
```

Notes:

- The SPA uses absolute `/assets/...` and `/api/...` paths, so deploy at the
  host root.
- `AllowOverride All` is required for the shipped `.htaccess` files.
- If `.htaccess` is disabled, copy its rules into the VirtualHost and block
  `database|legacy|tests|docs` explicitly.
- The API sends HSTS only when it detects HTTPS. Ensure your proxy forwards
  `HTTPS=on` (or `SERVER_PORT=443`). Alternatively add HSTS at the web server.
- **Nginx is not supported out of the box.** `.htaccess` is ignored there. You
  must reproduce the rewrite, deny and security-header rules in the Nginx
  config, and test every route.

## 5. PHP configuration

```ini
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
error_reporting = E_ALL & ~E_DEPRECATED
memory_limit = 256M
post_max_size = 2M
upload_max_filesize = 2M
max_execution_time = 60
session.use_strict_mode = 1
session.use_only_cookies = 1
session.cookie_httponly = 1
```

Enable OPcache in production:

```ini
opcache.enable = 1
opcache.validate_timestamps = 0   ; restart PHP-FPM after deploying changes
```

The API writes PHP errors to `api/logs/error.log` when that directory is
writable.

## 6. HTTPS and headers

Check with:

```bash
curl -I https://lpg.example.com/api/health
```

Expected headers include:

```
Content-Security-Policy: default-src 'self'; ... frame-ancestors 'none'; form-action 'self'
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
Cross-Origin-Resource-Policy: same-origin
```

If HSTS is missing, the app did not detect HTTPS. Fix the proxy headers or add
HSTS at the web server. Do not enable HSTS on a domain you cannot keep on HTTPS.

## 7. Build and deploy frontend

On a build machine or CI:

```bash
cd frontend
npm ci
npm run build
```

Deploy `index.html` and `assets/` together. The build cleans nothing else
(`emptyOutDir: false`), so it is safe to run in place. Set the real `VITE_*`
values before building; changing them requires a rebuild.

Cache policy:

- `index.html` — `no-cache` (or a short max-age) so new asset hashes are picked
  up.
- `assets/index-*.js|css` — long-lived immutable cache is safe because Vite
  fingerprints filenames.

The shipped Apache config does not set per-file cache headers. Add them in the
VirtualHost if needed.

## 8. Backups and restore

Daily logical backup:

```bash
mysqldump -u lpg_backup -p --single-transaction --routines lpg_db \
  | gzip > /backups/lpg_db_$(date +%Y%m%d_%H%M%S).sql.gz
```

Keep backups off the web server, encrypted at rest, and test a restore into a
staging database. Suggested policy:

- daily database dump, retained 30 days;
- weekly full backup, retained 12 weeks;
- backup before every migration or price/stock correction;
- define RPO/RTO with the business owner.

Restore:

```bash
gunzip -c /backups/lpg_db_YYYYmmdd_HHMMSS.sql.gz | mysql -u root -p lpg_db
```

## 9. Logging and monitoring

- Watch `api/logs/error.log` for `Database error`, `Unhandled error` and
  repeated `Rate limit hit` entries.
- Rotate `api/logs/error.log` (logrotate or a scheduled task); do not let it
  fill the disk.
- Monitor `GET /api/health` every minute from an external service.
- Alert on HTTP 5xx rate, disk usage, database connection failures and backup
  failures.
- Review the **Audit** page after incidents and periodically for unusual
  voids, price changes, user changes and transfers.

## 10. Go-live smoke test

Run these against the real HTTPS host:

1. `GET /api/health` → `200` and `{"ok":true}`.
2. Load `/`, `/privacy`, `/terms`, `/login`, `/signup` → `200`.
3. Log in as owner, change the bootstrap password.
4. Create/verify every real branch. Set address, phone, hours, cutoff and max
   order quantity.
5. Replace all prices and record opening stock per branch.
6. Create a staff account in each branch and log in.
7. Place a customer order, approve, deliver and verify stock and reports.
8. Record a cash sale, a credit sale, a payment and a void.
9. Create, approve, ship and receive a transfer.
10. Export a CSV report and open it in a spreadsheet.
11. Deactivate a test staff account and confirm the session ends.
12. Confirm `database/`, `tests/`, `docs/`, `legacy/`, `api/src/` and
    `api/config.php` are not downloadable.
13. Confirm the privacy contact and legal version/effective date are real.

## 11. Incident basics

- **Suspected account compromise:** deactivate the user (sessions are
  invalidated by the `is_active` / `session_version` check), reset the password,
  review the audit trail, rotate the session secret only if tokens may have
  leaked, and force other users to re-authenticate by bumping their
  `session_version` if needed.
- **Leaked database credentials:** change the DB password, update
  `api/config.php`, restart PHP, review access logs and the audit trail.
- **Leaked session secret:** rotate `session_secret` (this invalidates OTP and
  password-reset tokens), then force a password/session reset for affected
  accounts.
- **Bad price or stock entry:** void/reverse the transaction; do not edit rows
  directly. Record a stock adjustment for the correction and note the reason.

See [security.md](security.md) for the full security model.
