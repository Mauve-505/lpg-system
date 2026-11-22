# Changelog

All notable changes to this project are recorded here.

## Unreleased / Phase 1 — Prepaid payments

> **Historical note:** the `[2.0.0]` and `[1.0.0]` entries below describe the
> original credit-enabled system. Credit/utang, customer balances, unpaid
> reports, the record-payment API and reseller flags mentioned there are **no
> longer active**; those entries are kept for history only.

### Added

- Manual verification workflow for GCash/Maya order payments:
  `POST /api/orders/{id}/verify-payment` and
  `POST /api/orders/{id}/reject-payment` (optional `reason`), with branch-scoped
  staff/owner access and `verify_payment` / `reject_payment` audit actions.
- 409 error codes `payment_not_verified`, `duplicate_reference`,
  `payment_not_required` and `payment_not_submitted`. Approve/deliver now
  return `payment_not_verified` until a GCash/Maya reference is verified.
- Unique reference registry spanning orders and sales so a GCash/Maya reference
  cannot be reused; rejected references can be released and resubmitted by the
  customer on a pending order.
- New schema fields: `orders.payment_method`, `payment_reference`,
  `payment_status`, `payment_verified_by`, `payment_verified_at`;
  `sales.payment_method`, `payment_reference`; branch `gcash_number`,
  `gcash_account_name`, `maya_number`, `maya_account_name`,
  `service_area_cities`; and `cylinder_sizes.deposit_amount`.

### Changed

- Payment methods are now **only** `cash` (cash on delivery), `gcash` and
  `maya`. GCash/Maya require a customer-supplied `reference_no` (4–80 chars)
  and staff verification; COD needs none.
- `GET /api/my/account` returns profile fields plus `empties_owed` only.
  Customer lists also return `empties_owed`, with no balance, credit or
  reseller fields.
- Direct sales accept `pay` in `{cash,gcash,maya}` with an optional
  `reference_no` (required for GCash/Maya) and no required customer.
- Reports use a `payment_method` filter instead of `payment_type`; daily and
  branch-comparison reports no longer show credit-sales, payments-collected or
  unpaid columns.
- Empties and cylinder deposits remain; prices, deposits and delivery fees are
  computed server-only.
- Frontend: prepaid order/sale forms, reference entry and resubmission, and
  staff verify/reject actions replace the balance, payment-entry, credit and
  reseller UI.

### Removed

- Routes: `GET /api/my/payments`, `GET`/`POST /api/payments`,
  `DELETE /api/payments/{id}` and `GET /api/reports/unpaid`.
- Fields: `customers.credit_limit`, `is_reseller`, `reseller_status`;
  `sales.payment_type`; `orders.payment_type`.
- Error codes `credit_limit`, `payment_too_large` and `no_balance`.
- The legacy `payments` table remains as read-only historical data; there is no
  record-payment flow.

### Migration

- New `005_prepaid_payments` migration applies the schema changes and refuses
  to run with a clear message if any non-void legacy credit sale still has an
  outstanding amount. Settle those amounts before upgrading. Migrations 006
  and 007 belong to later phases and are not part of this change.

### Documentation

- API, setup, deployment, configuration, security, privacy/Terms, claims and
  decision registers updated for prepaid payments and manual verification.

## [2.0.0] — 2026-11-06

Complete runtime port from Node/Express to PHP 8.1+ and MySQL/MariaDB, with a
rebuilt React frontend, multi-branch support, security hardening, reports and
end-to-end tests. The original Node implementation is retained under
`legacy/node-backend/` for reference only.

### Added

- PHP API front controller (`api/index.php`) with a single route table,
  centralised error handling, JSON/CSP/security headers and request limits.
- PDO data layer with native prepared statements and transaction helpers
  (`api/src/db.php`).
- Fresh schema `database/schema.sql` with `branches`, `branch_prices`,
  `stock_locks`, `schema_migrations`, item tables, payments, transfers, audit
  log, cylinder assets and login/OTP/reset tables.
- Migration `database/migrations/001_add_branches.sql` and rollback script;
  all legacy rows are assigned to `Main Branch (MAIN)`.
- Migration `database/migrations/002_customer_home_branch.sql` and rollback
  script; adds an optional customer home branch and backfills it from each
  customer's earliest sale, order or payment.
- Safe migration runner `database/migrate.php` with `--dry-run`, automatic
  `mysqldump` backup and `schema_migrations` tracking.
- Seed script `database/seed.php` with forced first-login password change and
  optional `--demo` customers.
- Multi-branch model:
  - per-branch prices with a global fallback;
  - optional customer home branch with staff list/update/deactivate scoping;
  - staff bound to exactly one branch;
  - owner branch switcher and `all` read scope;
  - owner writes require one concrete active branch;
  - branch-scoped dashboard, sales, orders, deliveries, payments,
    adjustments, transfers, reports and audit;
  - guarded branch deactivation.
- Inter-branch transfers with a
  `requested → approved → in_transit → received` state machine, idempotency
  keys, source/destination authorisation and stock reversal paths.
- Customer features: signup, login, account summary, multi-item ordering,
  pending-order editing, cancellation, per-branch quantity limits, same-day
  cutoff, time slots, delivery address, valve type, notes, deposits, balance,
  payments and empties owed.
- Staff/owner features: multi-item sales, cash/credit, deposits, receipts,
  plant deliveries, stock adjustments, customer payments, order approval,
  rider assignment, order delivery (creates a sale), voids and reversals.
- Reports: daily, sales register, unpaid, empties, stock and branch comparison;
  JSON and CSV (UTF-8 BOM, attachment) output.
- Owner audit trail browser.
- Owner user administration with one-time temporary password reset.
- React/Vite frontend rebuilt for the new API:
  - API client with CSRF, branch header, one automatic 419 retry and error
    handling;
  - role-aware app shell, branch switcher, sidebar and modals;
  - dashboard, orders, sales, deliveries, customers, payments, stock,
    transfers, reports, branches, users and audit pages;
  - customer shop and account views;
  - filters, pagination, line-item editors, void confirmations and receipts;
  - Filipino UI/chrome translation (`frontend/src/i18n.js`);
  - responsive, accessible states, skip links and focus styles.
- Marketing site with configurable brand, live “Our Branches” section,
  product/application/safety/sustainability/FAQ content, and Privacy/Terms
  pages.
- Legal templates with RA 10173 (Data Privacy Act) notes, NPC references,
  data-subject rights and a visible lawyer-review warning.
- Documentation: setup, configuration, deployment, security, API, privacy/Terms
  review guide, claims/sources register, unverified-decisions register.
- End-to-end test suite `tests/run.php` (216 HTTP/database checks) with a
  disposable `lpg_test` database and self-managed PHP server.

### Changed

- Frontend production build now writes `index.html` and `assets/` to the site
  root (`outDir: '..'`, `emptyOutDir: false`) so the SPA and `/api` share an
  origin. Vite prints a harmless parent-outDir warning. A small `postbuild`
  script (`frontend/scripts/prune-assets.mjs`) removes stale hashed bundles
  without touching source files.
- `@vitejs/plugin-react` upgraded to `^6.2.1` for Vite 8 compatibility.
- Brand defaults changed from a third-party petroleum brand to the generic
  `LPG Distributor`; owner defaults to `Your Company Name`.
- Marketing safety copy rewritten to avoid unverified “burns cleaner” and
  environmental-superiority claims.
- Contact details and social links render only when configured; no invented
  phone numbers, addresses or statistics.
- Session storage moved to `<system temp>/lpg_sessions`, outside the web root.
- Audit writes are best-effort after the business transaction commits, so a
  logging failure cannot turn a successful sale into HTTP 500.
- CSV reports now include a UTF-8 BOM for Excel compatibility.
- The API always resolves the route relative to the `/api` mount.

### Fixed

- CSRF and content-type failures were thrown outside the dispatcher try/catch
  and produced empty HTTP 500 responses; they now return JSON `419`/`415`.
- Optional sale `back`, delivery `full`/`empties`, transfer
  `qty_full`/`qty_empty` and order `empties_received` values defaulted to the
  integer `0` and failed strict whole-number checks; they now default to the
  float `0.0`.
- Signup rate limiting now counts successful signups, preventing unlimited
  account creation.
- Added a per-account login cap independent of IP.
- Dashboard unpaid query corrected.
- Users now carry and bump `session_version`, invalidating sessions on password
  change/reset and deactivation.
- Customer credit-limit updates are persisted and enforced per branch.
- Customer update and deactivation now enforce staff branch access instead of
  allowing any staff account to modify any customer by ID.
- Transfer numbers no longer collide under concurrent creation.
- Stock guards prevent negative stock under concurrent sales/transfers.
- Windows test runner now kills the whole `proc_open` process tree, so no
  stray `php -S` process holds the port or log file.
- Test server log names include the runner PID to avoid Windows file locks.

### Security

- Native prepared statements everywhere; no user input concatenated into SQL.
- Password hashing, rehash-on-login, forced first-login change and owner reset.
- Strict session cookies (HttpOnly, SameSite, Secure on HTTPS), regeneration,
  idle/absolute timeouts and per-request DB re-check.
- CSRF on every state-changing request, including login and signup.
- Login/signup rate limiting per username+IP, per account and per IP.
- JSON-only writes, body-size limit and server-side validation.
- CSP, HSTS on HTTPS, `X-Frame-Options: DENY`, `nosniff`, Referrer Policy,
  Permissions Policy and CORP.
- Root and API `.htaccess` rules block source, config, logs, tests, docs,
  backups and dotfiles.
- Financial/stock records are voided, not deleted; stock changes are
  transactional and row-locked.
- Customer management is branch-scoped for staff (home branch or a transaction
  in their branch); only the owner can reassign a customer's home branch.
- Audit trail for privileged and financial actions.

### Documentation

- New `README.md` and `docs/setup.md`, `docs/configuration.md`,
  `docs/deployment.md`, `docs/security.md`, `docs/api.md`,
  `docs/privacy-terms.md`, `docs/claims-and-sources.md`,
  `docs/unverified-decisions.md`.
- Legal pages explicitly flagged as unreviewed templates.
- Official-source register for safety/marketing claims.

### Known issues / not done

- The workspace only has PHP 8.0.30; the target is PHP 8.1+. Re-run the suite
  and smoke test on 8.1+.
- OTP/SMS is designed but not integrated; signup does not require an OTP while
  `otp.provider` is empty.
- No in-app, email or SMS notifications; no background queue.
- No self-service customer password reset (owner-assisted only).
- No two-factor authentication.
- No file uploads; `proof_of_delivery` is a text reference.
- Audit writes are best-effort after commit.
- No external penetration test, load test or accessibility audit.
- Brand, prices, branches, contacts, hosting and legal documents remain
  placeholders pending owner decisions.
- `BACKEND/` is the untouched original Express backend and
  `legacy/node-backend/` is a copy of it; do not deploy either. On
  case-insensitive Windows, `frontend/` and `FRONTEND/` are the **same**
  folder, not a duplicate pair.

## [1.0.0] — legacy

The original Node/Express + React prototype. Kept in `legacy/node-backend/` and
the original `BACKEND/`/`FRONTEND/` folders for behaviour comparison only.
