import { useEffect, useState } from 'react'
import { del, get, patch, post, publicGet, put, withBranch } from './api'
import {
  BranchSelect, Modal, copyText, formatDate, peso, today, useApp, useDone,
} from './ui'

/* -------------------------------------------------------------------------- */
/* Shared helpers                                                             */
/* -------------------------------------------------------------------------- */

/**
 * Owner writes must target one concrete branch. When the owner is viewing
 * "all branches", branch-scoped forms show a branch picker and send the request
 * with that branch header (without changing the whole app's branch view).
 */
function useWriteBranch(initial) {
  const { me, branches, activeBranch } = useApp()
  const concrete = me?.role === 'owner' && activeBranch !== 'all' ? String(activeBranch) : ''
  const [picked, setPicked] = useState(initial ? String(initial) : '')
  const needsPicker = me?.role === 'owner' && !concrete
  const branchId = concrete || picked || ''
  const run = fn => {
    if (needsPicker) {
      if (!branchId) throw new Error('Choose a branch first.')
      return withBranch(branchId, fn)
    }
    return fn()
  }
  return { branchId, setBranchId: setPicked, needsPicker, run, branches }
}

function BranchField({ write }) {
  if (!write.needsPicker) return null
  return (
    <div className="full">
      <BranchSelect
        branches={write.branches}
        value={write.branchId}
        onChange={write.setBranchId}
        label="Branch for this record"
        id="write-branch"
      />
      <p className="hint">You are viewing all branches. Choose the branch this record belongs to.</p>
    </div>
  )
}

const blankLine = kind => {
  switch (kind) {
    case 'delivery': return { size_id: '', full: 0, empties: 0 }
    case 'transfer': return { size_id: '', qty_full: 0, qty_empty: 0 }
    case 'order': return { size_id: '', qty: 1, price: '', deposit_amount: 0 }
    default: return { size_id: '', qty: 1, price: '', back: 0 }
  }
}

function newLines(initial, kind) {
  if (Array.isArray(initial) && initial.length) {
    return initial.map(it => ({
      ...blankLine(kind),
      ...it,
      size_id: it.size_id ?? '',
      price: it.unit_price ?? it.price ?? '',
    }))
  }
  return [blankLine(kind)]
}

function useLines(initial, kind) {
  const [lines, setLines] = useState(() => newLines(initial, kind))
  const update = (index, patch) => setLines(ls => ls.map((line, i) => i === index ? { ...line, ...patch } : line))
  const add = () => setLines(ls => [...ls, blankLine(kind)])
  const remove = index => setLines(ls => (ls.length === 1 ? [blankLine(kind)] : ls.filter((_, i) => i !== index)))
  return { lines, setLines, update, add, remove }
}

function linePrice(line, sizes) {
  if (line.price !== '' && line.price !== null && line.price !== undefined) return Number(line.price) || 0
  const size = sizes.find(s => String(s.id) === String(line.size_id))
  return Number(size?.selling_price || 0)
}

function LineEditor({ kind, sizes, lines, update, add, remove, showPrice = false, showBack = false, showDeposit = false, label = 'Cylinder size' }) {
  return (
    <div className="line-editor full">
      <div className="line-head" aria-hidden="true">
        <span>{label}</span>
        {kind === 'delivery' ? <><span>Full in</span><span>Empties out</span></> : null}
        {kind === 'transfer' ? <><span>Full</span><span>Empty</span></> : null}
        {kind === 'sale' || kind === 'order' ? <span>Qty</span> : null}
        {showPrice ? <span>Price each</span> : null}
        {showBack ? <span>Empties back</span> : null}
        {showDeposit ? <span>Deposit</span> : null}
        <span />
      </div>
      {lines.map((line, i) => {
        const used = new Set(lines.filter((_, k) => k !== i).map(l => String(l.size_id)).filter(Boolean))
        return (
          <div className="line-row" key={i}>
            <label className="sr-only">Cylinder size</label>
            <select
              value={line.size_id}
              onChange={e => {
                const size = sizes.find(s => String(s.id) === String(e.target.value))
                update(i, { size_id: e.target.value, price: showPrice ? (size?.selling_price ?? '') : line.price })
              }}
              required
            >
              <option value="">Choose size</option>
              {sizes.map(s => (
                <option key={s.id} value={s.id} disabled={used.has(String(s.id))}>
                  {s.label}
                </option>
              ))}
            </select>

            {kind === 'delivery' && (
              <>
                <label className="sr-only">Full received</label>
                <input type="number" min="0" value={line.full} onChange={e => update(i, { full: e.target.value })} />
                <label className="sr-only">Empties returned</label>
                <input type="number" min="0" value={line.empties} onChange={e => update(i, { empties: e.target.value })} />
              </>
            )}

            {kind === 'transfer' && (
              <>
                <label className="sr-only">Full cylinders</label>
                <input type="number" min="0" value={line.qty_full} onChange={e => update(i, { qty_full: e.target.value })} />
                <label className="sr-only">Empty cylinders</label>
                <input type="number" min="0" value={line.qty_empty} onChange={e => update(i, { qty_empty: e.target.value })} />
              </>
            )}

            {(kind === 'sale' || kind === 'order') && (
              <>
                <label className="sr-only">Quantity</label>
                <input type="number" min="1" max="200" value={line.qty} onChange={e => update(i, { qty: e.target.value })} required />
              </>
            )}

            {showPrice && (
              <>
                <label className="sr-only">Price each</label>
                <input type="number" min="0" step="0.01" value={line.price} onChange={e => update(i, { price: e.target.value })} placeholder="Branch price" />
              </>
            )}

            {showBack && (
              <>
                <label className="sr-only">Empties received back</label>
                <input type="number" min="0" value={line.back} onChange={e => update(i, { back: e.target.value })} />
              </>
            )}

            {showDeposit && (
              <>
                <label className="sr-only">Deposit</label>
                <input type="number" min="0" step="0.01" value={line.deposit_amount} onChange={e => update(i, { deposit_amount: e.target.value })} />
              </>
            )}

            <button type="button" className="ic" aria-label={'Remove line ' + (i + 1)} onClick={() => remove(i)} disabled={lines.length === 1}>×</button>
          </div>
        )
      })}
      <div className="line-foot">
        <button type="button" className="ghost sm" onClick={add}>+ Add another size</button>
      </div>
    </div>
  )
}

/* -------------------------------------------------------------------------- */
/* Account                                                                    */
/* -------------------------------------------------------------------------- */

export function ChangePasswordForm({ onClose }) {
  const { setMe, toast, tr } = useApp()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(event) {
    event.preventDefault()
    const f = Object.fromEntries(new FormData(event.currentTarget))
    setError('')
    if (f.new_password !== f.confirm) {
      setError('The new passwords do not match.')
      return
    }
    setBusy(true)
    try {
      const updated = await post('me/password', {
        current_password: f.current_password,
        new_password: f.new_password,
      })
      setMe(updated)
      toast(tr('common.saved') || 'Password updated')
      onClose?.()
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title={tr('common.changePassword')} submit={tr('common.save')} onClose={onClose} onSubmit={submit}>
      {error && <p className="form-error full" role="alert">{error}</p>}
      <label className="full">{tr('app.currentPassword')}
        <input type="password" name="current_password" autoComplete="current-password" required autoFocus />
      </label>
      <label className="full">{tr('app.newPassword')}
        <input type="password" name="new_password" minLength={8} autoComplete="new-password" required />
      </label>
      <label className="full">{tr('app.confirmPassword')}
        <input type="password" name="confirm" minLength={8} autoComplete="new-password" required />
      </label>
      <p className="hint full">{tr('app.passwordRule')}</p>
      {busy && <p className="hint full" role="status">{tr('common.working')}</p>}
    </Modal>
  )
}

/* -------------------------------------------------------------------------- */
/* Sales and deliveries                                                       */
/* -------------------------------------------------------------------------- */

export function SaleForm({ onClose }) {
  const { sizes, customers, me, tr } = useApp()
  const write = useWriteBranch()
  const done = useDone(onClose)
  const { lines, update, add, remove } = useLines(null, 'sale')
  const [pay, setPay] = useState('cash')
  const [customerId, setCustomerId] = useState('')
  const total = lines.reduce((sum, l) => sum + (Number(l.qty) || 0) * linePrice(l, sizes), 0)

  const chosen = customers.find(c => String(c.id) === String(customerId))

  async function submit(f) {
    if (pay === 'credit' && !customerId) throw new Error('Choose a customer for a credit sale.')
    const items = lines.map(l => ({
      size_id: Number(l.size_id),
      qty: Number(l.qty),
      price: l.price === '' ? undefined : Number(l.price),
      back: Number(l.back) || 0,
    }))
    await write.run(() => post('sales', {
      date: f.date,
      customer_id: customerId ? Number(customerId) : null,
      pay,
      items,
    }))
    done('Sale recorded')
  }

  return (
    <Modal title="Record a sale" submit="Save sale" wide onClose={onClose} onSubmit={submit}>
      <BranchField write={write} />
      <label>{tr('common.date')}<input type="date" name="date" defaultValue={today()} required /></label>
      <label>Payment
        <select value={pay} onChange={e => setPay(e.target.value)}>
          <option value="cash">Cash</option>
          <option value="credit">Credit (utang)</option>
        </select>
      </label>
      <label className="full">Customer
        <select value={customerId} onChange={e => setCustomerId(e.target.value)}>
          <option value="">Walk-in{customers.length ? '' : ' (add a customer first)'}</option>
          {customers.map(c => <option key={c.id} value={c.id}>{c.name}{c.contact ? ` · ${c.contact}` : ''}</option>)}
        </select>
      </label>
      {chosen && <p className="hint full">Current balance at this branch: <b>{peso(chosen.balance)}</b> · empties owed: <b>{chosen.empties_owed ?? 0}</b></p>}
      {me?.role !== 'owner' ? null : <p className="hint full">Prices default to the branch price list. You may override a line for a negotiated sale.</p>}
      <LineEditor kind="sale" sizes={sizes} lines={lines} update={update} add={add} remove={remove} showPrice showBack />
      <p className="total full">Total: {peso(total)}</p>
    </Modal>
  )
}

export function DeliveryForm({ onClose }) {
  const { sizes, tr } = useApp()
  const write = useWriteBranch()
  const done = useDone(onClose)
  const { lines, update, add, remove } = useLines(null, 'delivery')

  async function submit(f) {
    const items = lines.map(l => ({
      size_id: Number(l.size_id),
      full: Number(l.full) || 0,
      empties: Number(l.empties) || 0,
    }))
    if (!items.some(i => i.full + i.empties > 0)) throw new Error('Enter a full or empty quantity on at least one line.')
    await write.run(() => post('deliveries', {
      date: f.date,
      receipt: f.receipt,
      supplier: f.supplier,
      remarks: f.remarks,
      items,
    }))
    done('Plant delivery recorded')
  }

  return (
    <Modal title="Record a plant delivery" submit="Save delivery" wide onClose={onClose} onSubmit={submit}>
      <BranchField write={write} />
      <label>{tr('common.date')}<input type="date" name="date" defaultValue={today()} required /></label>
      <label>Receipt no.<input name="receipt" maxLength={50} required /></label>
      <label>Supplier<input name="supplier" maxLength={120} /></label>
      <label>Remarks<input name="remarks" maxLength={200} /></label>
      <LineEditor kind="delivery" sizes={sizes} lines={lines} update={update} add={add} remove={remove} />
      <p className="hint full">Recording empties returned to the plant cannot take the branch&rsquo;s empty count below zero.</p>
    </Modal>
  )
}

/* -------------------------------------------------------------------------- */
/* Customers and payments                                                     */
/* -------------------------------------------------------------------------- */

export function CustomerForm({ customer, onClose }) {
  const done = useDone(onClose)
  const { me, branches, tr } = useApp()
  const write = useWriteBranch()

  async function submit(f) {
    const body = {
      name: f.name,
      contact: f.contact,
      address: f.address,
      credit_limit: f.credit_limit === '' ? null : Number(f.credit_limit),
      is_reseller: f.is_reseller === 'on',
      reseller_status: f.reseller_status || 'none',
    }
    if (customer) {
      // Only the owner may reassign a customer's home branch.
      if (me?.role === 'owner') {
        body.branch_id = f.branch_id === '' ? null : Number(f.branch_id)
      }
      await put('customers/' + customer.id, body)
    } else {
      await write.run(() => post('customers', body))
    }
    done(customer ? 'Customer updated' : 'Customer added')
  }

  return (
    <Modal title={customer ? 'Edit customer' : 'Add a customer'} submit={customer ? tr('common.save') : 'Add customer'} onClose={onClose} onSubmit={submit}>
      {!customer && <BranchField write={write} />}
      {customer && me?.role === 'owner' && (
        <div className="full">
          <label>Home branch
            <select name="branch_id" defaultValue={customer.branch_id ? String(customer.branch_id) : ''}>
              <option value="">No home branch (may order from any branch)</option>
              {branches.map(b => (
                <option key={b.id} value={String(b.id)}>{b.name}{b.code ? ` (${b.code})` : ''}</option>
              ))}
            </select>
          </label>
          <p className="hint">The customer's contact and credit details are shared across all branches.</p>
        </div>
      )}
      <label className="full">Name<input name="name" maxLength={100} defaultValue={customer?.name || ''} required autoFocus /></label>
      <label>Contact number<input name="contact" maxLength={50} defaultValue={customer?.contact || ''} /></label>
      <label>Address / area<input name="address" maxLength={200} defaultValue={customer?.address || ''} /></label>
      <label>Credit limit (₱)
        <input type="number" name="credit_limit" min="0" step="0.01" defaultValue={customer?.credit_limit ?? ''} placeholder="No limit" />
      </label>
      <label>Reseller status
        <select name="reseller_status" defaultValue={customer?.reseller_status || 'none'}>
          <option value="none">Not a reseller</option>
          <option value="pending">Pending review</option>
          <option value="approved">Approved reseller</option>
          <option value="rejected">Rejected</option>
        </select>
      </label>
      <label className="check full">
        <input type="checkbox" name="is_reseller" defaultChecked={Boolean(customer?.is_reseller)} />
        This customer buys for resale
      </label>
      <p className="hint full">Credit limits apply per branch and are enforced when orders or credit sales are recorded.</p>
    </Modal>
  )
}

export function PaymentForm({ customerId = '', onClose }) {
  const { customers, tr } = useApp()
  const write = useWriteBranch()
  const done = useDone(onClose)
  const [selected, setSelected] = useState(customerId ? String(customerId) : '')
  const customer = customers.find(c => String(c.id) === String(selected))

  async function submit(f) {
    if (!selected) throw new Error('Choose a customer.')
    const amount = Number(f.amount)
    if (customer && amount > Number(customer.balance || 0) + 0.001) {
      throw new Error('Payment cannot exceed the outstanding balance of ' + peso(customer.balance) + '.')
    }
    await write.run(() => post('payments', {
      customer_id: Number(selected),
      date: f.date,
      amount,
      method: f.method,
      reference_no: f.reference_no,
      notes: f.notes,
    }))
    done('Payment recorded')
  }

  return (
    <Modal title="Record a payment" submit="Save payment" onClose={onClose} onSubmit={submit}>
      <BranchField write={write} />
      <label className="full">Customer
        <select value={selected} onChange={e => setSelected(e.target.value)} required>
          <option value="">Choose customer</option>
          {customers.map(c => <option key={c.id} value={c.id}>{c.name} · {peso(c.balance)} owed</option>)}
        </select>
      </label>
      {customer && <p className="hint full">Outstanding at this branch: <b>{peso(customer.balance)}</b>.</p>}
      <label>{tr('common.date')}<input type="date" name="date" defaultValue={today()} required /></label>
      <label>Amount (₱)<input type="number" name="amount" min="0.01" step="0.01" max={customer ? Number(customer.balance) : undefined} required /></label>
      <label>Method
        <select name="method" defaultValue="cash">
          <option value="cash">Cash</option>
          <option value="gcash">GCash</option>
          <option value="maya">Maya</option>
          <option value="bank">Bank transfer</option>
          <option value="other">Other</option>
        </select>
      </label>
      <label>Reference no.<input name="reference_no" maxLength={80} /></label>
      <label className="full">Notes<input name="notes" maxLength={200} /></label>
    </Modal>
  )
}

/* -------------------------------------------------------------------------- */
/* Stock                                                                      */
/* -------------------------------------------------------------------------- */

export function AdjustForm({ onClose }) {
  const { sizes, tr } = useApp()
  const write = useWriteBranch()
  const done = useDone(onClose)

  async function submit(f) {
    if ((Number(f.full) || 0) === 0 && (Number(f.empty) || 0) === 0) {
      throw new Error('Enter a non-zero change.')
    }
    await write.run(() => post('adjustments', {
      date: f.date,
      size_id: Number(f.size),
      reason: f.reason,
      note: f.note,
      full: Number(f.full) || 0,
      empty: Number(f.empty) || 0,
    }))
    done('Stock adjustment saved')
  }

  return (
    <Modal title="Opening count or stock correction" submit="Save adjustment" onClose={onClose} onSubmit={submit}>
      <BranchField write={write} />
      <label>{tr('common.date')}<input type="date" name="date" defaultValue={today()} required /></label>
      <label>Cylinder size
        <select name="size" required>
          {sizes.map(s => <option key={s.id} value={s.id}>{s.label}</option>)}
        </select>
      </label>
      <label>Reason
        <select name="reason" defaultValue="Opening count">
          <option>Opening count</option>
          <option>Damaged</option>
          <option>Lost</option>
          <option>Correction</option>
          <option>Other</option>
        </select>
      </label>
      <label>Note<input name="note" maxLength={200} /></label>
      <label>Change in full<input type="number" name="full" defaultValue="0" /></label>
      <label>Change in empty<input type="number" name="empty" defaultValue="0" /></label>
      <p className="hint full">Use positive numbers to add cylinders and negative numbers to remove them. The adjustment is voidable, never deleted.</p>
    </Modal>
  )
}

export function SizeForm({ onClose }) {
  const { tr } = useApp()
  const done = useDone(onClose)

  async function submit(f) {
    await post('sizes', { label: f.label, price: Number(f.price), valve_type: f.valve_type })
    done('Cylinder size added')
  }

  return (
    <Modal title="Add a cylinder size" submit="Add size" onClose={onClose} onSubmit={submit}>
      <label>Size name<input name="label" maxLength={30} placeholder="e.g. 11kg" required autoFocus /></label>
      <label>Standard price (₱)<input type="number" name="price" min="0" step="0.01" required /></label>
      <label className="full">Valve type
        <select name="valve_type" defaultValue="unspecified">
          <option value="unspecified">Not specified</option>
          <option value="snap_on">Snap-on</option>
          <option value="pol">POL</option>
          <option value="both">Both</option>
        </select>
      </label>
      <p className="hint full">{tr('common.optional')}: branch price overrides can be set from Branches after the size is created.</p>
    </Modal>
  )
}

export function PriceForm({ branchId, onClose }) {
  const { branches, tr } = useApp()
  const done = useDone(onClose)
  const [selected, setSelected] = useState(branchId ? String(branchId) : '')
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [rows, setRows] = useState([])

  useEffect(() => {
    if (!selected) { setData(null); setRows([]); return undefined }
    let live = true
    setError('')
    get('branches/' + selected + '/prices')
      .then(result => {
        if (!live) return
        setData(result)
        setRows((result.prices || []).map(p => ({ ...p, price: String(p.price) })))
      })
      .catch(err => { if (live) setError(err.message) })
    return () => { live = false }
  }, [selected])

  async function submit() {
    if (!selected) throw new Error('Choose a branch.')
    const prices = rows.map(r => ({ size_id: Number(r.size_id), price: Number(r.price) }))
    await put('branches/' + selected + '/prices', { prices })
    done('Branch prices updated')
  }

  return (
    <Modal title="Branch price list" submit="Save prices" wide onClose={onClose} onSubmit={submit}>
      {!branchId && (
        <BranchSelect branches={branches} value={selected} onChange={setSelected} label="Branch" id="price-branch" />
      )}
      {error && <p className="form-error full" role="alert">{error}</p>}
      {!selected && <p className="hint full">Choose a branch to see its price list.</p>}
      {selected && data && (
        <>
          <p className="hint full">{data.branch?.name}{data.branch?.code ? ` (${data.branch.code})` : ''}. Leave a row at the standard price to remove the override.</p>
          <div className="line-editor full">
            <div className="line-head" aria-hidden="true"><span>Cylinder size</span><span>Standard</span><span>Branch price</span><span>Status</span></div>
            {rows.map((row, i) => (
              <div className="line-row price-row" key={row.size_id}>
                <strong>{row.label}</strong>
                <span>{peso(row.global_price)}</span>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={row.price}
                  onChange={e => setRows(rs => rs.map((r, k) => k === i ? { ...r, price: e.target.value } : r))}
                />
                <span className="muted">{Number(row.price) === Number(row.global_price) ? tr('common.globalPrice') : tr('common.branchPrice')}</span>
              </div>
            ))}
          </div>
        </>
      )}
    </Modal>
  )
}

/* -------------------------------------------------------------------------- */
/* Branches and users                                                         */
/* -------------------------------------------------------------------------- */

export function BranchForm({ branch, onClose }) {
  const { tr } = useApp()
  const done = useDone(onClose)

  async function submit(f) {
    const body = {
      name: f.name,
      code: f.code,
      address: f.address,
      contact_phone: f.contact_phone,
      service_area_notes: f.service_area_notes,
      operating_hours: f.operating_hours,
      cutoff_time: f.cutoff_time,
      max_order_qty: Number(f.max_order_qty) || 1,
    }
    if (branch) await put('branches/' + branch.id, body)
    else await post('branches', body)
    done(branch ? 'Branch updated' : 'Branch created')
  }

  return (
    <Modal title={branch ? 'Edit branch' : 'Add a branch'} submit={branch ? tr('common.save') : 'Create branch'} onClose={onClose} onSubmit={submit}>
      <label className="full">Branch name<input name="name" maxLength={100} defaultValue={branch?.name || ''} required autoFocus /></label>
      <label>Branch code<input name="code" maxLength={20} defaultValue={branch?.code || ''} placeholder="MAIN" required /></label>
      <label>Contact number<input name="contact_phone" maxLength={40} defaultValue={branch?.contact_phone || ''} /></label>
      <label className="full">Address<input name="address" maxLength={200} defaultValue={branch?.address || ''} /></label>
      <label>Operating hours<input name="operating_hours" maxLength={200} defaultValue={branch?.operating_hours || ''} placeholder="e.g. 8:00 AM - 6:00 PM" /></label>
      <label>Same-day cut-off
        <input type="time" name="cutoff_time" defaultValue={branch?.cutoff_time || ''} />
      </label>
      <label>Max cylinders per order
        <input type="number" name="max_order_qty" min="1" max="500" defaultValue={branch?.max_order_qty ?? 20} required />
      </label>
      <label className="full">Service area notes
        <textarea name="service_area_notes" rows={3} maxLength={500} defaultValue={branch?.service_area_notes || ''} />
      </label>
    </Modal>
  )
}

export function UserForm({ user, onClose }) {
  const { branches, tr } = useApp()
  const done = useDone(onClose)
  const [role, setRole] = useState(user?.role || 'staff')

  async function submit(f) {
    if (role === 'staff' && !f.branch_id) throw new Error('Choose a branch for staff.')
    if (user) {
      await put('users/' + user.id, {
        name: f.name,
        username: f.username,
        role,
        branch_id: role === 'staff' ? Number(f.branch_id) : null,
        is_active: f.is_active === 'on',
      })
      done('User updated')
      return
    }
    await post('users', {
      name: f.name,
      username: f.username,
      password: f.password,
      role,
      branch_id: role === 'staff' ? Number(f.branch_id) : null,
      contact: f.contact,
      address: f.address,
    })
    done('User created. Share the temporary password securely; they must change it on first login.')
  }

  return (
    <Modal title={user ? 'Edit user' : 'Add a user'} submit={user ? tr('common.save') : 'Create user'} onClose={onClose} onSubmit={submit}>
      <label>Full name<input name="name" maxLength={100} defaultValue={user?.name || ''} required autoFocus /></label>
      <label>Username<input name="username" maxLength={30} pattern="[A-Za-z0-9_.-]+" defaultValue={user?.username || ''} required /></label>
      {!user && <label className="full">Temporary password<input type="password" name="password" minLength={8} autoComplete="new-password" required /></label>}
      <label>Role
        <select value={role} onChange={e => setRole(e.target.value)}>
          <option value="staff">Staff</option>
          <option value="owner">Owner</option>
          <option value="customer">Customer</option>
        </select>
      </label>
      {role === 'staff' && (
        <label>Assigned branch
          <select name="branch_id" defaultValue={user?.branch_id ? String(user.branch_id) : ''} required>
            <option value="">Choose branch</option>
            {branches.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
          </select>
        </label>
      )}
      {!user && role === 'customer' && (
        <>
          <label>Contact number<input name="contact" maxLength={50} /></label>
          <label>Address<input name="address" maxLength={200} /></label>
        </>
      )}
      {user && (
        <label className="check full">
          <input type="checkbox" name="is_active" defaultChecked={Number(user.is_active) === 1} />
          Account is active
        </label>
      )}
      <p className="hint full">Staff must be assigned to exactly one branch. Changing a role or branch signs that user out everywhere.</p>
    </Modal>
  )
}

export function ResetPasswordForm({ user, onClose }) {
  const { toast } = useApp()
  const done = useDone(onClose)
  const [temp, setTemp] = useState('')
  const [busy, setBusy] = useState(false)

  async function reset() {
    setBusy(true)
    try {
      const result = await post('users/' + user.id + '/reset-password')
      setTemp(result.temporary_password)
      toast('Temporary password created')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      title={'Reset password for ' + user.name}
      submit={temp ? 'Done' : 'Create temporary password'}
      onClose={() => { if (temp) done('Password reset'); else onClose?.() }}
      onSubmit={async () => { if (!temp) await reset() }}
      submitDisabled={busy}
    >
      {!temp && <p className="full">This signs the user out everywhere. Their next login requires a new password.</p>}
      {temp && (
        <div className="full temp-box">
          <p>Temporary password (shown once):</p>
          <code>{temp}</code>
          <button type="button" className="ghost sm" onClick={() => copyText(temp).then(ok => toast(ok ? 'Copied' : 'Copy failed', !ok))}>
            Copy password
          </button>
          <p className="hint">Hand it over through a secure channel. The user must change it at first login.</p>
        </div>
      )}
      {busy && <p className="hint full" role="status">Creating…</p>}
    </Modal>
  )
}

/* -------------------------------------------------------------------------- */
/* Orders                                                                     */
/* -------------------------------------------------------------------------- */

export function OrderForm({ order, onClose }) {
  const { branches, sizes: globalSizes, tr } = useApp()
  const done = useDone(onClose)
  const [branchId, setBranchId] = useState(order?.branch_id ? String(order.branch_id) : (branches[0]?.id ? String(branches[0].id) : ''))
  const [sizes, setSizes] = useState(order ? globalSizes : [])
  const [loadingSizes, setLoadingSizes] = useState(false)
  const { lines, setLines, update, add, remove } = useLines(order?.items || null, 'order')
  const [pay, setPay] = useState(order?.payment_type || 'cash')

  useEffect(() => {
    if (!branchId) { setSizes([]); return undefined }
    let live = true
    setLoadingSizes(true)
    publicGet('sizes?branch_id=' + encodeURIComponent(branchId))
      .then(list => { if (live) setSizes(Array.isArray(list) ? list : []) })
      .catch(() => { if (live) setSizes([]) })
      .finally(() => { if (live) setLoadingSizes(false) })
    return () => { live = false }
  }, [branchId])

  // If the branch changes, keep lines but reset any size that is not sold there.
  useEffect(() => {
    if (!sizes.length) return
    setLines(ls => ls.map(l => (
      l.size_id && !sizes.some(s => String(s.id) === String(l.size_id))
        ? { ...l, size_id: '' }
        : l
    )))
  }, [sizes, setLines])

  const total = lines.reduce((sum, l) => sum + (Number(l.qty) || 0) * linePrice(l, sizes) + (Number(l.deposit_amount) || 0), 0)

  async function submit(f) {
    if (!branchId) throw new Error('Choose a branch.')
    const items = lines.map(l => ({
      size_id: Number(l.size_id),
      qty: Number(l.qty),
      deposit_amount: Number(l.deposit_amount) || 0,
    }))
    const body = {
      branch_id: Number(branchId),
      pay,
      notes: f.notes,
      requested_date: f.requested_date,
      time_slot: f.time_slot,
      delivery_address: f.delivery_address,
      valve_type: f.valve_type,
      items,
    }
    if (order) await patch('my/orders/' + order.id, body)
    else await post('my/orders', body)
    done(order ? 'Order updated' : 'Order sent')
  }

  return (
    <Modal title={order ? 'Edit pending order' : 'Place an order'} submit={order ? tr('common.save') : 'Send order'} wide onClose={onClose} onSubmit={submit}>
      <label className="full">Branch
        <select value={branchId} onChange={e => setBranchId(e.target.value)} required>
          <option value="">Choose branch</option>
          {branches.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
      </label>
      <label>{tr('common.date')} requested
        <input type="date" name="requested_date" min={today()} defaultValue={order?.requested_date || today()} required />
      </label>
      <label>Time slot
        <input name="time_slot" maxLength={40} defaultValue={order?.time_slot || ''} placeholder="e.g. Morning" />
      </label>
      <label className="full">Delivery address
        <input name="delivery_address" maxLength={200} defaultValue={order?.delivery_address || ''} placeholder="Leave blank to use your account address" />
      </label>
      <label>Payment
        <select value={pay} onChange={e => setPay(e.target.value)}>
          <option value="cash">Cash on delivery</option>
          <option value="credit">Credit (utang)</option>
        </select>
      </label>
      <label>Valve type
        <select name="valve_type" defaultValue={order?.valve_type || 'na'}>
          <option value="na">Not sure / not applicable</option>
          <option value="snap_on">Snap-on</option>
          <option value="pol">POL</option>
        </select>
      </label>
      <label className="full">Notes<input name="notes" maxLength={200} defaultValue={order?.notes || ''} placeholder="Landmark, gate colour, etc." /></label>
      {loadingSizes && <p className="hint full" role="status">Loading branch prices…</p>}
      {!loadingSizes && !sizes.length && <p className="form-error full">This branch has no available cylinder sizes right now.</p>}
      <LineEditor kind="order" sizes={sizes} lines={lines} update={update} add={add} remove={remove} showPrice showDeposit />
      <p className="total full">Estimated total: {peso(total)}</p>
      <p className="hint full">Final prices are set by the branch when the order is approved. {order ? 'Only pending orders can be edited.' : ''}</p>
    </Modal>
  )
}

export function DeliverForm({ order, onClose }) {
  const done = useDone(onClose)
  const [items, setItems] = useState(() => (order.items || []).map(it => ({ ...it, empties_received: 0 })))

  async function submit(f) {
    await withBranch(order.branch_id, () => post('orders/' + order.id + '/deliver', {
      proof_of_delivery: f.proof_of_delivery,
      items: items.map(it => ({ size_id: it.size_id, empties_received: Number(it.empties_received) || 0 })),
    }))
    done('Delivered and recorded as a sale')
  }

  return (
    <Modal title="Deliver this order" submit="Mark delivered" wide onClose={onClose} onSubmit={submit}>
      <p className="full">
        Deliver to <b>{order.customer}</b>{order.delivery_address ? ' at ' + order.delivery_address : ''}.
        This records a sale, deducts full cylinders and updates the customer&rsquo;s empties.
      </p>
      <div className="line-editor full">
        <div className="line-head" aria-hidden="true"><span>Cylinder size</span><span>Qty</span><span>Empties received</span></div>
        {items.map((it, i) => (
          <div className="line-row" key={it.size_id}>
            <strong>{it.size}</strong>
            <span>{it.qty}</span>
            <input
              type="number"
              min="0"
              max={it.qty}
              value={it.empties_received}
              onChange={e => setItems(list => list.map((x, k) => k === i ? { ...x, empties_received: e.target.value } : x))}
              aria-label={'Empties received for ' + it.size}
            />
          </div>
        ))}
      </div>
      <label className="full">Proof of delivery (optional)
        <input name="proof_of_delivery" maxLength={255} placeholder="Photo filename, signature reference, or note" />
      </label>
      <p className="hint full">A voided sale is possible afterwards, but stock movements are never hard-deleted.</p>
    </Modal>
  )
}

/* -------------------------------------------------------------------------- */
/* Transfers                                                                  */
/* -------------------------------------------------------------------------- */

export function TransferForm({ onClose }) {
  const { me, branches, sizes, tr } = useApp()
  const done = useDone(onClose)
  const staffBranch = me?.role === 'staff' ? String(me.branch_id || '') : ''
  const [fromId, setFromId] = useState(staffBranch || (branches[0]?.id ? String(branches[0].id) : ''))
  const [toId, setToId] = useState(
    branches.find(b => String(b.id) !== String(staffBranch || branches[0]?.id))?.id ?? ''
  )
  const { lines, update, add, remove } = useLines(null, 'transfer')
  const [idempotency] = useState(() => (
    globalThis.crypto?.randomUUID ? globalThis.crypto.randomUUID() : 'trf-' + Date.now() + '-' + Math.random().toString(16).slice(2)
  ))

  async function submit(f) {
    if (String(fromId) === String(toId)) throw new Error('Choose two different branches.')
    const items = lines.map(l => ({
      size_id: Number(l.size_id),
      qty_full: Number(l.qty_full) || 0,
      qty_empty: Number(l.qty_empty) || 0,
    }))
    if (!items.some(i => i.qty_full + i.qty_empty > 0)) throw new Error('Enter a full or empty quantity on at least one line.')
    await post('transfers', {
      from_branch_id: Number(fromId),
      to_branch_id: Number(toId),
      notes: f.notes,
      idempotency_key: idempotency,
      items,
    })
    done('Transfer requested')
  }

  return (
    <Modal title="Request a stock transfer" submit="Request transfer" wide onClose={onClose} onSubmit={submit}>
      <label>From branch
        <select value={fromId} onChange={e => setFromId(e.target.value)} disabled={Boolean(staffBranch)} required>
          <option value="">Choose branch</option>
          {branches.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
      </label>
      <label>To branch
        <select value={toId} onChange={e => setToId(e.target.value)} required>
          <option value="">Choose branch</option>
          {branches.filter(b => String(b.id) !== String(fromId)).map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
      </label>
      <label className="full">Notes<input name="notes" maxLength={200} placeholder="Why is this stock needed?" /></label>
      <LineEditor kind="transfer" sizes={sizes} lines={lines} update={update} add={add} remove={remove} />
      <p className="hint full">Stock leaves the sending branch only when shipped, and arrives when the receiving branch confirms receipt.</p>
    </Modal>
  )
}

/* -------------------------------------------------------------------------- */
/* Generic confirmations                                                      */
/* -------------------------------------------------------------------------- */

export function ConfirmPost({ title, text, submit = 'Continue', path, danger = false, body, branchId, onClose }) {
  const done = useDone(onClose)
  async function go() {
    const send = () => post(path, body || {})
    await (branchId ? withBranch(branchId, send) : send())
    done()
  }
  return (
    <Modal title={title} submit={submit} danger={danger} onClose={onClose} onSubmit={go}>
      {text && <p>{text}</p>}
    </Modal>
  )
}

export function ConfirmDelete({ title, text, submit = 'Confirm', path, danger = true, branchId, onClose }) {
  const done = useDone(onClose)
  async function go() {
    const send = () => del(path)
    await (branchId ? withBranch(branchId, send) : send())
    done()
  }
  return (
    <Modal title={title} submit={submit} danger={danger} onClose={onClose} onSubmit={go}>
      {text && <p>{text}</p>}
    </Modal>
  )
}

export function ReasonPostForm({ title, text, submit = 'Continue', path, commitLabel = 'Reason', danger = false, onClose }) {
  const done = useDone(onClose)
  return (
    <Modal title={title} submit={submit} danger={danger} onClose={onClose} onSubmit={async f => {
      await post(path, { reason: f.reason })
      done()
    }}>
      {text && <p>{text}</p>}
      <label className="full">{commitLabel}<input name="reason" minLength={3} maxLength={200} required autoFocus /></label>
    </Modal>
  )
}

export function VoidForm({ path, label = 'Void this record', branchId, onClose }) {
  const done = useDone(onClose)
  async function go(f) {
    const send = () => del(path, { reason: f.reason })
    await (branchId ? withBranch(branchId, send) : send())
    done('Voided')
  }
  return (
    <Modal title={label} submit="Void" danger onClose={onClose} onSubmit={go}>
      <p>The record stays in the ledger for audit, and reversal stock movements are written.</p>
      <label className="full">Reason<input name="reason" minLength={3} maxLength={200} required autoFocus /></label>
    </Modal>
  )
}

/** Owner-only void button + confirmation modal. */
export function useVoid() {
  const { me } = useApp()
  const [target, setTarget] = useState(null)
  const button = (path, { label = 'Void', branchId, text } = {}) => (
    me?.role === 'owner'
      ? <button type="button" className="sm link-danger" onClick={() => setTarget({ path, label, branchId, text })}>{label}</button>
      : null
  )
  const modal = target
    ? <VoidForm path={target.path} label={target.text || 'Void this record'} branchId={target.branchId} onClose={() => setTarget(null)} />
    : null
  return [button, modal]
}

/* -------------------------------------------------------------------------- */
/* Receipt                                                                    */
/* -------------------------------------------------------------------------- */

export function ReceiptModal({ saleId, onClose }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    let live = true
    get('sales/' + saleId + '/receipt')
      .then(result => { if (live) setData(result) })
      .catch(err => { if (live) setError(err.message) })
    return () => { live = false }
  }, [saleId])

  return (
    <Modal title="Sale receipt" submit="Close" onClose={onClose} onSubmit={async () => onClose?.()}>
      {error && <p className="form-error full" role="alert">{error}</p>}
      {!data && !error && <p className="hint full" role="status">Loading receipt…</p>}
      {data && (
        <div className="receipt full">
          <h4>{data.branch?.name}</h4>
          <p className="muted">{data.branch?.address}</p>
          {data.branch?.contact_phone && <p className="muted">{data.branch.contact_phone}</p>}
          <hr />
          <dl>
            <div><dt>Receipt</dt><dd>{data.sale_no}</dd></div>
            <div><dt>{formatDate('') === '—' ? 'Date' : 'Date'}</dt><dd>{formatDate(data.sale_date)}</dd></div>
            <div><dt>Customer</dt><dd>{data.customer || 'Walk-in'}</dd></div>
            <div><dt>Payment</dt><dd>{data.payment_type}</dd></div>
          </dl>
          <table>
            <thead><tr><th>Item</th><th className="r">Qty</th><th className="r">Price</th><th className="r">Total</th></tr></thead>
            <tbody>
              {(data.items || []).map(it => (
                <tr key={it.id}>
                  <td>{it.size}</td><td className="r">{it.qty}</td>
                  <td className="r">{peso(it.unit_price)}</td><td className="r">{peso(it.line_total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="receipt-total">Total: {peso(data.total_amount)}</p>
          {data.is_void ? <p className="neg">This sale was voided: {data.void_reason}</p> : null}
          <button type="button" className="ghost sm" onClick={() => window.print()}>Print</button>
        </div>
      )}
    </Modal>
  )
}
