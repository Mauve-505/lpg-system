# Unverified items and open decisions

This file is the launch gate. An item marked **open** has not been confirmed by
the business owner and must not be treated as true in production.

Status values: **open**, **confirmed**, **not applicable**.

## 1. Launch blockers

| # | Item | Current state | Decision needed | Where it changes |
|---|------|---------------|-----------------|------------------|
| 1 | Brand name and logo | Generic `LPG Distributor` / `Your Company Name` | Confirm the real brand and whether a third-party mark is authorised in writing. Do not use PETRONAS branding without permission. | `frontend/.env.*`, `frontend/src/site/content.js` |
| 2 | Registered business name | Placeholder | Provide the exact legal name and entity type. | `VITE_COMPANY_NAME`, Privacy/Terms |
| 3 | Public contact details | Empty; hidden in the UI | Provide phone, email, address, hours and monitored privacy email. | `VITE_CONTACT_*`, `VITE_PRIVACY_CONTACT` |
| 4 | Branch list | One placeholder `Main Branch (MAIN)` | Provide every real branch: name, code, address, phone, hours, cutoff, service area, max order qty, active date. | **Branches** page |
| 5 | Cylinder catalogue | Placeholder `11kg / 22kg / 50kg` and prices 950 / 1850 / 4100 | Confirm real sizes, labels, valve types, global prices, deposits and availability by branch. | **Sizes** and branch prices |
| 6 | Privacy Policy and Terms | Templates with a visible review warning | Philippine lawyer review, real DPO/contact, version and effective date. | `Privacy.jsx`, `Terms.jsx`, `VITE_LEGAL_*` |
| 7 | Hosting, domain, HTTPS | Not decided | Choose host/region, DNS, TLS certificate, and whether PHP runs as Apache module or PHP-FPM. | Deployment |
| 8 | Production database | Local `lpg_db` with `root`/no password | Create a named database and least-privilege user with a strong password. | `api/config.php` |
| 9 | Session secret | Local generated value | Generate a new production value; never reuse the local one. | `api/config.php` |
| 10 | Bootstrap/demo accounts | Seeded owner and optional demo customers | Change the bootstrap password, delete/disable demo data, create real accounts. | Admin UI / DB |
| 11 | PHP version | Verified on PHP 8.0.30 in this workspace | Run the suite and smoke test on PHP 8.1+ (preferably 8.2/8.3). | Host |
| 12 | Backups | Script documented, not scheduled | Decide backup tool, location, encryption, retention, RPO/RTO, restore owner. | Operations |
| 13 | Marketing claims | Safety copy is generic | Review `docs/claims-and-sources.md`, open every source, sign off or remove claims. | Site content |
| 14 | OTP/SMS | Disabled by default | Decide whether signup/phone verification needs OTP. If yes, select a provider, implement `send_otp()`, test delivery and abuse controls. | `api/config.php`, `api/src/auth.php` |

## 2. Business policy decisions

| # | Item | Current implementation | Open question |
|---|------|------------------------|---------------|
| 15 | Cross-branch ordering | Customers may order from any active branch | Confirm this is the intended policy, including delivery coverage and whether prices differ. |
| 16 | Staff branch assignment | Exactly one branch per staff account | Confirm no staff member needs to work across branches; owner can see all. |
| 17 | Owner write branch | Owner must choose one active branch for writes; reads can be `all` | Confirm the workflow is acceptable. |
| 18 | Prices | Per-branch override with global fallback | Confirm who may change prices, how often, and whether changes need approval. Currently owner-only. |
| 19 | Credit limit | Optional per-customer limit, enforced per branch on credit sales/orders | Confirm limit values, whether limits are per branch or company-wide, and the approval process. |
| 20 | Credit terms | No due dates or statements; balance is tracked | Decide terms, collection process, statements and any lawful charges. |
| 21 | Payment methods | `cash`, `gcash`, `maya`, `bank`, `other` with optional reference | Confirm supported methods and whether proof/reference is mandatory. No online payment gateway is integrated. |
| 22 | Delivery fees | No delivery-fee field | Decide free vs charged delivery, and how a fee would be represented. |
| 23 | Time slots | Free-text slot per order | Define standard slots per branch and whether they are enforced. |
| 24 | Cutoff time | Optional per-branch same-day cutoff | Set real cutoff times and the customer-facing wording. |
| 25 | Max order quantity | Per-branch cylinder count | Set real limits and whether they differ by size or customer type. |
| 26 | Empties and deposits | `deposit_amount` per line and empties-owed calculation | Confirm deposit amounts, refund rules, lost/damaged cylinder charges and ownership. |
| 27 | Resellers | `is_reseller` / `reseller_status` flags and customer form | Confirm whether there is a real reseller program, approval workflow and pricing. The current feature is only a flag/status. |
| 28 | Customer cancellation | Pending orders only | Confirm whether approved orders can be cancelled by staff/owner and under what rules. |
| 29 | Void policy | Owner can void sales/payments/deliveries/adjustments with a reason | Confirm who may void, whether staff can void, and the review/approval process. Currently owner-only for many actions. |
| 30 | Rider assignment | Optional `rider_id` pointing at an active staff/owner | Confirm rider list, assignment rules and whether riders need their own role. |
| 31 | Proof of delivery | Text reference (`proof_of_delivery`) | Confirm whether photos/signatures are required; uploads are not implemented. |
| 32 | Notifications | Not implemented | Decide in-app, email, SMS or none for order status, approvals, deliveries and payments. |
| 33 | Customer password reset | Owner-assisted reset only | Decide whether customers need self-service reset and what identity proof is required. |
| 34 | Account deletion | Customers can be deactivated by staff; no self-service deletion | Define the data-subject deletion/anonymisation process and its effect on financial records. |

## 3. Legal and compliance

| # | Item | Status | Notes |
|---|------|--------|-------|
| 35 | Privacy Policy review | open | Must be reviewed by Philippine counsel; see `docs/privacy-terms.md`. |
| 36 | Terms review | open | Same. |
| 37 | DPO appointment and NPC registration | open | Determine whether registration is required for the organisation. |
| 38 | Lawful basis and processing inventory | open | Document per purpose: contract, legitimate interest, consent, legal obligation. |
| 39 | Retention schedule | open | Define periods for accounts, orders, payments, audit, login attempts and backups. The app keeps data indefinitely unless deactivated/deleted. |
| 40 | Data-subject request procedure | open | Define intake, identity verification, fulfilment and response deadlines. |
| 41 | Breach response plan | open | Define decision-maker, NPC notification, customer notification, evidence preservation. |
| 42 | Processor agreements | open | Hosting, SMS, payment, support and backup providers. |
| 43 | Cross-border transfer assessment | open | Depends on hosting region and providers. |
| 44 | Cookies/analytics | none currently | If analytics or ads are added, update the policy and add consent where required. |
| 45 | Consumer protection terms | open | Pricing, refunds, cancellations and complaints must comply with Philippine consumer law. |
| 46 | Fire/safety permits and local rules | open | Branch storage and delivery must comply with the local fire code and LGU rules. |
| 47 | Business permits, BIR receipts, official invoices | open | The system prints a simple receipt; confirm whether it meets BIR requirements. |
| 48 | Insurance and liability wording | open | Legal review of disclaimers and liability limits. |

## 4. Technical and operational decisions

| # | Item | Current state | Recommendation |
|---|------|---------------|----------------|
| 49 | PHP 8.1+ verification | Tested on 8.0.30 | Run `php tests/run.php` and the go-live checklist on the target version. |
| 50 | Session storage scaling | Native files in system temp | Use Redis/DB sessions or sticky sessions if more than one app server. |
| 51 | Log retention | `api/logs/error.log` grows until rotated | Configure logrotate or a scheduled cleanup; monitor disk. |
| 52 | Monitoring | Not configured | External uptime check on `/api/health`, 5xx/DB/backup alerts. |
| 53 | Backups | Documented only | Schedule, encrypt, test restore; decide RPO/RTO. |
| 54 | Email/SMS delivery | Not implemented | Add a queued outbox if notifications are required; do not block requests on a provider. |
| 55 | File uploads | Not implemented | If added, validate, randomise names, store outside web root, serve through an authorised handler. |
| 56 | Audit durability | Best-effort after commit | If strict auditability is required, write audit in the same transaction or use an outbox. |
| 57 | Error detail | `debug=false` in local config | Keep false in production; use `api/logs/error.log`. |
| 58 | Dependency updates | No CI | Add `npm audit`, secret scanning and a patch cadence. |
| 59 | Nginx | Not supported out of the box | Reproduce rewrite/deny/header rules and retest every route. |
| 60 | Subdirectory install | Not supported by default | Deploy at the root, or change Vite `base` and the API base, then rebuild. |
| 61 | Browser support | Modern evergreen browsers | Confirm any requirement for older Android/iOS browsers. |
| 62 | Accessibility | Basic skip links, labels and focus styles | Commission a WCAG 2.1 AA audit before launch. |
| 63 | Filipino language | Lightweight UI/chrome translation (`frontend/src/i18n.js`) | Decide whether full marketing/legal translation is needed. |
| 64 | Tausug or other local language | Not implemented | Confirm whether required for the target service area. |
| 65 | Timezone | `Asia/Manila`, MySQL `+08:00` | Confirm no branch operates in another timezone. |
| 66 | Legacy folders | `FRONTEND/`, `BACKEND/`, `legacy/node-backend/` retained | They were locked by a process during the port. Remove them from the deployment artifact once verified; never serve them. |
| 67 | Test data | `lpg_test` is disposable | Never point tests at production; the runner drops the configured test database. |
| 68 | Database name | `lpg_db` | Some hosts require a prefixed name; update schema/config together. |
| 69 | Shared customer records | Home branch plus transaction-based staff access; contact and credit fields are global | Confirm whether a customer may be shared across branches, and who may edit or deactivate a shared record. |

## 5. Decisions already made in this port

These are implementation decisions, recorded so they are not accidentally
reversed:

- PHP + PDO native prepares; no Node at runtime.
- Same-origin SPA at the site root, API under `/api`.
- Branch-specific prices with a global fallback.
- Customers may order from any active branch.
- Customers have an optional home branch. Staff may manage a customer only when
  it is their home-branch customer or has a sale, order or payment in their
  branch. Public signups start with no home branch. Customer contact/credit
  fields are global, so an allowed update is visible to every branch.
- Staff belong to exactly one branch; branch comes from the DB, never from the
  client.
- Owner reads may span all branches; owner writes require one concrete active
  branch.
- Stock is authoritative through `stock_movements`; `stock_locks` rows are
  locked with `SELECT ... FOR UPDATE`.
- Financial and stock records are voided/reversed, not hard-deleted.
- Order prices are locked at order creation.
- Transfers use a state machine and an idempotency key.
- Open balances and credit limits are tracked per branch.
- Sessions are file-based, strict, re-checked against the DB on every request.
- CSRF applies to login/signup too.
- Login is rate-limited per username+IP, per account and per IP.
- Bootstrap and owner-created accounts must change their password on first
  login.
- Audit writes are best-effort after commit (a logging failure must not turn a
  successful sale into a 500).
- Fresh schema marks `001_fresh_install`, `001_add_branches` and
  `002_customer_home_branch` as applied.
- Migration `001_add_branches` assigns all legacy rows to
  `Main Branch (MAIN)`.
- Migration `002_customer_home_branch` backfills a home branch from each
  customer's earliest sale, order or payment; customers with no activity stay
  unassigned until the owner sets one.
- The marketing brand defaults to a generic placeholder, with no assumed
  third-party authorisation.
- Legal pages are templates with a visible review warning.

## 6. Sign-off

| Area | Name | Date | Result |
|------|------|------|--------|
| Brand and logo authorisation | | | |
| Branch and price data | | | |
| Credit and deposit policy | | | |
| Hosting, domain, HTTPS | | | |
| Backups and monitoring | | | |
| Privacy Policy and Terms | | | |
| DPO/NPC and retention | | | |
| Marketing claims | | | |
| PHP 8.1+ verification | | | |
| Final go-live checklist | | | |

Until every launch blocker is `confirmed`, the system is a working prototype,
not a production service.
