# LPG Distributor System (PHP + React)

A production-oriented rewrite of the original Node/Express LPG stock-book and
customer-ordering system. The runtime is **PHP 8.1+ with MySQL/MariaDB**; the
React site is built once and served from the same origin as the API.

> **Status: feature-complete port, not launch-ready.** The API, database,
> frontend and automated test suite are working. Branding, prices, contacts,
> branch details, SMS/OTP, hosting and the legal documents are still
> placeholders. See [docs/unverified-decisions.md](docs/unverified-decisions.md)
> before going live.

## Stack

| Layer    | Technology |
|----------|------------|
| API      | PHP 8.1+, PDO native prepares, session auth |
| Database | MySQL 8 / MariaDB 10.4+, InnoDB, `utf8mb4` |
| Frontend | React 18, Vite 8, React Router |
| Server   | Apache + `mod_rewrite` / `mod_headers` (XAMPP supported) |
| Tests    | PHP CLI + curl end-to-end suite (`tests/run.php`) |

No Node.js is required at runtime. Node is only needed to build the frontend.

## Roles

- **Owner** — all branches, branch switcher, prices, staff, reports, transfers,
  audit trail, stock adjustments.
- **Staff** — exactly one branch, assigned by the owner. The branch is always
  read from the database; a client-supplied branch header is ignored. Staff
  verify submitted GCash/Maya payment references for their own branch.
- **Customer** — public signup, order from any active branch, see order history,
  payment status and empties owed.

## Features

### Public website
- Configurable brand, owner name and contact details (no hard-coded trademark).
- Live “Our Branches” section fed by `GET /api/branches/public`.
- Product, application, safety, sustainability and FAQ content.
- Privacy Policy and Terms pages with an explicit lawyer-review warning.

### Customer
- Signup and login; forced password change for owner-created accounts.
- Multi-item orders with branch, cylinder size, quantity, date, time slot,
  delivery address, valve type, notes and deposit.
- Prepaid payment choice: cash on delivery (no reference needed; marked paid
  when the rider delivers) or GCash/Maya with a 4–80 character reference number
  that staff must verify.
- Pending-order editing (including resubmitting a rejected reference) and
  cancellation; per-branch quantity limits and cutoff.
- Locked order prices: later branch price changes do not rewrite past orders.
- Account view: profile, order history with payment status, and empties owed.

### Staff / owner operations
- Dashboard with branch-aware totals.
- Multi-item direct sales with cash/GCash/Maya, optional customer, deposit
  handling and receipts.
- Plant deliveries and stock adjustments; all stock is ledger-backed.
- Manual payment verification: verify or reject a submitted GCash/Maya order
  reference, with audit trail; duplicate references are rejected across orders
  and sales.
- Order approval, rider assignment and delivery (creates a sale); GCash/Maya
  orders cannot be approved or delivered until verified.
- Inter-branch transfers: request → approve → ship → receive, with idempotency.
- Void/reversal instead of hard deletes for financial and stock records.
- Per-branch price lists, cylinder deposits and branch GCash/Maya account
  details.
- Reports: daily, sales register (filter by payment method), empties, stock and
  branch comparison. Every report supports `?format=csv` (UTF-8 BOM).
- Owner audit trail and user administration, including assisted password reset.

### Multi-branch model
- Branch-specific selling prices; global prices are a fallback.
- Customer records have an optional home branch; staff can manage only
  customers linked to their branch by home branch, sale or order.
- Stock is tracked per branch and per cylinder size.
- Staff belong to exactly one branch.
- The owner reads either one branch (`X-Branch-Id: <id>`) or all branches
  (`X-Branch-Id: all`); owner writes require one concrete active branch.
- Opening stock, transfers and deliveries are all branch-scoped.

## Quick start (XAMPP / Windows)

1. **Start Apache and MySQL** in the XAMPP control panel.
2. **Create the database** (the schema creates `lpg_db` itself):
   ```powershell
   C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
   ```
3. **Configure the API.** Copy `api/config.example.php` to `api/config.php` and
   set the database credentials. Generate a session secret:
   ```powershell
   C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```
   Paste it into `session_secret`. Never commit `api/config.php`.
4. **Seed the first owner**:
   ```powershell
   C:\xampp\php\php.exe database\seed.php
   ```
   The bootstrap account must change its password on first login. Change
   `bootstrap.admin_password` before seeding a real installation.
5. **Build the frontend**:
   ```powershell
   cd FRONTEND
   npm install
   npm run build
   cd ..
   ```
   The build writes `index.html` and `assets/` to the project root, next to
   `/api`, because the SPA and API must share an origin.
6. **Serve the project root** with Apache (a VirtualHost whose `DocumentRoot`
   is this folder), or preview with PHP's built-in server:
   ```powershell
   C:\xampp\php\php.exe -S 127.0.0.1:8080 -t . tests\router.php
   ```
   Open <http://127.0.0.1:8080/>. Check <http://127.0.0.1:8080/api/health>.

> The built SPA uses absolute `/assets/...` URLs. Deploy it at the **domain or
> VirtualHost root**, not in an arbitrary subdirectory. For a subdirectory
> install, change `base` in `FRONTEND/vite.config.js` and the API base in
> `FRONTEND/src/api.js`, then rebuild.

### Development frontend

Run Apache/PHP on port 80 (or configure `VITE_API_PROXY`), then:

```powershell
cd FRONTEND
npm run dev
```

Vite serves on <http://localhost:5173> and proxies `/api` to the PHP backend.
See [docs/setup.md](docs/setup.md) for details and troubleshooting.

## Upgrade an existing single-branch database

Back up first. `migrate.php` runs `mysqldump` automatically unless
`--no-backup` is passed.

```powershell
C:\xampp\php\php.exe database\migrate.php --dry-run
C:\xampp\php\php.exe database\migrate.php
C:\xampp\php\php.exe database\seed.php
```

Migration `001_add_branches` assigns all existing rows to **Main Branch**
(`MAIN`) so historical totals do not change. Migration
`002_customer_home_branch` adds an optional customer home branch and backfills
it from each customer's earliest sale or order. Migration
`005_prepaid_payments` removes the credit/reseller fields, adds payment-method
and verification fields, and **refuses to run while any non-void legacy credit
sale still has an outstanding amount** — settle those first. Migrations 006 and
007 cover Phase 2 hardening and Phase 3 hygiene; see [CHANGELOG.md](CHANGELOG.md).
See [docs/deployment.md](docs/deployment.md) for rollback steps.

## Tests

```powershell
C:\xampp\php\php.exe tests\run.php
```

The runner drops and recreates the disposable `lpg_test` database from
`database/schema.sql`, seeds one owner, starts its own PHP server, exercises the
HTTP API and database invariants, then stops the server. Current result:
**216 passed, 0 failed**.

Test credentials are created by the suite and are **not** production accounts:
`admin / OwnerPass123!`, `staffmain / StaffFinal123!`,
`custtest / Customer123!`.

## Folder layout

```
api/                 PHP front controller, auth, handlers, config
  index.php          router + middleware + security headers
  config.example.php configuration template
  src/               db, helpers, auth, handlers/
database/            schema.sql, migrations/, seed.php, migrate.php
FRONTEND/            React source and Vite config
tests/               end-to-end runner, test config, dev router
docs/                setup, deployment, security, legal and decision docs
legacy/node-backend/ copy of the original Express API, for reference only
index.html assets/   built SPA output (generated by `npm run build`)
```

> Windows note: NTFS is case-insensitive, but the tracked React source folder is
> `FRONTEND/`. Use `FRONTEND/` in commands and paths; there is no separate
> lowercase copy.

Dotfiles, `FRONTEND/`, `legacy/`, `node_modules`, `database/`, `tests/` and
`docs/` are blocked from HTTP access by the root `.htaccess`, along with
dangerous extensions. Do not rely on that alone: host them outside the web root
where possible.

## Documentation

- [Setup](docs/setup.md)
- [Configuration](docs/configuration.md)
- [Deployment](docs/deployment.md)
- [Security](docs/security.md)
- [API overview](docs/api.md)
- [Privacy and Terms](docs/privacy-terms.md)
- [Claims and sources](docs/claims-and-sources.md)
- [Unverified items and decisions](docs/unverified-decisions.md)
- [Changelog](CHANGELOG.md)

## Security highlights

- PDO with `ATTR_EMULATE_PREPARES => false`; no user input concatenated into SQL.
- `password_hash` / `password_verify`, automatic rehash, forced first-login
  password change, owner-assisted reset with one-time temporary password.
- Strict session cookies (HttpOnly, SameSite, Secure on HTTPS), session
  regeneration, idle and absolute timeouts, per-request DB re-check and
  `session_version` invalidation.
- CSRF token required on every state-changing request, including login/signup.
- Login and signup rate limiting per username+IP, per account and per IP.
- Server-side validation, JSON-only write bodies, body-size limit.
- CSP, HSTS on HTTPS, `X-Frame-Options: DENY`, `nosniff`, Referrer and
  Permissions policies.
- Stock writes use transactions plus `SELECT ... FOR UPDATE` row locks; negative
  stock is rejected. Financial records are voided/reversed, not deleted.
- Payment verification is branch-scoped: staff/owner verify or reject GCash/Maya
  order references in their branch, and duplicate references across orders and
  sales are rejected. The legacy payments table is read-only history.
- Customer records are branch-scoped for staff: they can list, edit and
  deactivate only home-branch customers or customers with a sale or order in
  their branch. Only the owner can reassign a customer's home branch.
- Audit trail for privileged and financial actions, including
  `verify_payment`/`reject_payment` (best-effort after commit).

See [docs/security.md](docs/security.md) for the full control list, known gaps
and operating procedures.

## Before going public

1. Replace all placeholder prices, cylinder deposits, branch details (including
   GCash/Maya account numbers and names and service-area cities) and max order
   quantities with real values.
2. Confirm written authorization for any third-party brand or logo, or keep the
   generic `LPG Distributor` branding.
3. Fill in contact details, social links and privacy contact.
4. Have a Philippine lawyer review the Privacy Policy and Terms; set version and
   effective date.
5. Decide on an SMS/OTP provider and implement `send_otp()` if OTP is required.
6. Use HTTPS, a least-privilege database user, a strong session secret, and move
   `api/config.php` outside the web root.
7. Configure backups, log rotation, monitoring and an incident-response contact.
8. Run `php tests/run.php` and an 8.1+ smoke test on the target host.

## Legacy code

`legacy/node-backend/` is the original Express implementation, preserved as a
historical reference for behaviour comparison only and blocked from HTTP access
by the root `.htaccess`. `BACKEND/` has been deleted; `FRONTEND/` is the live
React source. Do not deploy the legacy copy.
