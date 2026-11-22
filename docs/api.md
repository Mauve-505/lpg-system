# API overview

The API is a single PHP front controller at `api/index.php`, reached under
`/api/*`. It returns JSON except for report CSV downloads.

## Conventions

### Authentication
- Cookie session, created by `POST /api/login` or `POST /api/register`.
- Every state-changing request needs `X-CSRF-Token`. Fetch it from
  `GET /api/csrf` or read `csrf_token` from the login/register response.
- Protected endpoints return `401 unauthorized` without a session.
- Accounts with `must_change_password=1` receive `403 password_change_required`
  until they call `POST /api/me/password`.

### Branch scope
- Staff: the server always uses the staff member's `branch_id`; any
  `X-Branch-Id` header is ignored.
- Owner: send `X-Branch-Id: <id>` for one branch, or `X-Branch-Id: all` (or omit
  it) for combined reads.
- Owner writes require one concrete active branch. Missing returns
  `400 branch_required`; inactive returns `409 branch_inactive`.
- Customers do not use branch headers; their endpoints derive the customer ID
  from the session.

### Request format
- Write bodies must be JSON (`Content-Type: application/json`); other content
  types return `415 unsupported_media_type`.
- Malformed JSON returns `400 invalid_json`.
- Maximum body size is 1 MiB by default (`413 payload_too_large`).

### Pagination
List endpoints accept `?page=1&per_page=50&q=...`. `per_page` is capped at 100.
Responses come with:

```
X-Total-Count: 123
X-Page: 1
X-Per-Page: 50
X-Total-Pages: 3
```

### Errors
```json
{
  "error": "Human-readable message.",
  "code": "machine_code",
  "field": "optional_field"
}
```

Common codes: `validation_error`, `invalid_json`, `unsupported_media_type`,
`unauthorized`, `forbidden`, `password_change_required`, `csrf_failed`,
`rate_limited`, `not_found`, `duplicate`, `branch_required`,
`branch_inactive`, `branch_forbidden`, `insufficient_stock`, `already_void`,
`order_locked`, `order_limit`, `cutoff_passed`, `payment_not_verified`,
`duplicate_reference`, `payment_not_required`, `payment_not_submitted`,
`transfer_locked`, `server_error`.

### Dates and money
- Dates are `YYYY-MM-DD`; timestamps are `YYYY-MM-DD HH:MM:SS` in
  `Asia/Manila`.
- Money is numeric with two decimal places.
- Quantities are whole numbers.

## Routes

### Public / auth
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/health` | Health check. |
| GET | `/api/csrf` | Returns `csrf_token`. |
| POST | `/api/login` | `{username,password}`. |
| POST | `/api/logout` | Destroys the session. |
| POST | `/api/register` | Customer signup; logs in immediately. Fields: `name`, `username`, `contact`, `address`, `password`; `otp_code` only when OTP is enabled. |
| POST | `/api/auth/request-otp` | Starts phone verification (provider required for real SMS). |
| GET | `/api/branches/public` | Active branches for the marketing site. |
| GET | `/api/sizes` | Active sizes; optional `?branch_id=` for branch prices. |

### Session
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/me` | Current user + fresh CSRF token. |
| POST | `/api/me/password` | `{current_password,new_password}`. |

### Customer
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/my/account` | Profile fields plus `empties_owed`. |
| GET | `/api/my/orders` | Own orders. |
| POST | `/api/my/orders` | Create an order. |
| GET | `/api/my/orders/{id}` | Own order only. |
| PATCH/PUT | `/api/my/orders/{id}` | Edit a pending order, including a rejected payment reference. |
| POST | `/api/my/orders/{id}/cancel` | Cancel a pending order. |

`GET /api/my/account` returns the customer's profile (identifier, name,
contact and address fields) and `empties_owed`. It does **not** return a
balance, credit limit, reseller status or a list of payments.

Order create body:
```json
{
  "branch_id": 1,
  "pay": "gcash",
  "reference_no": "GC-2026-000142",
  "requested_date": "2026-11-10",
  "time_slot": "09:00-12:00",
  "delivery_address": "123 Example Street",
  "valve_type": "snap_on",
  "notes": "Leave at the gate",
  "items": [
    { "size_id": 1, "qty": 2, "deposit_amount": 0 }
  ]
}
```

- `pay` is required and must be one of `cash`, `gcash`, `maya`. There is no
  `credit` option.
- `reference_no` is required for `gcash` and `maya`: 4–80 characters,
  starting with a letter or digit and then letters, digits, `.`, `_`, `:` or
  `-`. It is not used for `cash` (cash on delivery).
- The branch must be active. The order is rejected if the requested date is in
  the past, the same-day cutoff has passed, or the total quantity exceeds the
  branch limit.
- Prices, deposits and any delivery fees are computed by the server from the
  branch catalogue; clients cannot set them. `deposit_amount` on a line item is
  informational — the server uses the configured cylinder deposit.

Pending-order editing accepts the same payment fields. Changing `pay` or
`reference_no` releases the old reference so the customer can resubmit a
corrected one after a rejection; a new GCash/Maya reference must be unique.
Only `pending` orders can be edited or cancelled.

### Dashboard
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/dashboard` | Staff: own branch. Owner: selected branch or all. |

### Customers
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/customers` | Staff/owner, branch-scoped. Returns `empties_owed`; no balance, credit or reseller fields. |
| POST | `/api/customers` | Create in the selected branch. |
| PUT | `/api/customers/{id}` | Update. Owner may also send `branch_id`. |
| DELETE | `/api/customers/{id}` | Deactivate (soft delete). |

- A customer created by staff or the owner stores the selected branch as its
  optional home branch (`branch_id`).
- Public signup customers start with `branch_id: null` and may order from any
  active branch.
- Staff can list, update and deactivate a customer only when it is their
  home-branch customer or it has a sale or order in their branch. Anything else
  returns `403 branch_forbidden`.
- Only the owner may change `branch_id`; a staff update keeps the existing home
  branch even if the request body includes one.
- Customer contact fields are shared across branches. Deactivation disables
  the customer's login globally.

### Sales
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/sales` | Branch-scoped. |
| POST | `/api/sales` | Create a direct sale (walk-in allowed). |
| GET | `/api/sales/{id}` | Detail. |
| GET | `/api/sales/{id}/receipt` | Receipt data. |
| DELETE | `/api/sales/{id}` | Void with `{reason}`; reverses stock. |

Sale create body:
```json
{
  "date": "2026-11-06",
  "pay": "maya",
  "reference_no": "MY-1042ABC",
  "customer_id": null,
  "items": [
    { "size_id": 1, "qty": 2, "back": 1, "price": null }
  ]
}
```

- `pay` is required and must be `cash`, `gcash` or `maya`. `reference_no` is
  optional for `cash` and required (4–80 characters, same format as orders) for
  `gcash`/`maya`. GCash/Maya references on sales are checked for duplicates
  across both sales and orders.
- `customer_id` is optional for **all** payment methods; direct sales do not
  require a customer.
- `back` is empties received; optional and defaults to 0.
- `price` is optional; the branch price is used when omitted. Prices and
  deposits are locked into `sale_items`, and the server computes totals.
- Overselling returns `409 insufficient_stock`.

### Orders (staff/owner)
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/orders` | Branch-scoped. |
| GET | `/api/orders/{id}` | Detail. |
| POST | `/api/orders/{id}/approve` | `pending → approved`; requires a verified payment for GCash/Maya. |
| POST | `/api/orders/{id}/verify-payment` | Staff/owner: mark a submitted GCash/Maya reference as verified. |
| POST | `/api/orders/{id}/reject-payment` | Staff/owner: reject it; optional `{reason}` (3–200 characters when supplied). |
| POST | `/api/orders/{id}/cancel` | Cancel an open order; optional `{reason}`. |
| POST | `/api/orders/{id}/deliver` | Creates a sale and moves stock; requires a verified payment for GCash/Maya. |
| POST | `/api/orders/{id}/assign` | `{rider_id}`; active staff/owner. |

Delivery body:
```json
{
  "items": [{ "size_id": 1, "empties_received": 1 }],
  "rider_id": 2,
  "proof_of_delivery": "POD-1042"
}
```

`proof_of_delivery` is a text reference; file uploads are not implemented.
Delivered orders keep the price locked at order creation.

#### Payment verification workflow

1. The customer submits an order with `pay: "cash"` (cash on delivery) or with
   `pay: "gcash"` / `pay: "maya"` plus `reference_no`. Cash orders need no
   verification; GCash/Maya orders start at
   `payment_status: "pending_verification"`.
2. Staff or the owner checks the payment against the branch's published
   GCash/Maya account and calls `POST /api/orders/{id}/verify-payment` (no body
   needed) or `POST /api/orders/{id}/reject-payment` with an optional `reason`.
   Verification is branch-scoped: staff can act only on orders in their own
   branch, and the owner must select one concrete active branch.
3. Verification sets `payment_status: "verified"` and records
   `payment_verified_by` / `payment_verified_at`. Rejection sets
   `payment_status: "rejected"` and clears the verifier fields; the reason is
   written to the audit trail. The customer can then edit the pending order
   with a new `reference_no` to resubmit it.
4. `approve` and `deliver` return `409 payment_not_verified` if a GCash/Maya
   order is not `verified` (including when it is `rejected`).

Verification calls on cash orders return `409 payment_not_required`; calls
when no reference has been submitted (or the status is not
`pending_verification`) return `409 payment_not_submitted`. A GCash/Maya
reference already used by another order or sale returns
`409 duplicate_reference`. The uniqueness check spans both orders and sales.

### Removed routes

The credit-era payment and unpaid-report routes no longer exist and have no
replacement record-payment flow:

- `GET /api/my/payments`
- `GET /api/payments`, `POST /api/payments`, `DELETE /api/payments/{id}`
- `GET /api/reports/unpaid`

The legacy `payments` table is retained only as read-only historical data; it
is not exposed through the API.

### Deliveries (plant)
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/deliveries` | Branch-scoped. |
| POST | `/api/deliveries` | Receive full/empty cylinders. |
| DELETE | `/api/deliveries/{id}` | Void with `{reason}`. |

```json
{
  "date": "2026-11-06",
  "receipt_no": "DR-1001",
  "supplier": "Supplier name",
  "remarks": "",
  "items": [{ "size_id": 1, "full": 20, "empties": 10 }]
}
```

### Adjustments / stock
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/adjustments` | Owner, branch-scoped. |
| POST | `/api/adjustments` | Opening count or correction. |
| DELETE | `/api/adjustments/{id}` | Void with `{reason}`. |

```json
{
  "date": "2026-11-06",
  "size_id": 1,
  "reason": "Opening count",
  "note": "",
  "full": 50,
  "empty": 10
}
```

### Sizes and branch prices
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/sizes/all` | Owner: all sizes, including inactive. |
| POST | `/api/sizes` | Create `{label, price, valve_type}`. |
| DELETE | `/api/sizes/{id}` | Deactivate. |
| GET | `/api/branches/{id}/prices` | Owner. |
| PUT | `/api/branches/{id}/prices` | `{prices:[{size_id,price}]}`. |

`valve_type`: `snap_on`, `pol`, `both`, `unspecified`.

### Users
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/users` | Owner. |
| POST | `/api/users` | Owner; staff require `branch_id`. |
| PUT | `/api/users/{id}` | Owner. |
| DELETE | `/api/users/{id}` | Deactivate. |
| POST | `/api/users/{id}/reset-password` | Returns a one-time temporary password. |

### Branches
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/branches` | Owner. |
| POST | `/api/branches` | Owner. |
| PUT | `/api/branches/{id}` | Owner. |
| POST | `/api/branches/{id}/deactivate` | Guarded. |
| POST | `/api/branches/{id}/activate` | Owner. |
| GET/PUT | `/api/branches/{id}/prices` | Owner. |

Branch records also hold the payment details customers are told to use:
`gcash_number`, `gcash_account_name`, `maya_number`, `maya_account_name`, plus
`service_area_cities`. Cylinder sizes carry `deposit_amount`. These are
maintained through the owner's branch/size screens, not through config files.

Deactivation is blocked when the branch has open orders/transfers, nonzero
stock, active staff, or is the last active branch.

### Transfers
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/transfers` | Owner all/source; staff source or destination. |
| POST | `/api/transfers` | Create with optional `idempotency_key`. |
| GET | `/api/transfers/{id}` | Detail. |
| POST | `/api/transfers/{id}/approve` | Requested → approved. |
| POST | `/api/transfers/{id}/ship` | Approved → in_transit; deducts source stock. |
| POST | `/api/transfers/{id}/receive` | In_transit → received; adds destination stock. |
| POST | `/api/transfers/{id}/cancel` | Before shipping only. |

```json
{
  "from_branch_id": 1,
  "to_branch_id": 2,
  "notes": "Rebalance stock",
  "idempotency_key": "transfer-2026-11-06-1",
  "items": [{ "size_id": 1, "qty_full": 2, "qty_empty": 1 }]
}
```

### Reports
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/reports/daily?from=&to=` | Daily sales split by payment method, deposits and stock movements. |
| GET | `/api/reports/sales?from=&to=&payment_method=` | Sales register; also `customer_id`, `q`. |
| GET | `/api/reports/empties` | Empties owed. |
| GET | `/api/reports/stock?branch_id=` | On-hand, out, per branch/size. |
| GET | `/api/reports/branch-comparison?from=&to=` | Owner only. |

Add `&format=csv` to any report for a UTF-8 CSV attachment with BOM.

`payment_method` is the filter on the sales register and accepts `cash`,
`gcash` or `maya`. Sale rows expose `payment_method` and `payment_reference`
instead of the removed `payment_type`.

- Daily rows contain `report_date`, `cash`, `gcash`, `maya`, `total_sales`,
  `deposits`, `voided_sales`, `full_received` and `empties_returned`. Totals
  mirror those fields. There are no credit-sales, payment-collection or unpaid
  columns.
- Branch-comparison rows contain `sales_total`, `cash`, `gcash`, `maya`,
  `deposits`, `full_qty`, `empty_qty`, `out_qty` and `open_orders`. Credit
  sales, payment records and unpaid amounts are no longer reported.

### Audit
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/audit` | Owner; filters `action`, `entity`, `user_id`, `branch_id`, `from`, `to`, `q`, pagination. |

Payment-verification actions appear as `verify_payment` and `reject_payment`
on the `order` entity.

## Status values

- Orders (fulfilment): `pending`, `approved`, `delivered`, `cancelled`.
- Orders (payment): `unpaid` (cash/COD), `pending_verification`,
  `verified`, `rejected`.
- Payment method: `cash`, `gcash`, `maya`. There is no `credit`, `bank` or
  `other` method.
- Transfers: `requested`, `approved`, `in_transit`, `received`, `cancelled`.
- Roles: `owner`, `staff`, `customer`.

## Legacy compatibility

The old JSON field names are preserved on the main resources (for example
`pay`, `back`, `full`, `empties`, `size`, `size_id`, `customer`, `sale_no`).
`pay` is the request alias for `payment_method`; responses expose
`payment_method`, `payment_reference` and, for orders, `payment_status` and its
verifier fields. The former `payment_type` field is not returned. Other
additive fields include `branch_id`, `branch_name`, `order_no`,
`requested_date`, `time_slot`, `delivery_address`, `valve_type`, `subtotal`,
`deposit_total`, `total_amount`, `items`, `empties_received` and
`deposit_amount`. Consumers should tolerate extra fields.
