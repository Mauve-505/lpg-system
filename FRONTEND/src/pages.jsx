import { useState } from 'react'
import { downloadCsv, query } from './api'
import {
  Badge, FilterBar, Loader, ErrorState, PageHeader, Pagination, Stat, Table,
  Acts, formatDate, formatDateTime, itemsQty, itemsSummary, monthStart, num, peso, today,
  useApi, useApp, usePagedApi,
} from './ui'
import {
  AdjustForm, BranchForm, ConfirmDelete, ConfirmPost, CustomerForm, DeliverForm, DeliveryForm,
  OrderForm, PaymentForm, PriceForm, ReasonPostForm, ReceiptModal, ResetPasswordForm, SaleForm,
  SizeForm, TransferForm, UserForm, useVoid,
} from './forms'

const dateFmt = formatDate
const dtFmt = formatDateTime

function TextFilter({ value, onChange, placeholder = 'Search…', label = 'Search' }) {
  return (
    <label>{label}
      <input type="search" value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder} />
    </label>
  )
}

function RangeFilter({ from, to, setFrom, setTo }) {
  return (
    <>
      <label>From<input type="date" value={from} onChange={e => setFrom(e.target.value)} /></label>
      <label>To<input type="date" value={to} onChange={e => setTo(e.target.value)} /></label>
    </>
  )
}

function ScopeNote() {
  const { me, activeBranch, branches } = useApp()
  if (me?.role !== 'owner' || activeBranch !== 'all') return null
  return (
    <p className="scope-note">
      Combined view of {branches.length} active branch{branches.length === 1 ? '' : 'es'}.
      Records show their branch; actions that create stock or money ask you to pick one branch.
    </p>
  )
}

function BranchCell({ row }) {
  return row.branch_name ? <span className="branch-tag">{row.branch_name}</span> : '—'
}

function branchColumn(me, activeBranch) {
  return me?.role === 'owner' && activeBranch === 'all'
}

/* -------------------------------------------------------------------------- */
/* Dashboard                                                                  */
/* -------------------------------------------------------------------------- */

export function Dashboard() {
  const { me, tr, activeBranch } = useApp()
  const [modal, setModal] = useState(null)
  const dash = useApi('dashboard')
  const latest = useApi('sales?per_page=6')
  const d = dash.data
  const showBranches = branchColumn(me, activeBranch) && Array.isArray(d?.branch?.branches)

  return (
    <>
      <PageHeader
        title={tr('nav.dashboard')}
        sub={`Today at a glance · ${today()}`}
        meta={<Badge t={d?.branch?.mode === 'all' ? 'active' : 'active'} label={d?.branch?.name || '—'} />}
      >
        <button className="ghost" onClick={() => setModal('delivery')}>Plant delivery</button>
        <button className="primary" onClick={() => setModal('sale')}>New sale</button>
      </PageHeader>

      <ScopeNote />

      {dash.loading && <Loader />}
      {dash.error && <ErrorState error={dash.error} onRetry={dash.reload} />}

      {d && (
        <>
          <div className="stats">
            <Stat label="Sold today" value={peso(d.sold)} hint="Posted sales, excluding voids" />
            <Stat label="Cash collected today" value={peso(d.cash)} hint="Cash sales plus credit payments" />
            <Stat label="Unpaid by customers" value={peso(d.unpaid)} tone={Number(d.unpaid) > 0 ? 'warn' : 'ok'} hint="Outstanding credit balance" />
            <Stat label="Open orders" value={num(d.open_orders)} hint="Pending or approved" />
            <Stat label="Empties with customers" value={num(d.empties_owed)} hint="Expected back from customers" />
          </div>

          <h2 className="sec">Stock on hand</h2>
          <div className="sizes">
            {(d.stock || []).map(s => {
              const f = Number(s.full_qty) || 0
              const e = Number(s.empty_qty) || 0
              const o = Number(s.out_qty) || 0
              const total = Math.max(1, Math.max(0, f) + Math.max(0, e) + Math.max(0, o))
              const w = v => (Math.max(0, v) / total) * 100 + '%'
              return (
                <div className="card size" key={s.id}>
                  <h3>{s.label} cylinder</h3>
                  <div className="big">{f}<span>full</span></div>
                  <div className="bar" aria-hidden="true">
                    <i style={{ width: w(f), background: 'var(--ac)' }} />
                    <i style={{ width: w(e), background: '#94A3B8' }} />
                    <i style={{ width: w(o), background: 'var(--wn)' }} />
                  </div>
                  <div className="leg">
                    <span>Empty <b>{e}</b></span>
                    <span>With customers <b>{o}</b></span>
                    <span>Total <b>{f + e + o}</b></span>
                  </div>
                </div>
              )
            })}
            {!d.stock?.length && <div className="card empty">No active cylinder sizes yet.</div>}
          </div>

          {showBranches && (
            <>
              <h2 className="sec">Branch snapshot</h2>
              <Table
                head={['Branch', { t: 'Sold today', r: true }, { t: 'Full cylinders', r: true }, 'Status']}
                empty="No branches yet."
                rows={d.branch.branches.map(b => (
                  <tr key={b.id}>
                    <td><b>{b.name}</b></td>
                    <td className="r">{peso(b.sold)}</td>
                    <td className="r">{num(b.full_qty)}</td>
                    <td><Badge t={Number(b.is_active) === 1 ? 'active' : 'inactive'} /></td>
                  </tr>
                ))}
              />
            </>
          )}

          <h2 className="sec">Latest sales</h2>
          <Table
            head={[
              'Date', ...(branchColumn(me, activeBranch) ? ['Branch'] : []), 'Customer', 'Items',
              { t: 'Qty', r: true }, { t: 'Total', r: true }, 'Payment', 'Status',
            ]}
            loading={latest.loading}
            error={latest.error}
            onRetry={latest.reload}
            empty="No sales yet. Record your first sale."
            rows={(latest.data || []).map(a => (
              <tr key={a.id}>
                <td>{dateFmt(a.sale_date)}</td>
                {branchColumn(me, activeBranch) ? <td><BranchCell row={a} /></td> : null}
                <td>{a.customer}</td>
                <td className="wrap">{itemsSummary(a.items)}</td>
                <td className="r">{itemsQty(a.items)}</td>
                <td className="r">{peso(a.total_amount)}</td>
                <td><Badge t={a.payment_type} /></td>
                <td>{Number(a.is_void) === 1 ? <Badge t="void" /> : <Badge t="delivered" label="Posted" />}</td>
              </tr>
            ))}
          />
        </>
      )}

      {modal === 'sale' && <SaleForm onClose={() => setModal(null)} />}
      {modal === 'delivery' && <DeliveryForm onClose={() => setModal(null)} />}
    </>
  )
}

/* -------------------------------------------------------------------------- */
/* Orders                                                                     */
/* -------------------------------------------------------------------------- */

const ORDER_STATUSES = ['pending', 'approved', 'delivered', 'cancelled']

export function Orders() {
  const { me, tr, activeBranch } = useApp()
  const [status, setStatus] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [q, setQ] = useState('')
  const [modal, setModal] = useState(null)
  const list = usePagedApi('orders' + query({ status, from, to, q }), { deps: [status, from, to, q] })
  const showBranch = branchColumn(me, activeBranch)

  const reset = () => { setStatus(''); setFrom(''); setTo(''); setQ('') }
  const close = () => setModal(null)

  return (
    <>
      <PageHeader title={tr('nav.orders')} sub="Customer requests, from confirmation to delivery" />
      <ScopeNote />

      <FilterBar onReset={reset}>
        <TextFilter value={q} onChange={setQ} placeholder="Order no., customer or address" />
        <label>Status
          <select value={status} onChange={e => setStatus(e.target.value)}>
            <option value="">All statuses</option>
            {ORDER_STATUSES.map(s => <option key={s} value={s}>{tr('status.' + s, s)}</option>)}
          </select>
        </label>
        <RangeFilter from={from} to={to} setFrom={setFrom} setTo={setTo} />
      </FilterBar>

      <Table
        head={[
          'Date', 'Order no.', ...(showBranch ? ['Branch'] : []), 'Customer', 'Items',
          { t: 'Total', r: true }, 'Requested', 'Payment', 'Status', 'Actions',
        ]}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={status || q ? 'No orders match these filters.' : 'No orders yet.'}
        rows={list.rows.map(o => (
          <tr key={o.id}>
            <td>{dateFmt(o.order_date)}</td>
            <td className="mono">{o.order_no || '—'}</td>
            {showBranch ? <td><BranchCell row={o} /></td> : null}
            <td><b>{o.customer}</b>{o.customer_contact ? <small className="sub">{o.customer_contact}</small> : null}</td>
            <td className="wrap">
              {itemsSummary(o.items)}
              {o.delivery_address ? <small className="sub">{o.delivery_address}</small> : null}
            </td>
            <td className="r">{peso(o.total_amount)}</td>
            <td>{dateFmt(o.requested_date)}{o.time_slot ? <small className="sub">{o.time_slot}</small> : null}</td>
            <td><Badge t={o.payment_type} /></td>
            <td><Badge t={o.status} /></td>
            <Acts>
              {o.status === 'pending' && (
                <button className="sm link-ac" onClick={() => setModal({ type: 'approve', order: o })}>Approve</button>
              )}
              {['pending', 'approved'].includes(o.status) && (
                <>
                  <button className="sm link-ac" onClick={() => setModal({ type: 'deliver', order: o })}>Deliver</button>
                  <button className="sm link-danger" onClick={() => setModal({ type: 'cancel', order: o })}>Cancel</button>
                </>
              )}
            </Acts>
          </tr>
        ))}
      />
      <Pagination page={list.page} pages={list.pages} total={list.total} onPage={list.setPage} />

      {modal?.type === 'approve' && (
        <ConfirmPost
          title="Approve this order?"
          text={`Approve ${itemsQty(modal.order.items)} cylinder(s) for ${modal.order.customer}? Stock is not deducted until delivery.`}
          submit="Approve order"
          path={`orders/${modal.order.id}/approve`}
          branchId={modal.order.branch_id}
          onClose={close}
        />
      )}
      {modal?.type === 'cancel' && (
        <ReasonPostForm
          title="Cancel this order?"
          text="The order stays in the ledger as cancelled. No stock has moved."
          submit="Cancel order"
          commitLabel="Reason"
          danger
          path={`orders/${modal.order.id}/cancel`}
          onClose={close}
        />
      )}
      {modal?.type === 'deliver' && <DeliverForm order={modal.order} onClose={close} />}
    </>
  )
}

/* -------------------------------------------------------------------------- */
/* Sales                                                                      */
/* -------------------------------------------------------------------------- */

export function Sales() {
  const { me, tr, activeBranch } = useApp()
  const [from, setFrom] = useState(monthStart)
  const [to, setTo] = useState(today)
  const [pay, setPay] = useState('')
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const [receipt, setReceipt] = useState(null)
  const [voidBtn, voidModal] = useVoid()
  const showBranch = branchColumn(me, activeBranch)
  const list = usePagedApi('sales' + query({ from, to, payment_type: pay, q }), { deps: [from, to, pay, q] })

  const reset = () => { setFrom(monthStart()); setTo(today()); setPay(''); setQ('') }
  const exportPath = 'reports/sales' + query({ from, to, payment_type: pay, q })

  return (
    <>
      <PageHeader title={tr('nav.sales')} sub="Every cylinder sold, with its receipt and audit trail">
        <button className="ghost" onClick={() => downloadCsv(exportPath, 'sales.csv')}>Export CSV</button>
        <button className="primary" onClick={() => setOpen(true)}>New sale</button>
      </PageHeader>
      <ScopeNote />

      <FilterBar onReset={reset}>
        <TextFilter value={q} onChange={setQ} placeholder="Sale no. or customer" />
        <label>Payment
          <select value={pay} onChange={e => setPay(e.target.value)}>
            <option value="">All payments</option>
            <option value="cash">Cash</option>
            <option value="credit">Credit</option>
          </select>
        </label>
        <RangeFilter from={from} to={to} setFrom={setFrom} setTo={setTo} />
      </FilterBar>

      <Table
        head={[
          'Date', 'Sale no.', ...(showBranch ? ['Branch'] : []), 'Customer', 'Items',
          { t: 'Qty', r: true }, { t: 'Total', r: true }, 'Payment', 'Status', 'Actions',
        ]}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty="No sales match these filters."
        rows={list.rows.map(a => (
          <tr key={a.id} className={Number(a.is_void) === 1 ? 'row-void' : undefined}>
            <td>{dateFmt(a.sale_date)}</td>
            <td className="mono">{a.sale_no || '—'}</td>
            {showBranch ? <td><BranchCell row={a} /></td> : null}
            <td>{a.customer}</td>
            <td className="wrap">{itemsSummary(a.items)}</td>
            <td className="r">{itemsQty(a.items)}</td>
            <td className="r">{peso(a.total_amount)}</td>
            <td><Badge t={a.payment_type} /></td>
            <td>{Number(a.is_void) === 1 ? <Badge t="void" label="Void" /> : <Badge t="delivered" label="Posted" />}</td>
            <Acts>
              <button className="sm link-ac" onClick={() => setReceipt(a.id)}>Receipt</button>
              {Number(a.is_void) !== 1 && voidBtn(`sales/${a.id}`, { branchId: a.branch_id, text: 'Void this sale' })}
            </Acts>
          </tr>
        ))}
      />
      <Pagination page={list.page} pages={list.pages} total={list.total} onPage={list.setPage} />

      {open && <SaleForm onClose={() => setOpen(false)} />}
      {receipt && <ReceiptModal saleId={receipt} onClose={() => setReceipt(null)} />}
      {voidModal}
    </>
  )
}

/* -------------------------------------------------------------------------- */
/* Payments                                                                   */
/* -------------------------------------------------------------------------- */

export function Payments() {
  const { me, tr, activeBranch } = useApp()
  const [from, setFrom] = useState(monthStart)
  const [to, setTo] = useState(today)
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const [voidBtn, voidModal] = useVoid()
  const showBranch = branchColumn(me, activeBranch)
  const list = usePagedApi('payments' + query({ from, to, q }), { deps: [from, to, q] })

  return (
    <>
      <PageHeader title={tr('nav.payments')} sub="Credit payments received from customers">
        <button className="primary" onClick={() => setOpen(true)}>Record payment</button>
      </PageHeader>
      <ScopeNote />

      <FilterBar onReset={() => { setFrom(monthStart()); setTo(today()); setQ('') }}>
        <TextFilter value={q} onChange={setQ} placeholder="Payment no., reference or customer" />
        <RangeFilter from={from} to={to} setFrom={setFrom} setTo={setTo} />
      </FilterBar>

      <Table
        head={[
          'Date', 'Payment no.', ...(showBranch ? ['Branch'] : []), 'Customer', 'Method',
          'Reference', { t: 'Amount', r: true }, 'Status', 'Actions',
        ]}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty="No payments match these filters."
        rows={list.rows.map(p => (
          <tr key={p.id} className={Number(p.is_void) === 1 ? 'row-void' : undefined}>
            <td>{dateFmt(p.payment_date)}</td>
            <td className="mono">{p.payment_no || '—'}</td>
            {showBranch ? <td><BranchCell row={p} /></td> : null}
            <td>{p.customer}</td>
            <td><Badge t={p.method} label={p.method} /></td>
            <td>{p.reference_no || '—'}</td>
            <td className="r">{peso(p.amount)}</td>
            <td>{Number(p.is_void) === 1 ? <Badge t="void" /> : <Badge t="active" label="Posted" />}</td>
            <Acts>{Number(p.is_void) !== 1 && voidBtn(`payments/${p.id}`, { branchId: p.branch_id, text: 'Void this payment' })}</Acts>
          </tr>
        ))}
      />
      <Pagination page={list.page} pages={list.pages} total={list.total} onPage={list.setPage} />

      {open && <PaymentForm onClose={() => setOpen(false)} />}
      {voidModal}
    </>
  )
}

/* -------------------------------------------------------------------------- */
/* Plant deliveries                                                           */
/* -------------------------------------------------------------------------- */

export function Deliveries() {
  const { me, tr, activeBranch } = useApp()
  const [from, setFrom] = useState(monthStart)
  const [to, setTo] = useState(today)
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const [voidBtn, voidModal] = useVoid()
  const showBranch = branchColumn(me, activeBranch)
  const list = usePagedApi('deliveries' + query({ from, to, q }), { deps: [from, to, q] })

  return (
    <>
      <PageHeader title={tr('nav.deliveries')} sub="Full cylinders received from the plant and empties returned">
        <button className="primary" onClick={() => setOpen(true)}>Record delivery</button>
      </PageHeader>
      <ScopeNote />

      <FilterBar onReset={() => { setFrom(monthStart()); setTo(today()); setQ('') }}>
        <TextFilter value={q} onChange={setQ} placeholder="Receipt no. or supplier" />
        <RangeFilter from={from} to={to} setFrom={setFrom} setTo={setTo} />
      </FilterBar>

      <Table
        head={[
          'Date', 'Receipt', ...(showBranch ? ['Branch'] : []), 'Supplier', 'Items',
          { t: 'Full in', r: true }, { t: 'Empties out', r: true }, 'Received by', 'Status', 'Actions',
        ]}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty="No plant deliveries recorded yet."
        rows={list.rows.map(d => (
          <tr key={d.id} className={Number(d.is_void) === 1 ? 'row-void' : undefined}>
            <td>{dateFmt(d.delivery_date)}</td>
            <td className="mono">{d.receipt_no}</td>
            {showBranch ? <td><BranchCell row={d} /></td> : null}
            <td>{d.supplier || '—'}</td>
            <td className="wrap">{itemsSummary(d.items, 'delivery')}</td>
            <td className="r">{num(d.full_received)}</td>
            <td className="r">{num(d.empties_returned)}</td>
            <td>{d.received_by || '—'}</td>
            <td>{Number(d.is_void) === 1 ? <Badge t="void" /> : <Badge t="active" label="Posted" />}</td>
            <Acts>{Number(d.is_void) !== 1 && voidBtn(`deliveries/${d.id}`, { branchId: d.branch_id, text: 'Void this delivery' })}</Acts>
          </tr>
        ))}
      />
      <Pagination page={list.page} pages={list.pages} total={list.total} onPage={list.setPage} />

      {open && <DeliveryForm onClose={() => setOpen(false)} />}
      {voidModal}
    </>
  )
}

/* -------------------------------------------------------------------------- */
/* Customers                                                                  */
/* -------------------------------------------------------------------------- */

export function Customers() {
  const { me, tr, activeBranch } = useApp()
  const [q, setQ] = useState('')
  const [includeInactive, setIncludeInactive] = useState(false)
  const [modal, setModal] = useState(null)
  const list = usePagedApi('customers' + query({ q, include_inactive: includeInactive && me?.role === 'owner' ? 1 : '' }), {
    deps: [q, includeInactive],
    perPage: 30,
  })

  return (
    <>
      <PageHeader title={tr('nav.customers')} sub="Balances and empty cylinders owed, scoped to the current branch view">
        <button className="ghost" onClick={() => setModal({ type: 'pay', customerId: '' })}>Record payment</button>
        <button className="primary" onClick={() => setModal({ type: 'add' })}>Add customer</button>
      </PageHeader>
      <ScopeNote />

      <FilterBar onReset={() => { setQ(''); setIncludeInactive(false) }}>
        <TextFilter value={q} onChange={setQ} placeholder="Name, contact or area" />
        {me?.role === 'owner' && (
          <label className="check">
            <input type="checkbox" checked={includeInactive} onChange={e => setIncludeInactive(e.target.checked)} />
            Show deactivated customers
          </label>
        )}
      </FilterBar>

      <Table
        head={[
          'Name', 'Contact', 'Area', { t: 'Credit limit', r: true }, { t: 'Unpaid', r: true },
          { t: 'Empties owed', r: true }, 'Reseller', 'Status', 'Actions',
        ]}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty="No customers match these filters. Add your first customer."
        rows={list.rows.map(c => (
          <tr key={c.id} className={Number(c.is_active) === 0 ? 'row-void' : undefined}>
            <td><b>{c.name}</b>{c.username ? <small className="sub">@{c.username}</small> : null}</td>
            <td>{c.contact || '—'}</td>
            <td>{c.address || '—'}</td>
            <td className="r">{c.credit_limit === null ? 'No limit' : peso(c.credit_limit)}</td>
            <td className={'r ' + (Number(c.balance) > 0 ? 'neg' : '')}>{peso(c.balance)}</td>
            <td className={'r ' + (Number(c.empties_owed) > 0 ? 'warn' : '')}>{num(c.empties_owed)}</td>
            <td>
              {Number(c.is_reseller) === 1 ? <Badge t={c.reseller_status || 'pending'} /> : <span className="muted">—</span>}
            </td>
            <td>{Number(c.is_active) === 1 ? <Badge t="active" /> : <Badge t="inactive" />}</td>
            <Acts>
              <button className="sm link-ac" onClick={() => setModal({ type: 'pay', customerId: String(c.id) })}>Pay</button>
              <button className="sm link-ac" onClick={() => setModal({ type: 'edit', customer: c })}>Edit</button>
              {Number(c.is_active) === 1 && (
                <button className="sm link-danger" onClick={() => setModal({ type: 'deactivate', customer: c })}>Deactivate</button>
              )}
            </Acts>
          </tr>
        ))}
      />
      <Pagination page={list.page} pages={list.pages} total={list.total} onPage={list.setPage} />

      {modal?.type === 'add' && <CustomerForm onClose={() => setModal(null)} />}
      {modal?.type === 'edit' && <CustomerForm customer={modal.customer} onClose={() => setModal(null)} />}
      {modal?.type === 'pay' && <PaymentForm customerId={modal.customerId} onClose={() => setModal(null)} />}
      {modal?.type === 'deactivate' && (
        <ConfirmDelete
          title={'Deactivate ' + modal.customer.name + '?'}
          text="Their login is disabled too. Sales and payment history stay in the ledger. Open orders must be resolved first."
          submit="Deactivate customer"
          path={'customers/' + modal.customer.id}
          onClose={() => setModal(null)}
        />
      )}
    </>
  )
}

/* -------------------------------------------------------------------------- */
/* Stock                                                                      */
/* -------------------------------------------------------------------------- */

export function Stock() {
  const { me, branches, activeBranch, tr } = useApp()
  const [modal, setModal] = useState(null)
  const [q, setQ] = useState('')
  const sizes = useApi('sizes/all')
  const stock = useApi('reports/stock')
  const adjustments = usePagedApi('adjustments' + query({ q }), { deps: [q] })
  const [voidBtn, voidModal] = useVoid()
  const concrete = activeBranch !== 'all' ? String(activeBranch) : ''

  return (
    <>
      <PageHeader title={tr('nav.stock')} sub="Cylinder sizes, opening counts, corrections and per-branch prices">
        <button className="ghost" onClick={() => setModal({ type: 'size' })}>Add size</button>
        <button className="ghost" onClick={() => setModal({ type: 'prices' })}>Branch prices</button>
        <button className="primary" onClick={() => setModal({ type: 'adjust' })}>Opening count / correction</button>
      </PageHeader>
      <ScopeNote />

      <h2 className="sec">Cylinder sizes</h2>
      <Table
        head={['Size', 'Valve', { t: 'Standard price', r: true }, 'Status', 'Actions']}
        loading={sizes.loading}
        error={sizes.error}
        onRetry={sizes.reload}
        empty="No cylinder sizes yet. Add the sizes this business sells."
        rows={(sizes.data || []).map(s => (
          <tr key={s.id}>
            <td><b>{s.label}</b></td>
            <td>{String(s.valve_type || 'unspecified').replace('_', '-')}</td>
            <td className="r">{peso(s.selling_price)}</td>
            <td>{Number(s.is_active) === 1 ? <Badge t="active" /> : <Badge t="inactive" />}</td>
            <Acts>
              <button className="sm link-ac" onClick={() => setModal({ type: 'prices', branchId: s.branch_id || concrete })}>Prices</button>
              {Number(s.is_active) === 1 && (
                <button className="sm link-danger" onClick={() => setModal({ type: 'sizeDelete', size: s })}>Remove</button>
              )}
            </Acts>
          </tr>
        ))}
      />

      <h2 className="sec">Stock on hand</h2>
      <Table
        head={['Branch', 'Size', { t: 'Full', r: true }, { t: 'Empty', r: true }, { t: 'With customers', r: true }, { t: 'Total', r: true }]}
        loading={stock.loading}
        error={stock.error}
        onRetry={stock.reload}
        empty="No stock recorded yet. Start with an opening count."
        rows={(stock.data?.rows || []).map(r => (
          <tr key={r.branch_id + ':' + r.size_id}>
            <td>{r.branch_name}</td>
            <td>{r.size}</td>
            <td className={'r ' + (r.full_qty < 0 ? 'neg' : '')}>{num(r.full_qty)}</td>
            <td className={'r ' + (r.empty_qty < 0 ? 'neg' : '')}>{num(r.empty_qty)}</td>
            <td className="r">{num(r.out_qty)}</td>
            <td className="r"><b>{num(r.total_qty)}</b></td>
          </tr>
        ))}
      />

      <h2 className="sec">Stock adjustments</h2>
      <FilterBar onReset={() => setQ('')}>
        <TextFilter value={q} onChange={setQ} placeholder="Reason or note" />
      </FilterBar>
      <Table
        head={['Date', 'Branch', 'Size', 'Reason', { t: 'Full', r: true }, { t: 'Empty', r: true }, 'By', 'Status', 'Actions']}
        loading={adjustments.loading}
        error={adjustments.error}
        onRetry={adjustments.reload}
        empty="No adjustments yet. Record an opening count for each new branch."
        rows={adjustments.rows.map(a => (
          <tr key={a.id} className={Number(a.is_void) === 1 ? 'row-void' : undefined}>
            <td>{dateFmt(a.adj_date)}</td>
            <td>{a.branch_name}</td>
            <td>{a.size}</td>
            <td>{a.reason}{a.note ? <small className="sub">{a.note}</small> : null}</td>
            <td className={'r ' + (Number(a.full_change) < 0 ? 'neg' : '')}>{a.full_change}</td>
            <td className={'r ' + (Number(a.empty_change) < 0 ? 'neg' : '')}>{a.empty_change}</td>
            <td>{a.created_by_name}</td>
            <td>{Number(a.is_void) === 1 ? <Badge t="void" /> : <Badge t="active" label="Posted" />}</td>
            <Acts>{Number(a.is_void) !== 1 && voidBtn(`adjustments/${a.id}`, { branchId: a.branch_id, text: 'Void this adjustment' })}</Acts>
          </tr>
        ))}
      />
      <Pagination page={adjustments.page} pages={adjustments.pages} total={adjustments.total} onPage={adjustments.setPage} />

      {modal?.type === 'size' && <SizeForm onClose={() => setModal(null)} />}
      {modal?.type === 'adjust' && <AdjustForm onClose={() => setModal(null)} />}
      {modal?.type === 'prices' && <PriceForm branchId={modal.branchId || concrete} onClose={() => setModal(null)} />}
      {modal?.type === 'sizeDelete' && (
        <ConfirmDelete
          title={'Remove ' + modal.size.label + '?'}
          text="The size is hidden from new sales and orders. Sizes with stock or open orders cannot be removed."
          submit="Remove size"
          path={'sizes/' + modal.size.id}
          onClose={() => setModal(null)}
        />
      )}
      {voidModal}
      {!branches.length && me?.role === 'owner' ? <p className="hint">Add a branch first.</p> : null}
    </>
  )
}

/* -------------------------------------------------------------------------- */
/* Transfers                                                                  */
/* -------------------------------------------------------------------------- */

export function Transfers() {
  const { me, tr } = useApp()
  const [status, setStatus] = useState('')
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const [modal, setModal] = useState(null)
  const list = usePagedApi('transfers' + query({ status, q }), { deps: [status, q] })

  const isSource = t => me?.role === 'owner' || Number(me?.branch_id) === Number(t.from_branch_id)
  const isDestination = t => me?.role === 'owner' || Number(me?.branch_id) === Number(t.to_branch_id)

  return (
    <>
      <PageHeader title={tr('nav.transfers')} sub="Move full or empty cylinders between branches with a two-step handover">
        <button className="primary" onClick={() => setOpen(true)}>Request transfer</button>
      </PageHeader>

      <FilterBar onReset={() => { setStatus(''); setQ('') }}>
        <TextFilter value={q} onChange={setQ} placeholder="Transfer no. or branch" />
        <label>Status
          <select value={status} onChange={e => setStatus(e.target.value)}>
            <option value="">All statuses</option>
            {['requested', 'approved', 'in_transit', 'received', 'cancelled'].map(s => (
              <option key={s} value={s}>{tr('status.' + s, s)}</option>
            ))}
          </select>
        </label>
      </FilterBar>

      <Table
        head={[
          'Transfer no.', 'From', 'To', 'Items', { t: 'Full', r: true }, { t: 'Empty', r: true },
          'Requested', 'Status', 'Actions',
        ]}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty="No stock transfers yet."
        rows={list.rows.map(t => (
          <tr key={t.id}>
            <td className="mono">{t.transfer_no}</td>
            <td>{t.from_branch}</td>
            <td>{t.to_branch}</td>
            <td className="wrap">{itemsSummary(t.items, 'transfer')}</td>
            <td className="r">{num(t.total_full)}</td>
            <td className="r">{num(t.total_empty)}</td>
            <td>{t.requested_by}<small className="sub">{dtFmt(t.requested_at)}</small></td>
            <td><Badge t={t.status} /></td>
            <Acts>
              {t.status === 'requested' && isSource(t) && (
                <button className="sm link-ac" onClick={() => setModal({ type: 'approve', transfer: t })}>Approve</button>
              )}
              {t.status === 'approved' && isSource(t) && (
                <button className="sm link-ac" onClick={() => setModal({ type: 'ship', transfer: t })}>Ship</button>
              )}
              {t.status === 'in_transit' && isDestination(t) && (
                <button className="sm link-ac" onClick={() => setModal({ type: 'receive', transfer: t })}>Receive</button>
              )}
              {['requested', 'approved'].includes(t.status) && (
                <button className="sm link-danger" onClick={() => setModal({ type: 'cancel', transfer: t })}>Cancel</button>
              )}
            </Acts>
          </tr>
        ))}
      />
      <Pagination page={list.page} pages={list.pages} total={list.total} onPage={list.setPage} />

      {open && <TransferForm onClose={() => setOpen(false)} />}
      {modal?.type === 'approve' && (
        <ConfirmPost
          title={'Approve ' + modal.transfer.transfer_no + '?'}
          text="The sending branch can then ship the stock. Stock does not move until it is shipped."
          submit="Approve transfer"
          path={`transfers/${modal.transfer.id}/approve`}
          onClose={() => setModal(null)}
        />
      )}
      {modal?.type === 'ship' && (
        <ConfirmPost
          title={'Ship ' + modal.transfer.transfer_no + '?'}
          text="This deducts the listed full and empty cylinders from the sending branch."
          submit="Ship transfer"
          path={`transfers/${modal.transfer.id}/ship`}
          onClose={() => setModal(null)}
        />
      )}
      {modal?.type === 'receive' && (
        <ConfirmPost
          title={'Receive ' + modal.transfer.transfer_no + '?'}
          text="This adds the listed cylinders to the receiving branch. Confirm the physical count first."
          submit="Receive transfer"
          path={`transfers/${modal.transfer.id}/receive`}
          onClose={() => setModal(null)}
        />
      )}
      {modal?.type === 'cancel' && (
        <ReasonPostForm
          title={'Cancel ' + modal.transfer.transfer_no + '?'}
          text="Only transfers that have not been shipped can be cancelled."
          submit="Cancel transfer"
          path={`transfers/${modal.transfer.id}/cancel`}
          onClose={() => setModal(null)}
        />
      )}
    </>
  )
}

/* -------------------------------------------------------------------------- */
/* Reports                                                                    */
/* -------------------------------------------------------------------------- */

const REPORT_TABS = [
  { key: 'daily', label: 'Daily takings', path: 'reports/daily' },
  { key: 'sales', label: 'Sales register', path: 'reports/sales' },
  { key: 'unpaid', label: 'Unpaid balances', path: 'reports/unpaid' },
  { key: 'empties', label: 'Empties owed', path: 'reports/empties' },
  { key: 'stock', label: 'Stock on hand', path: 'reports/stock' },
]

function ReportTotals({ totals }) {
  if (!totals) return null
  const entries = Object.entries(totals).filter(([, v]) => v !== null && v !== undefined)
  return (
    <div className="stats">
      {entries.map(([k, v]) => (
        <Stat key={k} label={k.replaceAll('_', ' ')} value={typeof v === 'number' ? num(v) : String(v)} />
      ))}
    </div>
  )
}

export function Reports() {
  const { me, tr, activeBranch } = useApp()
  const [tab, setTab] = useState('daily')
  const [from, setFrom] = useState(monthStart)
  const [to, setTo] = useState(today)
  const [pay, setPay] = useState('')
  const [q, setQ] = useState('')
  const isOwner = me?.role === 'owner'

  const tabs = isOwner ? [...REPORT_TABS, { key: 'branches', label: 'Branch comparison', path: 'reports/branch-comparison' }] : REPORT_TABS
  const active = tabs.find(t => t.key === tab) || tabs[0]
  const params = active.key === 'branches'
    ? { from, to }
    : active.key === 'daily'
      ? { from, to }
      : { from, to, ...(active.key === 'sales' ? { payment_type: pay, q } : {}) }
  const path = active.path + query(params)
  const report = useApi(path, { deps: [tab, from, to, pay, q] })

  return (
    <>
      <PageHeader title={tr('nav.reports')} sub="Branch-scoped figures with CSV export for your accountant">
        <button className="ghost" onClick={() => downloadCsv(path, active.key + '.csv')}>Export CSV</button>
      </PageHeader>
      <ScopeNote />

      <div className="tabs" role="tablist" aria-label="Report type">
        {tabs.map(t => (
          <button
            key={t.key}
            role="tab"
            aria-selected={active.key === t.key}
            className={active.key === t.key ? 'on' : ''}
            onClick={() => setTab(t.key)}
          >
            {t.label}
          </button>
        ))}
      </div>

      <FilterBar onReset={() => { setFrom(monthStart()); setTo(today()); setPay(''); setQ('') }}>
        <RangeFilter from={from} to={to} setFrom={setFrom} setTo={setTo} />
        {active.key === 'sales' && (
          <>
            <label>Payment
              <select value={pay} onChange={e => setPay(e.target.value)}>
                <option value="">All payments</option>
                <option value="cash">Cash</option>
                <option value="credit">Credit</option>
              </select>
            </label>
            <TextFilter value={q} onChange={setQ} placeholder="Sale no. or customer" />
          </>
        )}
      </FilterBar>

      {report.loading && <Loader />}
      {report.error && <ErrorState error={report.error} onRetry={report.reload} />}

      {report.data && active.key === 'daily' && (
        <>
          <ReportTotals totals={report.data.totals} />
          <Table
            head={['Date', { t: 'Cash sales', r: true }, { t: 'Credit sales', r: true }, { t: 'Total sales', r: true },
              { t: 'Credit payments', r: true }, { t: 'Cash collected', r: true }, { t: 'Deposits', r: true },
              { t: 'Voided', r: true }, { t: 'Full in', r: true }, { t: 'Empties out', r: true }]}
            empty="No activity in this date range."
            rows={report.data.rows.map(r => (
              <tr key={r.report_date}>
                <td>{r.report_date}</td>
                <td className="r">{peso(r.cash_sales)}</td>
                <td className="r">{peso(r.credit_sales)}</td>
                <td className="r"><b>{peso(r.total_sales)}</b></td>
                <td className="r">{peso(r.payments)}</td>
                <td className="r">{peso(r.cash_collected)}</td>
                <td className="r">{peso(r.deposits)}</td>
                <td className="r">{r.voided_sales}</td>
                <td className="r">{r.full_received}</td>
                <td className="r">{r.empties_returned}</td>
              </tr>
            ))}
          />
        </>
      )}

      {report.data && active.key === 'sales' && (
        <>
          <ReportTotals totals={report.data.totals} />
          <Table
            head={['Sale no.', 'Date', ...(isOwner && activeBranch === 'all' ? ['Branch'] : []), 'Customer', 'Payment',
              { t: 'Qty', r: true }, { t: 'Subtotal', r: true }, { t: 'Deposit', r: true }, { t: 'Total', r: true }, 'Status']}
            empty="No sales in this date range."
            rows={report.data.rows.map(r => (
              <tr key={r.id} className={r.is_void ? 'row-void' : undefined}>
                <td className="mono">{r.sale_no}</td>
                <td>{r.sale_date}</td>
                {isOwner && activeBranch === 'all' ? <td>{r.branch_name}</td> : null}
                <td>{r.customer || 'Walk-in'}</td>
                <td><Badge t={r.payment_type} /></td>
                <td className="r">{r.qty}</td>
                <td className="r">{peso(r.subtotal)}</td>
                <td className="r">{peso(r.deposit_total)}</td>
                <td className="r"><b>{peso(r.total_amount)}</b></td>
                <td>{r.is_void ? <Badge t="void" /> : <Badge t="active" label="Posted" />}</td>
              </tr>
            ))}
          />
        </>
      )}

      {report.data && active.key === 'unpaid' && (
        <>
          <div className="stats">
            <Stat label="Total unpaid" value={peso(report.data.total_balance)} tone="warn" />
          </div>
          <Table
            head={['Customer', 'Contact', 'Area', { t: 'Charged', r: true }, { t: 'Paid', r: true },
              { t: 'Balance', r: true }, { t: 'Credit limit', r: true }, 'Over limit']}
            empty="No outstanding balances in this branch view."
            rows={report.data.rows.map(r => (
              <tr key={r.id}>
                <td><b>{r.name}</b></td>
                <td>{r.contact || '—'}</td>
                <td>{r.address || '—'}</td>
                <td className="r">{peso(r.charged)}</td>
                <td className="r">{peso(r.paid)}</td>
                <td className="r neg">{peso(r.balance)}</td>
                <td className="r">{r.credit_limit === null ? 'No limit' : peso(r.credit_limit)}</td>
                <td>{r.over_limit ? <Badge t="cancelled" label="Over limit" /> : '—'}</td>
              </tr>
            ))}
          />
        </>
      )}

      {report.data && active.key === 'empties' && (
        <>
          <div className="stats">
            <Stat label="Empties owed by customers" value={num(report.data.total_empties)} />
          </div>
          <Table
            head={['Customer', 'Contact', 'Area', { t: 'Empties owed', r: true }]}
            empty="No empties outstanding in this branch view."
            rows={report.data.rows.map(r => (
              <tr key={r.id}>
                <td><b>{r.name}</b></td>
                <td>{r.contact || '—'}</td>
                <td>{r.address || '—'}</td>
                <td className="r warn">{r.empties_owed}</td>
              </tr>
            ))}
          />
        </>
      )}

      {report.data && active.key === 'stock' && (
        <Table
          head={['Branch', 'Size', { t: 'Full', r: true }, { t: 'Empty', r: true }, { t: 'With customers', r: true }, { t: 'Total', r: true }]}
          empty="No stock rows for this branch view."
          rows={report.data.rows.map(r => (
            <tr key={r.branch_id + ':' + r.size_id}>
              <td>{r.branch_name}</td>
              <td>{r.size}</td>
              <td className={'r ' + (r.full_qty < 0 ? 'neg' : '')}>{num(r.full_qty)}</td>
              <td className={'r ' + (r.empty_qty < 0 ? 'neg' : '')}>{num(r.empty_qty)}</td>
              <td className="r">{num(r.out_qty)}</td>
              <td className="r"><b>{num(r.total_qty)}</b></td>
            </tr>
          ))}
        />
      )}

      {report.data && active.key === 'branches' && (
        <>
          <ReportTotals totals={report.data.totals} />
          <Table
            head={['Branch', 'Code', { t: 'Sales', r: true }, { t: 'Cash sales', r: true }, { t: 'Credit sales', r: true },
              { t: 'Payments', r: true }, { t: 'Unpaid', r: true }, { t: 'Full', r: true }, { t: 'Empty', r: true }, { t: 'Open orders', r: true }]}
            empty="No active branches."
            rows={report.data.rows.map(r => (
              <tr key={r.id}>
                <td><b>{r.name}</b></td>
                <td className="mono">{r.code}</td>
                <td className="r">{peso(r.sales_total)}</td>
                <td className="r">{peso(r.cash_sales)}</td>
                <td className="r">{peso(r.credit_sales)}</td>
                <td className="r">{peso(r.payments)}</td>
                <td className="r neg">{peso(r.unpaid)}</td>
                <td className="r">{num(r.full_qty)}</td>
                <td className="r">{num(r.empty_qty)}</td>
                <td className="r">{num(r.open_orders)}</td>
              </tr>
            ))}
          />
        </>
      )}
    </>
  )
}

/* -------------------------------------------------------------------------- */
/* Branches                                                                   */
/* -------------------------------------------------------------------------- */

export function Branches() {
  const { tr } = useApp()
  const [modal, setModal] = useState(null)
  const list = useApi('branches')

  return (
    <>
      <PageHeader title={tr('nav.branches')} sub="Locations, service areas, cut-off times and price overrides">
        <button className="primary" onClick={() => setModal({ type: 'add' })}>Add branch</button>
      </PageHeader>

      <p className="scope-note">
        Active branches appear on the public website and can receive customer orders.
        A branch with stock, active staff, open work or open transfers cannot be deactivated.
      </p>

      <Table
        head={['Branch', 'Code', 'Contact', 'Hours', 'Cut-off', { t: 'Max / order', r: true },
          { t: 'Active staff', r: true }, { t: 'Open orders', r: true }, { t: 'Open transfers', r: true },
          { t: 'Full stock', r: true }, { t: 'Sold today', r: true }, 'Status', 'Actions']}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty="No branches yet. Add your first branch to start selling."
        rows={(list.data || []).map(b => (
          <tr key={b.id} className={Number(b.is_active) === 0 ? 'row-void' : undefined}>
            <td><b>{b.name}</b>{b.address ? <small className="sub">{b.address}</small> : null}</td>
            <td className="mono">{b.code}</td>
            <td>{b.contact_phone || '—'}</td>
            <td>{b.operating_hours || '—'}</td>
            <td>{b.cutoff_time || '—'}</td>
            <td className="r">{b.max_order_qty}</td>
            <td className="r">{b.staff_count}</td>
            <td className="r">{b.open_orders}</td>
            <td className="r">{b.open_transfers}</td>
            <td className="r">{num(b.stock_full)}</td>
            <td className="r">{peso(b.sales_today)}</td>
            <td>{Number(b.is_active) === 1 ? <Badge t="active" /> : <Badge t="inactive" />}</td>
            <Acts>
              <button className="sm link-ac" onClick={() => setModal({ type: 'edit', branch: b })}>Edit</button>
              {Number(b.is_active) === 1 && (
                <>
                  <button className="sm link-ac" onClick={() => setModal({ type: 'prices', branch: b })}>Prices</button>
                  <button className="sm link-danger" onClick={() => setModal({ type: 'deactivate', branch: b })}>Deactivate</button>
                </>
              )}
              {Number(b.is_active) === 0 && (
                <button className="sm link-ac" onClick={() => setModal({ type: 'activate', branch: b })}>Activate</button>
              )}
            </Acts>
          </tr>
        ))}
      />

      {modal?.type === 'add' && <BranchForm onClose={() => setModal(null)} />}
      {modal?.type === 'edit' && <BranchForm branch={modal.branch} onClose={() => setModal(null)} />}
      {modal?.type === 'prices' && <PriceForm branchId={modal.branch.id} onClose={() => setModal(null)} />}
      {modal?.type === 'activate' && (
        <ConfirmPost
          title={'Activate ' + modal.branch.name + '?'}
          text="The branch becomes selectable for orders, sales and transfers, and appears on the public website."
          submit="Activate branch"
          path={`branches/${modal.branch.id}/activate`}
          onClose={() => setModal(null)}
        />
      )}
      {modal?.type === 'deactivate' && (
        <ConfirmPost
          title={'Deactivate ' + modal.branch.name + '?'}
          text="This only works when the branch has no stock, active staff, open orders or open transfers. History is kept."
          submit="Deactivate branch"
          danger
          path={`branches/${modal.branch.id}/deactivate`}
          onClose={() => setModal(null)}
        />
      )}
    </>
  )
}

/* -------------------------------------------------------------------------- */
/* Users                                                                      */
/* -------------------------------------------------------------------------- */

export function Users() {
  const { me, tr } = useApp()
  const [role, setRole] = useState('')
  const [q, setQ] = useState('')
  const [modal, setModal] = useState(null)
  const list = usePagedApi('users' + query({ role, q }), { deps: [role, q] })

  return (
    <>
      <PageHeader title={tr('nav.users')} sub="Logins, roles and branch assignments">
        <button className="primary" onClick={() => setModal({ type: 'add' })}>Add user</button>
      </PageHeader>

      <p className="scope-note">
        Staff accounts are locked to exactly one branch. Changing a role, branch or password signs that user out everywhere.
        New and reset accounts must choose a new password at first login.
      </p>

      <FilterBar onReset={() => { setRole(''); setQ('') }}>
        <TextFilter value={q} onChange={setQ} placeholder="Name or username" />
        <label>Role
          <select value={role} onChange={e => setRole(e.target.value)}>
            <option value="">All roles</option>
            <option value="owner">Owner</option>
            <option value="staff">Staff</option>
            <option value="customer">Customer</option>
          </select>
        </label>
      </FilterBar>

      <Table
        head={['Name', 'Username', 'Role', 'Branch', 'Last login', 'Status', 'Actions']}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty="No users match these filters."
        rows={list.rows.map(u => (
          <tr key={u.id} className={Number(u.is_active) === 0 ? 'row-void' : undefined}>
            <td><b>{u.name}</b>{Number(u.must_change_password) === 1 ? <small className="sub">Must change password</small> : null}</td>
            <td className="mono">@{u.username}</td>
            <td><Badge t={u.role} /></td>
            <td>{u.branch_name || '—'}</td>
            <td>{u.last_login_at ? dtFmt(u.last_login_at) : 'Never'}</td>
            <td>{Number(u.is_active) === 1 ? <Badge t="active" /> : <Badge t="inactive" />}</td>
            <Acts>
              <button className="sm link-ac" onClick={() => setModal({ type: 'edit', user: u })}>Edit</button>
              {u.id !== me?.id && Number(u.is_active) === 1 && (
                <>
                  <button className="sm link-ac" onClick={() => setModal({ type: 'reset', user: u })}>Reset password</button>
                  <button className="sm link-danger" onClick={() => setModal({ type: 'deactivate', user: u })}>Deactivate</button>
                </>
              )}
            </Acts>
          </tr>
        ))}
      />
      <Pagination page={list.page} pages={list.pages} total={list.total} onPage={list.setPage} />

      {modal?.type === 'add' && <UserForm onClose={() => setModal(null)} />}
      {modal?.type === 'edit' && <UserForm user={modal.user} onClose={() => setModal(null)} />}
      {modal?.type === 'reset' && <ResetPasswordForm user={modal.user} onClose={() => setModal(null)} />}
      {modal?.type === 'deactivate' && (
        <ConfirmDelete
          title={'Deactivate ' + modal.user.name + '?'}
          text="Their active session ends immediately. The account and its history remain."
          submit="Deactivate user"
          path={'users/' + modal.user.id}
          onClose={() => setModal(null)}
        />
      )}
    </>
  )
}

/* -------------------------------------------------------------------------- */
/* Audit trail                                                                */
/* -------------------------------------------------------------------------- */

const AUDIT_ACTIONS = [
  'login', 'logout', 'register', 'create', 'update', 'deactivate', 'activate', 'approve',
  'cancel', 'deliver', 'assign', 'ship', 'receive', 'void', 'password_reset', 'password_change',
]
const AUDIT_ENTITIES = ['user', 'customer', 'sale', 'order', 'delivery', 'payment', 'adjustment', 'size', 'branch', 'branch_prices', 'transfer']

export function Audit() {
  const { tr } = useApp()
  const [q, setQ] = useState('')
  const [action, setAction] = useState('')
  const [entity, setEntity] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const list = usePagedApi('audit' + query({ q, action, entity, from, to }), { deps: [q, action, entity, from, to] })

  return (
    <>
      <PageHeader title={tr('nav.audit')} sub="Who changed what, when, and from which IP address" />

      <FilterBar onReset={() => { setQ(''); setAction(''); setEntity(''); setFrom(''); setTo('') }}>
        <TextFilter value={q} onChange={setQ} placeholder="Entity id, user or IP" />
        <label>Action
          <select value={action} onChange={e => setAction(e.target.value)}>
            <option value="">All actions</option>
            {AUDIT_ACTIONS.map(a => <option key={a} value={a}>{a.replaceAll('_', ' ')}</option>)}
          </select>
        </label>
        <label>Entity
          <select value={entity} onChange={e => setEntity(e.target.value)}>
            <option value="">All entities</option>
            {AUDIT_ENTITIES.map(e => <option key={e} value={e}>{e.replaceAll('_', ' ')}</option>)}
          </select>
        </label>
        <RangeFilter from={from} to={to} setFrom={setFrom} setTo={setTo} />
      </FilterBar>

      <Table
        head={['When', 'User', 'Branch', 'Action', 'Entity', 'Record', 'IP', 'Changes']}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty="No audit entries match these filters."
        rows={list.rows.map(a => (
          <tr key={a.id}>
            <td>{dtFmt(a.created_at)}</td>
            <td>{a.user_name || 'System'}{a.username ? <small className="sub">@{a.username}</small> : null}</td>
            <td>{a.branch_name || '—'}</td>
            <td><Badge t={a.action} label={String(a.action).replaceAll('_', ' ')} /></td>
            <td>{String(a.entity || '').replaceAll('_', ' ')}</td>
            <td className="mono">{a.entity_id ?? '—'}</td>
            <td className="mono">{a.ip || '—'}</td>
            <td className="wrap">
              {a.old_values || a.new_values ? (
                <details>
                  <summary>View</summary>
                  <pre>{JSON.stringify({ before: a.old_values, after: a.new_values }, null, 2)}</pre>
                </details>
              ) : '—'}
            </td>
          </tr>
        ))}
      />
      <Pagination page={list.page} pages={list.pages} total={list.total} onPage={list.setPage} />
    </>
  )
}

/* -------------------------------------------------------------------------- */
/* Customer shop                                                              */
/* -------------------------------------------------------------------------- */

export function Shop() {
  const { tr } = useApp()
  const [modal, setModal] = useState(null)
  const orders = useApi('my/orders?per_page=100')
  const account = useApi('my/account')
  const payments = useApi('my/payments?per_page=50')
  const acct = account.data

  const openOrders = (orders.data || []).filter(o => ['pending', 'approved'].includes(o.status)).length

  return (
    <>
      <PageHeader title={tr('nav.shop')} sub="Place orders, track deliveries, and see what you owe">
        <button className="primary" onClick={() => setModal({ type: 'order' })}>Place an order</button>
      </PageHeader>

      {acct && (
        <div className="stats">
          <Stat label="Amount you owe" value={peso(Math.max(0, Number(acct.balance) || 0))} tone={Number(acct.balance) > 0 ? 'warn' : 'ok'} />
          <Stat label="Empties to return" value={num(acct.empties_owed)} />
          <Stat label="Open orders" value={num(openOrders)} hint="Pending or approved" />
          <Stat label="Credit limit" value={acct.credit_limit === null ? 'No limit' : peso(acct.credit_limit)} />
        </div>
      )}

      {acct?.by_branch?.length > 1 && (
        <>
          <h2 className="sec">Balance by branch</h2>
          <Table
            head={['Branch', { t: 'Balance', r: true }, { t: 'Empties owed', r: true }]}
            empty="No branch balances."
            rows={acct.by_branch.map(b => (
              <tr key={b.branch_id}>
                <td>{b.branch_name}</td>
                <td className="r">{peso(b.balance)}</td>
                <td className="r">{num(b.empties_owed)}</td>
              </tr>
            ))}
          />
        </>
      )}

      <h2 className="sec">My orders</h2>
      <Table
        head={['Order no.', 'Branch', 'Items', { t: 'Total', r: true }, 'Requested', 'Payment', 'Status', 'Actions']}
        loading={orders.loading}
        error={orders.error}
        onRetry={orders.reload}
        empty="You have no orders yet. Place your first order."
        rows={(orders.data || []).map(o => (
          <tr key={o.id}>
            <td className="mono">{o.order_no || '—'}</td>
            <td>{o.branch_name}</td>
            <td className="wrap">{itemsSummary(o.items)}</td>
            <td className="r">{peso(o.total_amount)}</td>
            <td>{dateFmt(o.requested_date)}{o.time_slot ? <small className="sub">{o.time_slot}</small> : null}</td>
            <td><Badge t={o.payment_type} /></td>
            <td><Badge t={o.status} /></td>
            <Acts>
              {o.status === 'pending' && (
                <>
                  <button className="sm link-ac" onClick={() => setModal({ type: 'edit', order: o })}>Edit</button>
                  <button className="sm link-danger" onClick={() => setModal({ type: 'cancel', order: o })}>Cancel</button>
                </>
              )}
            </Acts>
          </tr>
        ))}
      />

      <h2 className="sec">My payments</h2>
      <Table
        head={['Date', 'Payment no.', 'Branch', 'Method', { t: 'Amount', r: true }, 'Status']}
        loading={payments.loading}
        error={payments.error}
        onRetry={payments.reload}
        empty="No payments recorded yet."
        rows={(payments.data || []).map(p => (
          <tr key={p.id}>
            <td>{dateFmt(p.payment_date)}</td>
            <td className="mono">{p.payment_no || '—'}</td>
            <td>{p.branch_name}</td>
            <td><Badge t={p.method} label={p.method} /></td>
            <td className="r">{peso(p.amount)}</td>
            <td>{Number(p.is_void) === 1 ? <Badge t="void" /> : <Badge t="active" label="Posted" />}</td>
          </tr>
        ))}
      />

      {modal?.type === 'order' && <OrderForm onClose={() => setModal(null)} />}
      {modal?.type === 'edit' && <OrderForm order={modal.order} onClose={() => setModal(null)} />}
      {modal?.type === 'cancel' && (
        <ConfirmPost
          title="Cancel this order?"
          text="You can cancel only while the branch has not delivered it."
          submit="Cancel order"
          danger
          path={`my/orders/${modal.order.id}/cancel`}
          onClose={() => setModal(null)}
        />
      )}
    </>
  )
}
