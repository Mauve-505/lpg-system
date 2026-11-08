import { Component, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Routes, Route, Navigate, Link, NavLink, useParams } from 'react-router-dom'
import { clearCsrfToken, ensureCsrf, get, hooks, post, publicGet, setBranch, setCsrfToken } from './api'
import { Ctx, useApp } from './ui'
import { makeT, readLang, saveLang } from './i18n'
import { SITE } from './site/content'
import Landing from './site/Landing'
import Privacy from './site/Privacy'
import Terms from './site/Terms'
import Auth from './Auth'
import {
  Dashboard, Orders, Deliveries, Sales, Payments, Customers, Stock,
  Transfers, Reports, Branches, Users, Audit, Shop,
} from './pages'
import { ChangePasswordForm } from './forms'

const PAGES = {
  dashboard: { label: 'nav.dashboard', Comp: Dashboard, roles: ['owner', 'staff'] },
  orders: { label: 'nav.orders', Comp: Orders, roles: ['owner', 'staff'] },
  sales: { label: 'nav.sales', Comp: Sales, roles: ['owner', 'staff'] },
  deliveries: { label: 'nav.deliveries', Comp: Deliveries, roles: ['owner', 'staff'] },
  customers: { label: 'nav.customers', Comp: Customers, roles: ['owner', 'staff'] },
  payments: { label: 'nav.payments', Comp: Payments, roles: ['owner', 'staff'] },
  stock: { label: 'nav.stock', Comp: Stock, roles: ['owner'] },
  transfers: { label: 'nav.transfers', Comp: Transfers, roles: ['owner', 'staff'] },
  reports: { label: 'nav.reports', Comp: Reports, roles: ['owner', 'staff'] },
  branches: { label: 'nav.branches', Comp: Branches, roles: ['owner'] },
  users: { label: 'nav.users', Comp: Users, roles: ['owner'] },
  audit: { label: 'nav.audit', Comp: Audit, roles: ['owner'] },
  shop: { label: 'nav.shop', Comp: Shop, roles: ['customer'] },
}

class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidCatch(error, info) {
    // Keep the stack available in the console for support without exposing it
    // in the UI.
    console.error('Application error', error, info)
  }

  render() {
    if (this.state.error) {
      return (
        <div className="crash">
          <div className="card errorbox" role="alert">
            <div>
              <strong>Something went wrong.</strong>
              <p>The page could not be displayed. Reload to try again.</p>
            </div>
            <button className="primary" onClick={() => window.location.reload()}>Reload</button>
          </div>
        </div>
      )
    }
    return this.props.children
  }
}

function ForcePassword() {
  const { me, setMe, setForced, toast, tr } = useApp()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(ev) {
    ev.preventDefault()
    const f = Object.fromEntries(new FormData(ev.currentTarget))
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
      setCsrfToken(updated.csrf_token)
      setMe(updated)
      setForced(false)
      toast('Password updated')
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  async function leave() {
    try { await post('logout') } catch { /* ignore */ }
    clearCsrfToken()
    setMe(null)
    setForced(false)
  }

  return (
    <div className="forced">
      <form className="card forced-card" onSubmit={submit}>
        <span className="flame big" aria-hidden="true" />
        <h1>{tr('app.forcedTitle')}</h1>
        <p className="muted">{tr('app.forcedBody')}</p>
        <p className="hint">{me?.name} · @{me?.username}</p>
        {error && <p className="form-error" role="alert">{error}</p>}
        <label>{tr('app.currentPassword')}
          <input type="password" name="current_password" autoComplete="current-password" required autoFocus />
        </label>
        <label>{tr('app.newPassword')}
          <input type="password" name="new_password" minLength={8} autoComplete="new-password" required />
        </label>
        <label>{tr('app.confirmPassword')}
          <input type="password" name="confirm" minLength={8} autoComplete="new-password" required />
        </label>
        <p className="hint">{tr('app.passwordRule')}</p>
        <button className="primary wide" disabled={busy}>{busy ? tr('common.working') : tr('common.save')}</button>
        <button type="button" className="ghost wide" onClick={leave}>{tr('common.logout')}</button>
      </form>
    </div>
  )
}

function BranchScope() {
  const { me, branches, activeBranch, setActiveBranch, tr } = useApp()

  if (me.role === 'owner') {
    return (
      <label className="side-scope">
        <span>{tr('app.branchScope')}</span>
        <select value={activeBranch} onChange={e => setActiveBranch(e.target.value)}>
          <option value="all">{tr('common.allBranches')}</option>
          {branches.map(b => <option key={b.id} value={String(b.id)}>{b.name}</option>)}
        </select>
      </label>
    )
  }

  if (me.role === 'staff') {
    return (
      <div className="side-scope">
        <span>{tr('app.staffBranch')}</span>
        <strong>{me.branch_name || '—'}</strong>
      </div>
    )
  }

  return null
}

function Shell() {
  const { me, setMe, tr } = useApp()
  const { page } = useParams()
  const [pwOpen, setPwOpen] = useState(false)

  if (!me) return <Navigate to="/login" replace />

  const keys = Object.keys(PAGES).filter(k => PAGES[k].roles.includes(me.role))
  const home = me.role === 'customer' ? 'shop' : 'dashboard'
  const key = keys.includes(page) ? page : home
  const Page = PAGES[key].Comp

  async function logout() {
    try { await post('logout') } catch { /* session may already be gone */ }
    clearCsrfToken()
    setMe(null)
  }

  return (
    <div className="app">
      <aside className="side">
        <Link to="/" className="brand" style={{ color: '#fff', textDecoration: 'none' }}>
          <span className="flame" aria-hidden="true" />
          <span>{SITE.brand}</span>
        </Link>

        <BranchScope />

        <nav aria-label="Application">
          {keys.map(k => (
            <NavLink key={k} to={'/app/' + k} className={() => k === key ? 'on' : ''}>
              {tr(PAGES[k].label)}
            </NavLink>
          ))}
        </nav>

        <div className="me">
          <div className="who">
            <strong>{me.name}</strong>
            <small>{me.branch_name || tr('status.' + me.role)} · @{me.username}</small>
          </div>
          <div className="me-actions">
            <button type="button" className="ghost light sm" onClick={() => setPwOpen(true)}>
              {tr('common.changePassword')}
            </button>
            <button type="button" className="ghost light sm" onClick={logout}>{tr('common.logout')}</button>
          </div>
          <Link to="/" className="back-site">{tr('common.backToSite')}</Link>
        </div>
      </aside>

      <main className="main">
        <ErrorBoundary key={key}>
          <Page key={key + ':' + (me.role === 'owner' ? 'branch' : me.branch_id || '')} />
        </ErrorBoundary>
      </main>

      {pwOpen && <ChangePasswordForm onClose={() => setPwOpen(false)} />}
    </div>
  )
}

function NotFound() {
  const { tr } = useApp()
  return (
    <div className="simple-page">
      <div className="card">
        <span className="code">404</span>
        <h1>{tr('app.notFound')}</h1>
        <p className="muted">{tr('app.notFoundText')}</p>
        <Link className="primary btn-link" to="/">{tr('app.goHome')}</Link>
      </div>
    </div>
  )
}

export default function App() {
  const [me, setMe] = useState(undefined) // undefined = still checking
  const [booting, setBooting] = useState(true)
  const [forced, setForced] = useState(false)
  const [branches, setBranches] = useState([])
  const [sizes, setSizes] = useState([])
  const [customers, setCustomers] = useState([])
  const [tick, setTick] = useState(0)
  const [lang, setLang] = useState(readLang)
  const [note, setNote] = useState(null)
  const [activeBranch, setActiveBranchState] = useState(() => {
    try { return localStorage.getItem('lpg.branch') || 'all' } catch { return 'all' }
  })
  const noteTimer = useRef(null)

  const tr = useMemo(() => makeT(lang), [lang])

  const toast = useCallback((text, err = false) => {
    setNote({ text, err })
    clearTimeout(noteTimer.current)
    noteTimer.current = setTimeout(() => setNote(null), 3200)
  }, [])

  const refresh = useCallback(() => setTick(t => t + 1), [])

  const chooseLang = useCallback(code => {
    saveLang(code)
    setLang(code)
  }, [])

  const setActiveBranch = useCallback(id => {
    try { localStorage.setItem('lpg.branch', id) } catch { /* ignore */ }
    setActiveBranchState(id)
  }, [])

  // Boot: start a CSRF-protected session and load the current user.
  useEffect(() => {
    let live = true
    ;(async () => {
      try { await ensureCsrf() } catch { /* the login screen will retry */ }
      try {
        const user = await get('me')
        if (!live) return
        setMe(user)
        setForced(user.must_change_password === 1)
        setCsrfToken(user.csrf_token)
      } catch {
        if (live) setMe(null)
      } finally {
        if (live) setBooting(false)
      }
    })()
    return () => { live = false }
  }, [])

  // Auth hooks used by the API client.
  useEffect(() => {
    hooks.onUnauth = () => {
      setMe(null)
      setForced(false)
      toast(tr('app.sessionExpired'), true)
    }
    hooks.onPasswordRequired = () => setForced(true)
    return () => {
      hooks.onUnauth = () => {}
      hooks.onPasswordRequired = () => {}
    }
  }, [toast, tr])

  // Branch scope header. Staff and customers never choose it from the client.
  useEffect(() => {
    if (!me) { setBranch(null); return }
    if (me.role === 'owner') setBranch(activeBranch || 'all')
    else if (me.role === 'staff') setBranch(String(me.branch_id || ''))
    else setBranch(null)
  }, [me, activeBranch])

  // Active branch list for the switcher, customer order form and marketing.
  useEffect(() => {
    if (!me) return undefined
    let live = true
    publicGet('branches/public')
      .then(list => { if (live) setBranches(Array.isArray(list) ? list : []) })
      .catch(() => { if (live) setBranches([]) })
    return () => { live = false }
  }, [me, tick])

  // If a branch was deactivated, fall back to the combined view.
  useEffect(() => {
    if (me?.role !== 'owner' || activeBranch === 'all' || !branches.length) return
    if (!branches.some(b => String(b.id) === String(activeBranch))) setActiveBranch('all')
  }, [branches, me, activeBranch, setActiveBranch])

  // Branch-scoped reference data.
  useEffect(() => {
    if (!me || forced) return undefined
    let live = true
    get('sizes')
      .then(list => { if (live) setSizes(Array.isArray(list) ? list : []) })
      .catch(() => { if (live) setSizes([]) })
    if (me.role !== 'customer') {
      get('customers')
        .then(list => { if (live) setCustomers(Array.isArray(list) ? list : []) })
        .catch(() => { if (live) setCustomers([]) })
    } else {
      setCustomers([])
    }
    return () => { live = false }
  }, [me, forced, tick, activeBranch])

  // Keep the browser tab useful without hardcoding a brand in index.html.
  useEffect(() => {
    const section = me ? (PAGES[window.location.pathname.split('/').pop()]?.label) : null
    document.title = section ? `${SITE.brand} · ${tr(section)}` : `${SITE.brand} · LPG distributor system`
  }, [me, tr])

  const ctx = useMemo(() => ({
    me, setMe, forced, setForced, branches, activeBranch, setActiveBranch,
    sizes, customers, tick, refresh, toast, lang, setLang: chooseLang, tr,
  }), [me, forced, branches, activeBranch, setActiveBranch, sizes, customers, tick, refresh, toast, lang, chooseLang, tr])

  if (booting) {
    return (
      <div className="boot" role="status" aria-live="polite">
        <span className="flame big" aria-hidden="true" />
        <span className="spinner" aria-hidden="true" />
        <span>{tr('common.loading')}</span>
      </div>
    )
  }

  return (
    <Ctx.Provider value={ctx}>
      {me && forced ? (
        <ForcePassword />
      ) : (
        <ErrorBoundary>
          <Routes>
            <Route path="/" element={<Landing />} />
            <Route path="/privacy" element={<Privacy />} />
            <Route path="/terms" element={<Terms />} />
            <Route path="/login" element={<Auth mode="login" />} />
            <Route path="/signup" element={<Auth mode="signup" />} />
            <Route path="/app/:page?" element={<Shell />} />
            <Route path="*" element={<NotFound />} />
          </Routes>
        </ErrorBoundary>
      )}
      <div className={'toast' + (note ? ' show' : '') + (note?.err ? ' err' : '')} role="status" aria-live="polite">
        {note?.text}
      </div>
    </Ctx.Provider>
  )
}
