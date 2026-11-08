import { createContext, useCallback, useContext, useEffect, useId, useRef, useState } from 'react'
import { get, getPage, query } from './api'

export const Ctx = createContext(null)
export const useApp = () => useContext(Ctx)

/* -------------------------------------------------------------------------- */
/* Formatting                                                                 */
/* -------------------------------------------------------------------------- */

export const peso = n => '₱' + Number(n || 0).toLocaleString('en-PH', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

export const num = n => Number(n || 0).toLocaleString('en-PH')

export const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })

export const monthStart = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}

export function formatDate(value) {
  if (!value) return '—'
  return String(value).slice(0, 10)
}

export function formatDateTime(value) {
  if (!value) return '—'
  const s = String(value)
  return s.length > 10 ? s.slice(0, 16) : s
}

export const itemsSummary = (items = [], kind = 'sale') => {
  if (!Array.isArray(items) || !items.length) return '—'
  return items.map(it => {
    if (kind === 'delivery') return `${it.size}: ${it.full_received} in / ${it.empties_returned} out`
    if (kind === 'transfer') return `${it.size}: ${it.qty_full} full / ${it.qty_empty} empty`
    return `${it.qty} × ${it.size}`
  }).join(', ')
}

export const itemsQty = (items = []) => items.reduce((n, it) => n + Number(it.qty || 0), 0)

/* -------------------------------------------------------------------------- */
/* Data loading                                                               */
/* -------------------------------------------------------------------------- */

/**
 * Load an API path. Reloads when the global refresh tick changes (after a
 * mutation or branch switch) or when local filters change.
 * Returns { data, loading, error, reload }.
 */
export function useApi(path, options = {}) {
  const { tick } = useApp() || {}
  const enabled = options.enabled !== false
  const depKey = JSON.stringify(options.deps || [])
  const [nonce, setNonce] = useState(0)
  const [state, setState] = useState({ data: null, loading: Boolean(path) && enabled, error: null })

  useEffect(() => {
    if (!path || !enabled) {
      setState({ data: null, loading: false, error: null })
      return undefined
    }
    let live = true
    setState(s => ({ ...s, loading: true, error: null }))
    get(path)
      .then(data => { if (live) setState({ data, loading: false, error: null }) })
      .catch(error => {
        if (!live) return
        if (error?.status === 401) return
        setState(s => ({ ...s, loading: false, error }))
      })
    return () => { live = false }
  }, [path, tick, enabled, nonce, depKey])

  const reload = useCallback(() => setNonce(n => n + 1), [])
  return { ...state, reload }
}

/**
 * Paginated variant of useApi for list endpoints that return X-Total-Count.
 * `basePath` must not include page/per_page; filters belong in the path.
 */
export function usePagedApi(basePath, { deps = [], perPage = 20, enabled = true } = {}) {
  const { tick } = useApp() || {}
  const depKey = JSON.stringify(deps)
  const [page, setPage] = useState(1)
  const [nonce, setNonce] = useState(0)
  const [state, setState] = useState({ rows: [], total: 0, pages: 1, loading: Boolean(basePath) && enabled, error: null })

  // New filters always start from the first page.
  useEffect(() => { setPage(1) }, [depKey, basePath])

  useEffect(() => {
    if (!basePath || !enabled) {
      setState({ rows: [], total: 0, pages: 1, loading: false, error: null })
      return undefined
    }
    let live = true
    setState(s => ({ ...s, loading: true, error: null }))
    const sep = basePath.includes('?') ? '&' : '?'
    getPage(basePath + sep + query({ page, per_page: perPage }))
      .then(result => {
        if (!live) return
        setState({ rows: result.data, total: result.total, pages: result.pages, loading: false, error: null })
      })
      .catch(error => {
        if (!live || error?.status === 401) return
        setState(s => ({ ...s, loading: false, error }))
      })
    return () => { live = false }
  }, [basePath, page, perPage, tick, nonce, enabled])

  const reload = useCallback(() => setNonce(n => n + 1), [])
  return { ...state, page, setPage, reload }
}

/** Legacy helper: returns only the loaded data. */
export function useLoad(path) {
  return useApi(path).data
}

export function useDone(onClose) {
  const { refresh, toast } = useApp()
  return (msg = 'Saved') => {
    refresh()
    toast(msg)
    onClose?.()
  }
}

/* -------------------------------------------------------------------------- */
/* Modal                                                                      */
/* -------------------------------------------------------------------------- */

export function Modal({
  title, onClose, onSubmit, submit = 'Save', danger = false, wide = false,
  children, intro, submitDisabled = false,
}) {
  const ref = useRef(null)
  const { toast } = useApp()
  const [busy, setBusy] = useState(false)
  const titleId = useId()

  useEffect(() => {
    const el = ref.current
    if (el && !el.open) el.showModal()
    return () => { if (el?.open) el.close() }
  }, [])

  async function go(ev) {
    ev.preventDefault()
    if (busy) return
    setBusy(true)
    try {
      await onSubmit(Object.fromEntries(new FormData(ev.currentTarget)))
    } catch (err) {
      toast(err.message || 'Could not save.', true)
    } finally {
      setBusy(false)
    }
  }

  return (
    <dialog
      ref={ref}
      className={wide ? 'wide' : undefined}
      aria-labelledby={titleId}
      onClose={() => onClose?.()}
      onClick={e => e.target === ref.current && ref.current.close()}
    >
      <form onSubmit={go}>
        <header>
          <h3 id={titleId}>{title}</h3>
          <button type="button" className="ic" aria-label="Close" onClick={() => ref.current?.close()}>×</button>
        </header>
        <div className="fields">
          {intro && <p className="hint">{intro}</p>}
          {children}
        </div>
        <footer>
          <button type="button" className="ghost" onClick={() => ref.current?.close()}>Cancel</button>
          <button className={danger ? 'danger' : 'primary'} disabled={busy || submitDisabled}>
            {busy ? 'Working…' : submit}
          </button>
        </footer>
      </form>
    </dialog>
  )
}

/* -------------------------------------------------------------------------- */
/* Page building blocks                                                       */
/* -------------------------------------------------------------------------- */

export function PageHeader({ title, sub, children, meta }) {
  return (
    <header className="top">
      <div>
        <h1>{title}</h1>
        {sub && <p className="muted">{sub}</p>}
        {meta && <div className="page-meta">{meta}</div>}
      </div>
      {children && <div className="actions">{children}</div>}
    </header>
  )
}

export function Stat({ label, value, hint, tone }) {
  return (
    <div className={'card stat' + (tone ? ' tone-' + tone : '')}>
      <small>{label}</small>
      <strong>{value}</strong>
      {hint && <span className="stat-hint">{hint}</span>}
    </div>
  )
}

export function Table({ head, rows, empty = 'Nothing to show yet.', loading = false, error = null, onRetry }) {
  if (loading && (!rows || !rows.length)) return <Loader />
  if (error) return <ErrorState error={error} onRetry={onRetry} />
  if (!rows || !rows.length) return <div className="card empty">{empty}</div>
  return (
    <div className="card tbl">
      <div className="scroll">
        <table>
          <thead>
            <tr>{head.map((h, i) => <th key={i} className={h.r ? 'r' : ''}>{h.t || h}</th>)}</tr>
          </thead>
          <tbody>{rows}</tbody>
        </table>
      </div>
    </div>
  )
}

const TONES = {
  delivered: 'ok', received: 'ok', cash: 'ok', active: 'ok', approved: 'info',
  pending: 'warn', requested: 'warn', credit: 'warn', in_transit: 'info',
  cancelled: 'bad', void: 'bad', inactive: 'muted',
  owner: 'info', staff: 'info', customer: 'info',
}

export function Badge({ t, label }) {
  const { tr } = useApp() || {}
  const value = String(t ?? '')
  const text = label || (tr ? tr('status.' + value, value.replaceAll('_', ' ')) : value.replaceAll('_', ' '))
  return <span className={'badge b-' + value + ' tone-' + (TONES[value] || 'muted')}>{text}</span>
}

export function Loader({ label }) {
  return (
    <div className="loading" role="status" aria-live="polite">
      <span className="spinner" aria-hidden="true" />
      <span>{label || 'Loading…'}</span>
    </div>
  )
}

export function ErrorState({ error, onRetry }) {
  const { tr } = useApp() || {}
  return (
    <div className="card errorbox" role="alert">
      <div>
        <strong>{tr ? tr('app.errorTitle') : 'Something went wrong'}</strong>
        <p>{error?.message || 'The request could not be completed.'}</p>
      </div>
      {onRetry && <button className="ghost sm" onClick={onRetry}>{tr ? tr('common.retry') : 'Try again'}</button>}
    </div>
  )
}

export function EmptyState({ title, text, action }) {
  return (
    <div className="card empty">
      {title && <strong>{title}</strong>}
      {text && <p className="muted">{text}</p>}
      {action}
    </div>
  )
}

export function Pagination({ page, pages, total, onPage }) {
  if (!total || pages <= 1) return null
  return (
    <nav className="pager" aria-label="Pagination">
      <span className="muted">{num(total)} record{total === 1 ? '' : 's'} · page {page} of {pages}</span>
      <div className="pager-btns">
        <button type="button" className="ghost sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>Previous</button>
        <button type="button" className="ghost sm" disabled={page >= pages} onClick={() => onPage(page + 1)}>Next</button>
      </div>
    </nav>
  )
}

export function FilterBar({ children, onReset }) {
  return (
    <form className="card filters" role="search" onSubmit={e => e.preventDefault()}>
      {children}
      {onReset && <button type="button" className="ghost sm" onClick={onReset}>Reset</button>}
    </form>
  )
}

export function BranchSelect({ branches = [], value, onChange, includeAll = false, label = 'Branch', id, disabled = false }) {
  const { tr } = useApp() || {}
  const selectId = id || 'branch-select'
  return (
    <label className="branch-select" htmlFor={selectId}>
      {label}
      <select id={selectId} value={value ?? ''} onChange={e => onChange?.(e.target.value)} disabled={disabled}>
        {includeAll && <option value="all">{tr ? tr('common.allBranches') : 'All branches'}</option>}
        {!includeAll && <option value="">{tr ? tr('common.selectBranch') : 'Select a branch'}</option>}
        {branches.map(b => <option key={b.id} value={String(b.id)}>{b.name}{b.code ? ` (${b.code})` : ''}</option>)}
      </select>
    </label>
  )
}

export function LangToggle() {
  const { lang, setLang, tr } = useApp() || {}
  if (!setLang) return null
  return (
    <button
      type="button"
      className="lang-toggle"
      title={tr ? tr('common.language') : 'Language'}
      aria-label={tr ? tr('common.language') : 'Language'}
      onClick={() => setLang(lang === 'en' ? 'fil' : 'en')}
    >
      {lang === 'en' ? 'FIL' : 'EN'}
    </button>
  )
}

export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    return false
  }
}

/** Small helper for building a row of action buttons. */
export const Acts = ({ children }) => <td className="acts">{children}</td>
