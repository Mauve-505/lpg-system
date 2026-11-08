import { Link } from 'react-router-dom'
import { ArrowLeft, Flame } from 'lucide-react'
import { SITE } from './content'

/**
 * Shared shell for the privacy policy and terms pages.
 *
 * These documents are starting points, not legal advice. They are flagged in
 * the UI so they cannot be mistaken for reviewed, jurisdiction-specific terms.
 */
export function LegalPage({ title, intro, children }) {
  return (
    <div className="legal-page">
      <a className="lp-skip" href="#legal-main">Skip to content</a>

      <header className="legal-bar">
        <Link to="/" className="legal-brand">
          <Flame size={20} aria-hidden="true" />
          <span>{SITE.brand}</span>
        </Link>
        <Link to="/" className="legal-back">
          <ArrowLeft size={16} aria-hidden="true" /> Back to website
        </Link>
      </header>

      <main id="legal-main" className="legal-wrap">
        <article className="legal-doc">
          <span className="legal-kicker">Legal</span>
          <h1>{title}</h1>
          <p className="legal-meta">
            {SITE.legal.version} &middot; Effective date: {SITE.legal.effective}
          </p>
          {intro && <p className="legal-intro">{intro}</p>}

          <aside className="legal-flag" role="note">
            <strong>Review required before launch.</strong>
            <p>
              This is a plain-language template. It is not legal advice and has not
              been reviewed for your business, branch operations, payment methods or
              hosting arrangements. Have a Philippine lawyer review and adapt it,
              then set the version and effective date.
            </p>
          </aside>

          {children}

          <p className="legal-foot">
            Questions about this document? Contact {SITE.owner} using the contact
            details published on this website.
          </p>
        </article>
      </main>

      <footer className="legal-footer">
        <Link to="/">Home</Link>
        <Link to="/privacy">Privacy Policy</Link>
        <Link to="/terms">Terms and Conditions</Link>
      </footer>
    </div>
  )
}
