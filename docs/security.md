# Security

This is the security model for the PHP port. It describes what is implemented,
what is deliberately left to the operator, and what is still a known gap.

Security is not a one-time checklist. Re-test after every change, especially
changes to authentication, branch scoping, stock logic or the web-server config.

## 1. Threat model

Primary risks for an LPG distributor system:

- unauthorised access to customer, staff or financial data;
- tampering with stock, prices, payments or branch records;
- one branch reading or changing another branch's data;
- stolen credentials or session hijacking;
- brute-force and credential-stuffing attacks;
- CSRF from a malicious site;
- SQL injection and cross-site scripting;
- exposure of backups, config, source or logs;
- accidental deletion of records that must be kept for audit;
- insider misuse of owner/staff privileges.

The design assumes the database and PHP host are trusted, the web server
terminates TLS correctly, and the operator keeps the OS, PHP, MySQL and Apache
patched.

## 2. Authentication

### Passwords
- Stored with PHP `password_hash()` using `PASSWORD_DEFAULT` (currently bcrypt).
- Verified with `password_verify()`.
- Automatically rehashed on successful login when the algorithm or cost
  changes.
- Minimum length `security.password_min_length` (default 8, configurable).
- Maximum 200 characters.
- New password must differ from the current password.
- No password is ever returned by the API.
- Bootstrap and owner-created accounts have `must_change_password=1` and are
  blocked from all endpoints except `/api/me`, `/api/me/password`,
  `/api/logout`, `/api/csrf` and `/api/health` until they change it.
- Owner-assisted reset generates a random one-time temporary password, sets
  `must_change_password=1` and bumps `session_version`.

### Login
- Username lookup is prepared and parameterised.
- A dummy bcrypt hash is verified for unknown usernames so timing is similar.
- Inactive users, inactive staff branches and disabled customer accounts are
  rejected.
- Successful login regenerates the session ID and rotates the CSRF token.
- `last_login_at` is recorded.
- Login success and failure are audited/rate-limit-recorded.

### Rate limiting
- Per username + IP: `security.login_max_attempts` (default 5) inside
  `login_window_minutes` (default 15).
- Per username across all IPs: `security.login_max_user_attempts` (default 10).
  This prevents IP rotation from brute-forcing one account.
- Per IP: `security.ip_max_attempts` (default 30).
- Signup: `security.signup_max_attempts` (default 5) per IP; **successful
  signups count too**, so an attacker cannot create unlimited accounts.
- OTP request endpoint has its own per-IP cap.
- Exceeding a limit returns HTTP `429` with `Retry-After`.
- Old `login_attempts` rows are deleted opportunistically after 7 days.

### Not implemented
- Two-factor authentication.
- Self-service “forgot password” by email/SMS. The owner resets passwords.
- CAPTCHA or proof-of-work.
- Breached-password checks against an external corpus.
- Password complexity rules beyond minimum length.

## 3. Sessions

- Native PHP file sessions, stored in `<system temp>/lpg_sessions`, outside the
  web root.
- `session.use_strict_mode=1`, `use_only_cookies=1`, HttpOnly cookies.
- `SameSite=Lax` by default; configurable.
- `Secure` automatically on HTTPS, or forced with `session.secure=true` behind
  a TLS-terminating proxy.
- Session ID length 48 with 5 bits per character.
- ID regenerated on login and on password change.
- Idle timeout `session.lifetime` (default 2 hours).
- Absolute cap `4 × session.lifetime` (default 8 hours).
- Destroyed on logout.
- The user row is re-read from the database on every request. Deactivating a
  user, changing their role, deactivating their branch, disabling their
  customer record or bumping `session_version` invalidates access immediately.
- Password changes bump `session_version`, which invalidates every other
  session for that user.

**Limitation:** file sessions are local to one server. Multiple app servers
need shared session storage or sticky sessions.

## 4. CSRF

- Every non-GET/HEAD/OPTIONS request requires `X-CSRF-Token` matching the
  session token, including login and signup.
- Comparison uses `hash_equals()`.
- Missing/invalid token returns HTTP `419`, code `csrf_failed`.
- Tokens are rotated on login and password change.
- The frontend fetches `/api/csrf`, sends the header, and retries once on `419`
  after refreshing the token.
- Same-origin policy is assumed; the API does not enable CORS.

## 5. SQL injection and data access

- All database access goes through PDO with
  `PDO::ATTR_EMULATE_PREPARES => false` (native server-side prepares).
- User input is bound as parameters; no input is concatenated into SQL.
- Dynamic `ORDER BY`, table and column names are not taken from user input.
- Pagination uses bound `LIMIT`/`OFFSET`.
- All queries run as a single application database user. Use a
  least-privilege account in production.

## 6. Input validation and output

- JSON-only write bodies; other content types return `415`.
- Malformed JSON returns `400`, code `invalid_json`.
- Request body limit `security.max_body_bytes` (default 1 MiB).
- Server-side validation for strings, lengths, patterns, enums, integers,
  decimals, dates, IDs and quantities. The frontend validation is convenience
  only; the API is authoritative.
- Numeric stock quantities require whole numbers and are range-checked.
- Dates are validated as real `YYYY-MM-DD` values.
- Output is JSON encoded by PHP; the SPA renders text through React, which
  escapes by default. Avoid `dangerouslySetInnerHTML` in future code.
- CSP restricts scripts to same-origin (`script-src 'self'`), blocks framing,
  object embedding and form posts to other origins.
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy` and `Cross-Origin-Resource-Policy: same-origin`.
- HSTS is sent when HTTPS is detected.

**CSP caveat:** `style-src` allows `'unsafe-inline'` and Google Fonts because
framer-motion and the site font stack need them. Tighten this if you remove
those dependencies or self-host fonts.

## 7. Authorisation and branch scoping

Roles:

- `owner` — all branches, all administrative functions.
- `staff` — exactly one branch, operational functions for that branch.
- `customer` — own account, own orders, own payments.

Enforcement:

- Route handlers call `require_owner()`, `require_staff_or_owner()` or
  `require_customer()`.
- `branch_scope()` resolves the branch:
  - staff: always `users.branch_id` from the database; **client headers are
    ignored**;
  - owner: `X-Branch-Id` header or `?branch_id=`; `all` is allowed for reads;
  - customer: no branch scope (customer endpoints use `customer_id`).
- Owner writes require one concrete **active** branch. Missing branch returns
  `400 branch_required`; inactive returns `409 branch_inactive`.
- Cross-branch order/transfer actions use `require_branch_access()` and return
  `403 branch_forbidden`.
- Customer order and payment queries filter by the customer's own ID.
- Staff cannot list users, create branches, or read the audit trail.
- Customers cannot read the sales register, users, reports or audit trail.
- Customer records are branch-scoped for staff: an update or deactivation is
  rejected with `403 branch_forbidden` unless the customer's home branch is the
  staff member's branch, or the customer has a sale, order or payment in that
  branch. Only the owner may reassign `customers.branch_id`.

Tests cover the important combinations: staff sending a different branch
header, owner `all` vs one branch, cross-branch order approval, wrong-branch
customer edits and deactivation, wrong-branch transfer receipt, and customer
data isolation.

## 8. Stock and financial integrity

- Stock is a ledger: every change writes a `stock_movements` row. The reported
  on-hand quantity is derived from the ledger.
- The affected `stock_locks` row is locked with `SELECT ... FOR UPDATE` inside
  the same transaction as the stock change. Concurrent sales/transfers cannot
  both consume the same last cylinder.
- Stock cannot go negative; overselling returns `409 insufficient_stock` and
  rolls back.
- Sales, deliveries, payments and adjustments are **voided/reversed**, not
  hard-deleted. Voids require a reason and write a reversal ledger row.
- Order prices are locked at order creation. Changing branch prices later does
  not rewrite pending or delivered order totals.
- Payments are validated against the customer's outstanding balance:
  overpayment returns `422 payment_too_large`; zero balance returns
  `409 no_balance`.
- Transfers use a state machine: `requested → approved → in_transit → received`
  or `cancelled` before shipping. Reposting a create with the same
  `idempotency_key` returns the existing transfer instead of duplicating stock.
- Branch deactivation is blocked when the branch has open orders/transfers,
  nonzero stock, active staff, or is the last active branch.
- Audit entries are written for privileged and financial actions: logins,
  logouts, registrations, password changes/resets, branch and price changes,
  users, customers, sales, voids, payments, deliveries, adjustments, orders and
  transfers.

## 9. Error handling and logging

- Production never displays PHP errors to the client.
- Unhandled exceptions log to `api/logs/error.log` and return a generic JSON
  `500 server_error`.
- `config.debug=true` adds exception detail to `500` responses. **Do not enable
  this in production.**
- Database errors are logged with file/line, but the client sees only
  “A database error occurred.”
- Audit writes are best-effort **after** the business transaction commits. If
  the audit insert fails, the error is logged but the successful transaction is
  not turned into an HTTP 500. This avoids losing a completed sale because of a
  logging failure, but it means audit writes are not guaranteed in a crash.
  Monitor the error log.

## 10. File and deployment exposure

The root `.htaccess` blocks `database/`, `legacy/`, `tests/`, `docs/` and
dangerous extensions (`.sql`, `.log`, `.md`, `.env`, `.ini`, `.bak`, `.dump`).
The API `.htaccess` blocks `api/src/`, `api/logs/`, `api/config*.php` and
dotfiles. `Options -Indexes` is set.

If the host ignores `.htaccess`, these rules do not apply. Reproduce them in
the VirtualHost and verify with direct requests:

```
/database/schema.sql
/tests/config.test.php
/docs/unverified-decisions.md
/api/config.php
/api/src/db.php
/api/logs/error.log
```

All should return `403` or `404`, never the file contents.

## 11. Privacy and data protection

- The system stores names, usernames, mobile numbers, delivery addresses,
  order history, payments, balances and empties owed.
- Access is role- and branch-scoped; customers only see their own records.
- Passwords are hashed; OTP and reset tokens are hashed with a server secret.
- The audit trail records who changed what and when.
- Data is not encrypted at rest by the application. Use full-disk/volume
  encryption and encrypted backups at the infrastructure level.
- Retention, lawful basis, DPO details and data-subject request handling must be
  decided by the business and documented in the Privacy Policy. See
  [privacy-terms.md](privacy-terms.md).
- Philippine law: RA 10173 (Data Privacy Act of 2012), its IRR, and NPC
  issuance apply. This document is not legal advice.

## 12. Known gaps and recommendations

| Gap | Recommendation |
|-----|----------------|
| OTP/SMS not implemented | Wire a reputable SMS provider into `send_otp()`, add delivery monitoring, and test before enabling `otp.provider`. |
| No in-app/email/SMS notifications | Add an outbox/notification table and a queue; do not send synchronously in the request. |
| Owner-assisted password reset only | Add a rate-limited, expiring, single-use reset flow if customers need self-service. |
| No 2FA | Add TOTP for owner accounts first. |
| No file upload | `proof_of_delivery` is a text reference only. If uploads are added, validate type/size, store outside the web root, generate random names, and serve through an authorised handler. |
| Audit is best-effort after commit | Use an outbox or write audit inside the transaction if regulatory auditability requires strict guarantees. |
| File sessions are per-server | Use Redis/database sessions or sticky sessions for multiple app servers. |
| No rate limit on every write endpoint | Add per-user/IP throttles for orders and payments if abuse appears. |
| No WAF / bot protection | Put the app behind a managed WAF or reverse proxy with rate limits. |
| No dependency scanning in CI | Add `composer audit` (if Composer is added), `npm audit`, and secret scanning. |
| CSP allows inline styles | Remove framer-motion or self-host fonts and tighten `style-src` when practical. |
| PII not application-encrypted | Use disk/volume encryption, encrypted backups, and strict DB access. |
| Tested on PHP 8.0.30 here | Run the suite on PHP 8.1/8.2/8.3 on staging/production. |
| No external penetration test | Commission one before handling real customer and payment data. |

## 13. Operational security

- Use HTTPS everywhere and redirect HTTP.
- Keep `api/config.php` out of version control and, ideally, outside the web
  root.
- Use a unique, long random `session_secret`; rotate it if leaked.
- Use a least-privilege database user; do not run as `root`.
- Patch PHP, MySQL/MariaDB, Apache and the OS promptly.
- Disable `expose_php`; keep `display_errors=Off`.
- Restrict access to `api/logs/` and `database/backups/`.
- Encrypt and test backups; define RPO/RTO.
- Review the audit trail after incidents and periodically.
- Deactivate staff who leave; deactivating a user immediately invalidates their
  session.
- Do not share owner accounts. Create a separate staff account per person.
- Never put real credentials, customer data or database dumps in the repository.

## 14. Reporting a vulnerability

There is no public security contact configured yet. Before launch, set a
monitored security email (for example `security@your-domain`) and publish it in
`SECURITY.md` or the site footer. Until then, report issues privately to the
system owner; do not open a public issue with exploit details.

## 15. Test coverage

`php tests/run.php` exercises 216 HTTP/database checks, including:

- security headers, CSRF, JSON content type and malformed body handling;
- forced first-login password change and session invalidation;
- role and branch authorisation, including header spoofing attempts;
- customer home-branch scoping for list, update and deactivation;
- stock guards, oversell rejection and void reversal;
- price locking and branch price overrides;
- multi-item orders, pending edits, cancellation and quantity limits;
- payments, overpayment and zero-balance rejection;
- transfer state machine, cross-branch receipt and idempotency;
- branch deactivation guards;
- reports, CSV encoding and branch comparison;
- rate limiting and session isolation.

It does **not** replace a browser security review, load test, concurrency soak
test, or external penetration test.
