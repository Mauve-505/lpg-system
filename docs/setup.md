# Setup guide

This guide installs the PHP + React LPG system from a clean checkout. For a
production server, read [deployment.md](deployment.md) as well.

## 1. Requirements

| Component | Minimum | Notes |
|-----------|---------|-------|
| PHP | 8.1+ | 8.2/8.3 recommended. The bundled workspace was verified on 8.0.30, so run a final 8.1+ smoke test on the target host. |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `json`, `session` | `curl` is also needed to run the test suite. |
| Database | MySQL 8.0+ or MariaDB 10.4+ | InnoDB, `utf8mb4`. MariaDB 10.4.32 was used for verification. |
| Apache | 2.4+ | `mod_rewrite` and `mod_headers` enabled. XAMPP includes both. |
| Node.js | 20+ | Build-time only; not needed at runtime. |
| npm | 10+ | Ships with current Node. |

Check PHP from a terminal:

```powershell
C:\xampp\php\php.exe -v
C:\xampp\php\php.exe -m
```

You should see `pdo_mysql`, `mbstring` and `openssl` in the module list.

## 2. Get the files in place

The built React app expects to live at the **root of the site** because its
assets are referenced as `/assets/...` and the API as `/api/...`.

### Option A — Apache VirtualHost (recommended)

Point a VirtualHost directly at this project folder. Example for Windows
XAMPP (`C:\xampp\apache\conf\extra\httpd-vhosts.conf`):

```apache
<VirtualHost *:80>
    ServerName lpg.local
    DocumentRoot "C:/Users/Ugss/Documents/lpg-system"

    <Directory "C:/Users/Ugss/Documents/lpg-system">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog "logs/lpg-error.log"
    CustomLog "logs/lpg-access.log" combined
</VirtualHost>
```

Add `127.0.0.1 lpg.local` to `C:\Windows\System32\drivers\etc\hosts`, restart
Apache, then open <http://lpg.local/>.

### Option B — project at the document root

If this project is the only site, you can make it XAMPP's `htdocs` content
(for example by setting `DocumentRoot "C:/xampp/htdocs"` and putting the files
there). Do not install it at `http://localhost/lpg-system/` without adjusting
`base` in `frontend/vite.config.js` and the API base in `frontend/src/api.js`.

### Option C — PHP built-in server (local preview only)

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t . tests\router.php
```

`tests/router.php` serves `/api/*` through `api/index.php` and everything else
through the built `index.html`. It is a development convenience; **do not
deploy it** as the production router.

## 3. Create and configure the database

Start MySQL in the XAMPP control panel, then import the fresh schema:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
```

The schema creates the `lpg_db` database, all tables, the default `MAIN`
branch, starter cylinder sizes and stock-lock rows. If your MySQL root account
has a password:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -p < database\schema.sql
```

Copy the configuration template:

```powershell
Copy-Item api\config.example.php api\config.php
```

Edit `api/config.php`:

- `db.host`, `db.port`, `db.name`, `db.user`, `db.pass`
- `timezone` (default `Asia/Manila`)
- `session_secret` — generate a unique value:

  ```powershell
  C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
  ```

- `bootstrap.admin_username` and `bootstrap.admin_password` — used only by the
  seed script when no owner exists.

Every setting can also come from an environment variable. See
[configuration.md](configuration.md).

## 4. Seed the first owner

```powershell
C:\xampp\php\php.exe database\seed.php
```

Output includes the bootstrap username and temporary password. The account is
forced to change that password at first login. This is deliberate: there is no
default password that can survive a first login.

Optional sample customers and customer logins:

```powershell
C:\xampp\php\php.exe database\seed.php --demo
```

Demo customer logins use `customer123`; change or delete them before launch.
Demo data is only for local testing.

## 5. Build the frontend

```powershell
cd frontend
npm install
npm run build
cd ..
```

The build writes `index.html`, `assets/index-*.js` and `assets/index-*.css` to
the project root. `emptyOutDir` is disabled in `frontend/vite.config.js` so the
build cannot delete `api/`, `database/` or other server folders.

Optional branding/contact values are read at build time. Create
`frontend/.env.local` (or set the variables in the build environment):

```dotenv
VITE_BRAND_NAME=LPG Distributor
VITE_COMPANY_NAME=Your Company Name
VITE_BRAND_TAGLINE=LPG supply for homes and businesses.
VITE_CONTACT_PHONE=
VITE_CONTACT_EMAIL=
VITE_CONTACT_ADDRESS=
VITE_CONTACT_HOURS=
VITE_HERO_IMAGE=
VITE_ABOUT_IMAGE=
VITE_PRIVACY_CONTACT=
VITE_LEGAL_VERSION=Draft 1
VITE_LEGAL_EFFECTIVE=To be set before launch
```

Rebuild after changing any `VITE_*` value. Do not put unverified trademarks,
certifications, prices, statistics or contact details in these variables.

## 6. First run

1. Open `http://lpg.local/` (or your chosen URL).
2. Confirm `GET /api/health` returns `{"ok":true,...}`.
3. Log in with the seeded owner account.
4. Change the bootstrap password when prompted.
5. Open **Branches** and set the real branch name, code, address, phone,
   operating hours, cutoff time, service area and max order quantity.
6. Open **Prices** / branch prices and replace the placeholder cylinder prices.
7. Create staff accounts assigned to their branch.
8. Record opening stock with **Stock adjustments** for each branch and size.
9. Create a test customer order, approve it, deliver it, and confirm the stock
   and report totals move correctly.
10. Delete or disable the demo data.

The placeholder prices in `database/schema.sql` are inherited from the old
prototype (`11kg` 950, `22kg` 1850, `50kg` 4100). They are **not** real prices.

## 7. Run the automated tests

The suite creates/drops a disposable database named `lpg_test`. It needs a
MySQL user with `CREATE DATABASE` and `DROP DATABASE` rights; the defaults in
`tests/config.test.php` use `root` with no password.

```powershell
C:\xampp\php\php.exe tests\run.php
```

Expected tail:

```
216 passed, 0 failed
All API tests passed.
```

The runner starts its own PHP server on the first free port from 8095, then
stops the server tree on Windows so no `php -S` process is left behind.

To use an already-running server:

```powershell
$env:TEST_BASE_URL = 'http://127.0.0.1:8080'
C:\xampp\php\php.exe tests\run.php
```

When `TEST_BASE_URL` is set, point it at a server using
`tests/config.test.php`, **not** the production database.

## 8. Development mode

The Vite dev server proxies `/api` to the PHP backend:

```powershell
cd frontend
npm run dev
```

Default proxy target: `http://localhost` (port 80). Override it with
`VITE_API_PROXY` if Apache/PHP listens elsewhere:

```powershell
$env:VITE_API_PROXY = 'http://127.0.0.1:8080'
npm run dev
```

Open <http://localhost:5173>. The production build is not involved in dev mode.

## 9. Upgrading an existing single-branch database

```powershell
# 1. Back up first (migrate.php also takes a dump automatically).
C:\xampp\php\php.exe database\migrate.php --dry-run
C:\xampp\php\php.exe database\migrate.php
C:\xampp\php\php.exe database\seed.php
```

What the migration does:

- Creates `branches`, `branch_prices`, `stock_locks`, `schema_migrations` and
  branch columns on existing tables.
- Assigns all existing sales, orders, deliveries, payments, adjustments,
  transfers and staff to **Main Branch (`MAIN`)** so historical totals do not
  change.
- Creates a stock-lock row per branch/size.
- Adds an optional customer home branch (`002_customer_home_branch`) and
  backfills it from each customer's earliest sale, order or payment.
- Marks each migration as applied so it will not run twice.

`migrate.php` writes a `mysqldump` backup to `database/backups/` before
applying anything. If `mysqldump` is not on `PATH`, set `db.mysqldump_path` in
`api/config.php` or take your own backup and use `--no-backup`.

## 10. Troubleshooting

| Symptom | Likely cause / fix |
|---------|--------------------|
| `API configuration file is missing` | Copy `api/config.example.php` to `api/config.php`. |
| `Database connection failed` | Check credentials, port, and that MySQL is running. |
| `/api/health` returns 404 | `mod_rewrite` or `AllowOverride All` is not enabled, or the project is in a subdirectory with the wrong base path. |
| `/api/health` returns an empty 500 | Check `api/logs/error.log`; usually a missing config value or DB error. |
| Browser gets 419 on every save | Stale CSRF token. Refresh the page; the client retries once automatically. |
| Login returns 403 `password_change_required` | The account must change its password first. Complete the change form. |
| Staff cannot find a customer | Staff see only customers whose home branch is theirs or who have a sale/order/payment in their branch. The owner can reassign the home branch. |
| Page is blank after build | Assets are referenced from `/assets`; deploy at the site root, or set Vite `base` and rebuild. |
| `npm install` / build fails on Vite | Use the pinned `@vitejs/plugin-react` 6.x with Vite 8.x. Do not downgrade only one of them. |
| Session lost on every request | The system temp directory (`lpg_sessions`) is not writable, or a reverse proxy is stripping cookies. |
| Times are eight hours off | Keep PHP `timezone` at `Asia/Manila`; the PDO connection sets MySQL to `+08:00`. |
| CSV opens with odd characters in Excel | The export includes a UTF-8 BOM; use Data → From Text if Excel still guesses the encoding. |
| Tests cannot create `lpg_test` | Grant the test DB user `CREATE`/`DROP`; edit `tests/config.test.php`. |
| A test server stays alive on Windows | The runner kills the process tree with `taskkill /F /T`; check for an older runner or stop the stray `php.exe`. |

## 11. Local reset

This destroys all data in the configured database. Use only on a development
machine.

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "DROP DATABASE IF EXISTS lpg_db;"
C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
C:\xampp\php\php.exe database\seed.php
```
