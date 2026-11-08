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
`order_locked`, `order_limit`, `cutoff_passed`, `credit_limit`,
`payment_too_large`, `no_balance`, `transfer_locked`, `server_error`.

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
| GET | `/api/my/account` | Balance, credit limit, empties owed, per-branch balances. |
| GET | `/api/my/orders` | Own orders. |
| POST | `/api/my/orders` | Create an order. |
| GET | `/api/my/orders/{id}` | Own order only. |
| PATCH/PUT | `/api/my/orders/{id}` | Edit a pending order. |
| POST | `/api/my/orders/{id}/cancel` | Cancel a pending order. |
| GET | `/api/my/payments` | Own payments. |

Order create body:
```json
{
  "branch_id": 1,
  "pay": "cash",
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

`pay` is `cash` or `credit`. The branch must be active. The order is rejected
if the requested date is in the past, the same-day cutoff has passed, the total
quantity exceeds the branch limit, or a credit order would exceed the customer
limit.

### Dashboard
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/dashboard` | Staff: own branch. Owner: selected branch or all. |

### Customers
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/customers` | Staff/owner, branch-scoped. |
| POST | `/api/customers` | Create in the selected branch. |
| PUT | `/api/customers/{id}` | Update. Owner may also send `branch_id`. |
| DELETE | `/api/customers/{id}` | Deactivate (soft delete). |

- A customer created by staff or the owner stores the selected branch as its
  optional home branch (`branch_id`).
- Public signup customers start with `branch_id: null` and may order from any
  active branch.
- Staff can list, update and deactivate a customer only when it is their
  home-branch customer or it has a sale, order or payment in their branch.
  Anything else returns `403 branch_forbidden`.
- Only the owner may change `branch_id`; a staff update keeps the existing home
  branch even if the request body includes one.
- Customer contact and credit fields are shared across branches. Deactivation
  disables the customer's login globally.

### Sales
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/sales` | Branch-scoped. |
| POST | `/api/sales` | Create a direct sale. |
| GET | `/api/sales/{id}` | Detail. |
| GET | `/api/sales/{id}/receipt` | Receipt data. |
| DELETE | `/api/sales/{id}` | Void with `{reason}`; reverses stock. |

Sale create body:
```json
{
  "date": "2026-11-06",
  "pay": "cash",
  "customer_id": null,
  "items": [
    { "size_id": 1, "qty": 2, "back": 1, "price": null }
  ]
}
```

- `credit` requires `customer_id`.
- `back` is empties received; optional and defaults to 0.
- `price` is optional; the branch price is used when omitted. Prices are locked
  into `sale_items`.
- The server rejects overselling with `409 insufficient_stock`.

### Orders (staff/owner)
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/orders` | Branch-scoped. |
| GET | `/api/orders/{id}` | Detail. |
| POST | `/api/orders/{id}/approve` | `pending → approved`. |
| POST | `/api/orders/{id}/cancel` | Cancel an open order; optional `{reason}`. |
| POST | `/api/orders/{id}/deliver` | Creates a sale and moves stock. |
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

### Payments
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/payments` | Branch-scoped. |
| POST | `/api/payments` | Record a payment. |
| DELETE | `/api/payments/{id}` | Void with `{reason}`. |

```json
{
  "customer_id": 5,
  "date": "2026-11-06",
  "amount": 100.00,
  "method": "gcash",
  "reference_no": "GC-1001",
  "notes": ""
}
```

`method`: `cash`, `gcash`, `maya`, `bank`, `other`. Payments are capped at the
outstanding branch balance.

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
| GET | `/api/reports/daily?from=&to=` | Sales, cash collected, per branch. |
| GET | `/api/reports/sales?from=&to=` | Sales register; `payment_type`, `q`. |
| GET | `/api/reports/unpaid` | Customer balances. |
| GET | `/api/reports/empties` | Empties owed. |
| GET | `/api/reports/stock?branch_id=` | On-hand, out, per branch/size. |
| GET | `/api/reports/branch-comparison?from=&to=` | Owner only. |

Add `&format=csv` to any report for a UTF-8 CSV attachment with BOM.

### Audit
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/audit` | Owner; filters `action`, `entity`, `user_id`, `branch_id`, `from`, `to`, `q`, pagination. |

## Status values

- Orders: `pending`, `approved`, `delivered`, `cancelled`.
- Transfers: `requested`, `approved`, `in_transit`, `received`, `cancelled`.
- Payment type: `cash`, `credit`.
- Payment method: `cash`, `gcash`, `maya`, `bank`, `other`.
- Roles: `owner`, `staff`, `customer`.
- Reseller status: `none`, `pending`, `approved`, `rejected`.

## Legacy compatibility

The old JSON field names are preserved on the main resources (for example
`pay`, `back`, `full`, `empties`, `size`, `size_id`, `customer`, `sale_no`).
New fields are additive: `branch_id`, `branch_name`, `order_no`,
`requested_date`, `time_slot`, `delivery_address`, `valve_type`, `subtotal`,
`deposit_total`, `total_amount`, `items`, `empties_received`, `deposit_amount`.
Consumers should tolerate extra fields.
