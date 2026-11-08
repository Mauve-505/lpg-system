import { useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { ArrowLeft, Check, Flame, ShieldCheck } from 'lucide-react'
import { post } from './api'
import { LangToggle, useApp } from './ui'
import { SITE } from './site/content'

const POINTS = [
  'Choose an active branch and see the sizes it sells.',
  'Track pending, approved, and delivered orders.',
  'See payments, balances, and empties to return.',
]

export default function Auth({ mode }) {
  const signup = mode === 'signup'
  const { me, setMe, setForced, tr } = useApp()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  if (me) return <Navigate to="/app" replace />

  async function submit(ev) {
    ev.preventDefault()
    const f = Object.fromEntries(new FormData(ev.currentTarget))
    setError('')
    if (signup && f.password !== f.confirm) {
      setError('The passwords do not match.')
      return
    }
    setBusy(true)
    try {
      const body = signup
        ? { name: f.name, username: f.username, contact: f.contact, address: f.address, password: f.password }
        : { username: f.username, password: f.password }
      const user = await post(signup ? 'register' : 'login', body)
      setMe(user)
      setForced(user?.must_change_password === 1)
    } catch (err) {
      setError(err.message || 'Could not sign in.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="au">
      <aside className="au-side">
        <Link to="/" className="au-logo"><Flame size={22} aria-hidden="true" /> {SITE.brand}</Link>
        <div>
          <span className="au-kicker">Customer ordering</span>
          <h2>{tr(signup ? 'auth.signupTitle' : 'auth.loginTitle')}</h2>
          <p>{tr(signup ? 'auth.signupIntro' : 'auth.loginIntro')}</p>
          <ul className="au-points">
            {POINTS.map(t => <li key={t}><Check size={16} aria-hidden="true" />{t}</li>)}
          </ul>
        </div>
        <small className="au-note">
          <ShieldCheck size={15} aria-hidden="true" /> Staff and owner accounts are created by the business.
        </small>
      </aside>

      <main className="au-main">
        <div className="au-top">
          <Link to="/" className="au-back"><ArrowLeft size={16} aria-hidden="true" /> {tr('common.backToSite')}</Link>
          <LangToggle />
        </div>

        <form className="au-form" onSubmit={submit}>
          <h1>{tr(signup ? 'auth.signup' : 'auth.login')}</h1>
          <p className="muted au-sub">
            {signup ? 'Use the mobile number and address where you want LPG delivered.' : 'Use the username and password for your account.'}
          </p>

          {error && <p className="form-error" role="alert">{error}</p>}

          {signup && (
            <label>{tr('auth.fullName')}
              <input name="name" autoComplete="name" maxLength={100} required autoFocus />
            </label>
          )}
          <label>{tr('auth.username')}
            <input
              name="username"
              autoComplete="username"
              minLength={3}
              maxLength={30}
              pattern="[A-Za-z0-9_.\-]+"
              title="Letters, numbers, dots, hyphens, and underscores only"
              required
              autoFocus={!signup}
            />
          </label>
          {signup && (
            <>
              <label>{tr('auth.mobile')}
                <input name="contact" inputMode="tel" autoComplete="tel" maxLength={40} required />
              </label>
              <label>{tr('auth.address')}
                <input name="address" autoComplete="street-address" maxLength={200} required />
              </label>
            </>
          )}
          <label>{tr('auth.password')}{signup && ' (8+ characters)'}
            <input
              type="password"
              name="password"
              minLength={signup ? 8 : undefined}
              autoComplete={signup ? 'new-password' : 'current-password'}
              required
            />
          </label>
          {signup && <label>{tr('auth.confirm')}
            <input type="password" name="confirm" minLength={8} autoComplete="new-password" required />
          </label>}

          <button className="primary wide" disabled={busy}>
            {busy ? tr('common.working') : tr(signup ? 'auth.signup' : 'auth.login')}
          </button>

          <p className="au-alt">
            {signup
              ? <>{tr('auth.haveAccount')} <Link to="/login">{tr('auth.login')}</Link></>
              : <>{tr('auth.newCustomer')} <Link to="/signup">{tr('auth.signup')}</Link></>}
          </p>

          <p className="au-legal">
            {tr('auth.legal')}{' '}
            <Link to="/privacy">Privacy Policy</Link> &middot; <Link to="/terms">Terms and Conditions</Link>
          </p>
        </form>
      </main>
    </div>
  )
}
