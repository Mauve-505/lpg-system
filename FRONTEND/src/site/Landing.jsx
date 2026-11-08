import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { motion, MotionConfig, useScroll, useTransform, useReducedMotion, useMotionValue, useSpring } from 'framer-motion'
import { Flame, Menu, X, ArrowRight, Check, Mail, Phone, MapPin, Clock, ShieldAlert, Info } from 'lucide-react'
import { useApp } from '../ui'
import { publicGet } from '../api'
import { SITE, NAV, VALUES, PRODUCTS, APPS, SAFETY, SMELL_STEPS, PILLARS, MARQUEE, FIND, STEPS, CHECKS, FAQ, BRANCH_EMPTY } from './content'
import { ScrollBar, BackToTop, Tilt, Marquee, Steps, Faq, Finder, Checklist, useSpy } from './extras'

const ease = [0.22, 1, 0.36, 1]
const DOTS = Array.from({ length: 22 }, (_, i) => ({ l: (i * 47) % 100, s: 3 + ((i * 7) % 6), d: (i * 1.3) % 9, t: 9 + ((i * 5) % 9) }))

/* ---------- small building blocks ---------- */
function Reveal({ children, delay = 0, y = 28 }) {
  return (
    <motion.div initial={{ opacity: 0, y }} whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true, margin: '-60px' }} transition={{ duration: 0.6, delay, ease }}>
      {children}
    </motion.div>
  )
}

function Head({ eyebrow, title, text, id }) {
  return (
    <Reveal>
      <div className="lp-head">
        <span className="lp-eyebrow">{eyebrow}</span>
        <h2 id={id}>{title}</h2>
        {text && <p>{text}</p>}
      </div>
    </Reveal>
  )
}

function Pic({ src, alt = '', Icon }) {
  return (
    <div className="lp-pic">
      {src
        ? <img src={src} alt={alt} loading="lazy" />
        : <div className="lp-pic-i" aria-hidden="true"><Icon size={56} strokeWidth={1.4} /></div>}
    </div>
  )
}

function Cylinder() {
  return (
    <svg viewBox="0 0 240 380" role="img" aria-label="Illustration of an LPG cylinder">
      <defs>
        <linearGradient id="cyl" x1="0" x2="1">
          <stop offset="0" stopColor="#006B68" /><stop offset=".45" stopColor="#2DD4CE" /><stop offset="1" stopColor="#006B68" />
        </linearGradient>
      </defs>
      <ellipse cx="120" cy="364" rx="84" ry="10" fill="rgba(0,0,0,.35)" />
      <rect x="62" y="330" width="116" height="24" rx="6" fill="#0B3B3A" />
      <path d="M50 150C50 100 80 82 120 82s70 18 70 68v170q0 18-18 18H68q-18 0-18-18Z" fill="url(#cyl)" />
      <rect x="50" y="205" width="140" height="58" fill="rgba(16,42,54,.4)" />
      <text x="120" y="245" textAnchor="middle" fontSize="30" fontWeight="800" fill="#fff" fontFamily="DM Sans, sans-serif" letterSpacing="3">LPG</text>
      <path d="M74 130v170" stroke="rgba(255,255,255,.35)" strokeWidth="8" strokeLinecap="round" fill="none" />
      <rect x="86" y="52" width="68" height="40" rx="8" fill="#0B3B3A" />
      <rect x="104" y="28" width="32" height="30" rx="6" fill="#B8C6CA" />
      <circle cx="120" cy="22" r="12" fill="#D6E2E4" />
    </svg>
  )
}

/* ---------- header ---------- */
function Header() {
  const { me } = useApp()
  const [solid, setSolid] = useState(false)
  const [open, setOpen] = useState(false)
  const active = useSpy(NAV.map(([, h]) => h.slice(1)))

  useEffect(() => {
    const on = () => setSolid(window.scrollY > 24)
    on()
    addEventListener('scroll', on, { passive: true })
    return () => removeEventListener('scroll', on)
  }, [])

  const close = () => setOpen(false)
  return (
    <header className={'lp-header' + (solid || open ? ' solid' : '')}>
      <div className="lp-wrap lp-bar">
        <a href="#home" className="lp-logo" onClick={close}>
          <Flame size={22} aria-hidden="true" /> {SITE.brand}
        </a>

        <nav id="lp-menu" aria-label="Main" className={open ? 'open' : ''}>
          {NAV.map(([t, h]) => (
            <a key={h} href={h} className={active === h.slice(1) ? 'on' : ''} aria-current={active === h.slice(1) ? 'true' : undefined} onClick={close}>{t}</a>
          ))}
          {me
            ? <Link className="lp-m" to="/app">Open dashboard</Link>
            : <><Link className="lp-m" to="/login">Log in</Link><Link className="lp-m" to="/signup">Create account</Link></>}
        </nav>

        <div className="lp-actions">
          {me
            ? <Link className="lp-btn teal sm" to="/app">Open dashboard</Link>
            : <><Link className="lp-login" to="/login">Log in</Link><Link className="lp-btn teal sm" to="/signup">Sign up</Link></>}
        </div>

        <button className="lp-burger" aria-label={open ? 'Close menu' : 'Open menu'} aria-expanded={open} aria-controls="lp-menu" onClick={() => setOpen(o => !o)}>
          {open ? <X size={24} aria-hidden="true" /> : <Menu size={24} aria-hidden="true" />}
        </button>
      </div>
    </header>
  )
}

/* ---------- sections ---------- */
function Hero() {
  const reduce = useReducedMotion()
  const { scrollY } = useScroll()
  const bgY = useTransform(scrollY, [0, 800], [0, reduce ? 0 : 160])
  const artY = useTransform(scrollY, [0, 800], [0, reduce ? 0 : -70])
  const mx = useMotionValue(0), my = useMotionValue(0)
  const sx = useSpring(mx, { stiffness: 60, damping: 16 }), sy = useSpring(my, { stiffness: 60, damping: 16 })
  const ry = useTransform(sx, [-0.5, 0.5], [-12, 12])
  const rx = useTransform(sy, [-0.5, 0.5], [10, -10])
  const move = e => {
    if (reduce) return
    const r = e.currentTarget.getBoundingClientRect()
    mx.set((e.clientX - r.left) / r.width - 0.5)
    my.set((e.clientY - r.top) / r.height - 0.5)
  }
  const leave = () => { mx.set(0); my.set(0) }
  const words = [['Order'], ['LPG'], ['from'], ['your'], ['local', 1], ['branch.', 1]]
  const fade = d => ({ initial: { opacity: 0, y: 20 }, animate: { opacity: 1, y: 0 }, transition: { duration: 0.7, delay: d, ease } })

  return (
    <section id="home" className="lp-hero" aria-labelledby="hero-title" onMouseMove={move} onMouseLeave={leave}>
      <motion.div className="lp-hero-bg" style={{ y: bgY, backgroundImage: SITE.heroImage ? `url(${SITE.heroImage})` : undefined }} />
      <div className="lp-blob" style={{ width: 420, height: 420, background: '#00A19C', top: '-8%', right: '-6%' }} />
      <div className="lp-blob" style={{ width: 320, height: 320, background: '#006B68', bottom: '-10%', left: '-8%', animationDelay: '-6s' }} />
      <div className="lp-shade" />
      <div className="lp-dots" aria-hidden="true">
        {DOTS.map((p, i) => <i key={i} style={{ left: p.l + '%', width: p.s, height: p.s, animationDelay: '-' + p.d + 's', animationDuration: p.t + 's' }} />)}
      </div>

      <div className="lp-wrap lp-hero-grid">
        <div>
          <motion.span className="lp-eyebrow light" {...fade(0.1)}>LPG ordering, branch by branch</motion.span>
          <motion.h1 id="hero-title" aria-label="Order LPG from your local branch."
            initial="hide" animate="show" variants={{ show: { transition: { staggerChildren: 0.09, delayChildren: 0.2 } } }}>
            {words.map(([w, hl], i) => (
              <span className="lp-w" key={i} aria-hidden="true">
                <motion.span className={hl ? 'lp-hl' : ''}
                  variants={{ hide: { y: '110%', opacity: 0 }, show: { y: 0, opacity: 1, transition: { duration: 0.7, ease } } }}>
                  {w}
                </motion.span>{' '}
              </span>
            ))}
          </motion.h1>
          <motion.p className="lp-lead" {...fade(0.8)}>
            {SITE.tagline} Choose an active branch, send an order, and follow its delivery and
            balance from your own account.
          </motion.p>
          <motion.div className="lp-cta" {...fade(1)}>
            <a className="lp-btn teal" href="#products">Explore Our Products <ArrowRight size={18} aria-hidden="true" /></a>
            <a className="lp-btn glass" href="#branches">See Our Branches</a>
          </motion.div>
        </div>
        <motion.div className="lp-art" style={{ y: artY, rotateX: rx, rotateY: ry, transformPerspective: 900 }}>
          <div className="lp-float"><Cylinder /></div>
          <span className="lp-chip c1" aria-hidden="true">Order online</span>
          <span className="lp-chip c2" aria-hidden="true">Track delivery</span>
          <span className="lp-chip c3" aria-hidden="true">See your balance</span>
        </motion.div>
      </div>
    </section>
  )
}

function About() {
  return (
    <section id="about" className="lp-sec" aria-labelledby="about-t">
      <div className="lp-wrap lp-split">
        <div>
          <Head id="about-t" eyebrow={`About ${SITE.brand}`} title="A clearer way to order and supply LPG."
            text={`${SITE.owner} uses this system to connect customers with its LPG branches. Orders, deliveries, stock, and balances stay with the branch that handles them, so staff can give you a straight answer about your account.`} />
          <div className="lp-values">
            {VALUES.map(({ icon: Icon, t, d }, i) => (
              <Reveal key={t} delay={i * 0.08}>
                <div className="lp-value">
                  <span className="lp-ico"><Icon size={22} aria-hidden="true" /></span>
                  <div><h3>{t}</h3><p>{d}</p></div>
                </div>
              </Reveal>
            ))}
          </div>
        </div>
        <Reveal delay={0.15}><Pic src={SITE.aboutImage} alt={SITE.brand} Icon={Flame} /></Reveal>
      </div>
    </section>
  )
}

function Products() {
  return (
    <section id="products" className="lp-sec gray" aria-labelledby="prod-t">
      <div className="lp-wrap">
        <Head id="prod-t" eyebrow="Our LPG Products" title="LPG for different kinds of use."
          text="From a single home kitchen to larger operations, the branch can advise on the sizes and arrangements it supports." />
        <div className="lp-grid">
          {PRODUCTS.map((p, i) => (
            <Reveal key={p.t} delay={i * 0.1}>
              <Tilt>
                <article className="lp-card">
                  <Pic src={p.img} alt={p.t} Icon={p.icon} />
                  <div className="lp-card-b">
                    <h3>{p.t}</h3>
                    <p>{p.d}</p>
                    <ul>{p.b.map(x => <li key={x}><Check size={16} aria-hidden="true" />{x}</li>)}</ul>
                    <a className="lp-link" href="#branches">Ask your branch <ArrowRight size={16} aria-hidden="true" /></a>
                  </div>
                </article>
              </Tilt>
            </Reveal>
          ))}
        </div>
        <p className="lp-note">Product range, cylinder sizes, prices, and availability vary by branch and can change. Confirm details with the branch before ordering.</p>
      </div>
    </section>
  )
}

/* ---------- live branch list ---------- */
function BranchCard({ b }) {
  const details = [
    b.address && { icon: MapPin, text: b.address },
    b.contact_phone && { icon: Phone, text: b.contact_phone },
    b.operating_hours && { icon: Clock, text: b.operating_hours },
    b.service_area_notes && { icon: Info, text: b.service_area_notes },
  ].filter(Boolean)

  return (
    <article className="lp-card lp-branch">
      <div className="lp-card-b">
        <span className="lp-branch-code">{b.code || 'Branch'}</span>
        <h3>{b.name}</h3>
        {details.length > 0 ? (
          <ul className="lp-branch-list">
            {details.map(({ icon: Icon, text }) => (
              <li key={text}><Icon size={16} aria-hidden="true" /><span>{text}</span></li>
            ))}
          </ul>
        ) : (
          <p>Contact this branch for its address, hours, and delivery area.</p>
        )}
        <a className="lp-link" href="#contact">Contact us <ArrowRight size={16} aria-hidden="true" /></a>
      </div>
    </article>
  )
}

function Branches() {
  const [state, setState] = useState({ loading: true, list: [], error: null })

  useEffect(() => {
    let live = true
    publicGet('branches/public')
      .then(list => {
        if (!live) return
        setState({ loading: false, list: Array.isArray(list) ? list : [], error: null })
      })
      .catch(() => {
        if (!live) return
        setState({ loading: false, list: [], error: true })
      })
    return () => { live = false }
  }, [])

  return (
    <section id="branches" className="lp-sec" aria-labelledby="branch-t">
      <div className="lp-wrap">
        <Head id="branch-t" eyebrow="Our Branches" title="Find the branch that serves you."
          text="These are the branches currently open for orders. Availability, delivery areas, and cut-off times can differ." />

        {state.loading && <p className="lp-note" role="status">Loading branch details…</p>}

        {!state.loading && state.error && (
          <p className="lp-note" role="alert">
            Branch details are temporarily unavailable. Please try again later or use the contact details below.
          </p>
        )}

        {!state.loading && !state.error && state.list.length === 0 && (
          <p className="lp-note">{BRANCH_EMPTY}</p>
        )}

        {state.list.length > 0 && (
          <div className="lp-grid small lp-branches">
            {state.list.map(b => (
              <Reveal key={b.id}><BranchCard b={b} /></Reveal>
            ))}
          </div>
        )}

        <Reveal>
          <div className="lp-branch-cta">
            <p>Not sure which branch covers your address? Ask before you order.</p>
            <a className="lp-btn teal sm" href="#contact">Contact us <ArrowRight size={16} aria-hidden="true" /></a>
          </div>
        </Reveal>
      </div>
    </section>
  )
}

function FindFit() {
  return (
    <section id="find" className="lp-sec gray" aria-labelledby="find-t">
      <div className="lp-wrap">
        <Head id="find-t" eyebrow="Find your fit" title="Which LPG solution suits you?"
          text="Pick the option closest to your situation. Your branch can confirm sizes, prices, and availability." />
        <Reveal><Finder items={FIND} /></Reveal>
      </div>
    </section>
  )
}

function Applications() {
  return (
    <section id="applications" className="lp-sec" aria-labelledby="app-t">
      <div className="lp-wrap">
        <Head id="app-t" eyebrow="Applications" title="One fuel, many ways to use it." />
        <div className="lp-grid small">
          {APPS.map(({ icon: Icon, t, d }, i) => (
            <Reveal key={t} delay={i * 0.08}>
              <Tilt>
                <div className="lp-card lp-app">
                  <span className="lp-ico big"><Icon size={30} aria-hidden="true" /></span>
                  <h3>{t}</h3>
                  <p>{d}</p>
                </div>
              </Tilt>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  )
}

function How() {
  const { me } = useApp()
  return (
    <section id="how" className="lp-sec gray" aria-labelledby="how-t">
      <div className="lp-wrap">
        <Head id="how-t" eyebrow="How ordering works" title="From order to doorstep, in four steps."
          text="Order through your branch and follow every step from your own account." />
        <Steps steps={STEPS} />
        <Reveal>
          <div style={{ textAlign: 'center', marginTop: 44 }}>
            <Link className="lp-btn teal" to={me ? '/app' : '/signup'}>{me ? 'Open dashboard' : 'Create your account'} <ArrowRight size={18} aria-hidden="true" /></Link>
          </div>
        </Reveal>
      </div>
    </section>
  )
}

function Safety() {
  return (
    <section id="safety" className="lp-sec" aria-labelledby="safe-t">
      <div className="lp-wrap">
        <Head id="safe-t" eyebrow="Safety First" title="Safe energy starts with safe habits."
          text="General guidance for using LPG at home and at work. Always follow your supplier's instructions, the cylinder label, and local regulations." />
        <div className="lp-grid">
          {SAFETY.map(({ icon: Icon, t, d }, i) => (
            <Reveal key={t} delay={i * 0.07}>
              <div className="lp-card lp-safe">
                <span className="lp-ico"><Icon size={22} aria-hidden="true" /></span>
                <h3>{t}</h3>
                <p>{d}</p>
              </div>
            </Reveal>
          ))}
        </div>
        <div className="lp-two">
          <Reveal><Checklist items={CHECKS} /></Reveal>
          <Reveal delay={0.1}>
            <div className="lp-alert" role="note">
              <ShieldAlert size={34} aria-hidden="true" />
              <div>
                <h3>If you smell gas</h3>
                <ol>{SMELL_STEPS.map(s => <li key={s}>{s}</li>)}</ol>
              </div>
            </div>
          </Reveal>
        </div>
      </div>
    </section>
  )
}

function Sustainability() {
  return (
    <section id="sustainability" className="lp-sec lp-dark" aria-labelledby="sus-t">
      <div className="lp-blob" style={{ width: 380, height: 380, background: '#00A19C', top: '-10%', left: '-6%', opacity: 0.25 }} />
      <div className="lp-blob" style={{ width: 300, height: 300, background: '#006B68', bottom: '-12%', right: '-4%', animationDelay: '-5s', opacity: 0.35 }} />
      <div className="lp-wrap lp-rel">
        <Head id="sus-t" eyebrow="Responsibility and Improvement" title="Careful use, clearer records."
          text="Good supply is about more than volume. It is about using what is needed, maintaining equipment, and keeping accurate records." />
        <div className="lp-grid">
          {PILLARS.map(({ icon: Icon, t, d }, i) => (
            <Reveal key={t} delay={i * 0.09}>
              <div className="lp-card lp-glass">
                <span className="lp-ico dark"><Icon size={24} aria-hidden="true" /></span>
                <h3>{t}</h3>
                <p>{d}</p>
              </div>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  )
}

function FaqSection() {
  return (
    <section id="faq" className="lp-sec" aria-labelledby="faq-t">
      <div className="lp-wrap">
        <Head id="faq-t" eyebrow="FAQ" title="Questions people ask." />
        <Reveal><Faq items={FAQ} /></Reveal>
      </div>
    </section>
  )
}

function CallToAction() {
  const { me } = useApp()
  return (
    <section className="lp-cta-sec" aria-labelledby="cta-t">
      <div className="lp-wrap">
        <Reveal>
          <h2 id="cta-t">Ready to place an order?</h2>
          <p>Create an account, choose your branch, and keep your orders and balances in one place.</p>
          <div className="lp-cta" style={{ justifyContent: 'center' }}>
            <a className="lp-btn white" href="#contact">Contact Us</a>
            <Link className="lp-btn glass" to={me ? '/app' : '/signup'}>{me ? 'Open dashboard' : 'Create an account'}</Link>
          </div>
        </Reveal>
      </div>
    </section>
  )
}

function Footer() {
  const c = SITE.contact
  const socials = Object.entries(SITE.social).filter(([, url]) => url)
  const hasContact = c.phone || c.email || c.address || c.hours
  return (
    <footer id="contact" className="lp-footer">
      <div className="lp-wrap lp-fgrid">
        <div>
          <div className="lp-logo"><Flame size={22} aria-hidden="true" /> {SITE.brand}</div>
          <p className="lp-fnote">{SITE.tagline}</p>
          {socials.length > 0 && (
            <div className="lp-soc">{socials.map(([n, url]) => <a key={n} href={url} target="_blank" rel="noreferrer">{n}</a>)}</div>
          )}
        </div>
        <div>
          <h3>Quick links</h3>
          <ul>{NAV.map(([t, h]) => <li key={h}><a href={h}>{t}</a></li>)}</ul>
        </div>
        <div>
          <h3>Products</h3>
          <ul>{PRODUCTS.map(p => <li key={p.t}><a href="#products">{p.t}</a></li>)}</ul>
        </div>
        <div>
          <h3>Contact</h3>
          {hasContact ? (
            <ul className="lp-ct">
              {c.phone && <li><Phone size={16} aria-hidden="true" /><a href={`tel:${c.phone.replace(/[^\d+]/g, '')}`}>{c.phone}</a></li>}
              {c.email && <li><Mail size={16} aria-hidden="true" /><a href={`mailto:${c.email}`}>{c.email}</a></li>}
              {c.address && <li><MapPin size={16} aria-hidden="true" /><span>{c.address}</span></li>}
              {c.hours && <li><Clock size={16} aria-hidden="true" /><span>{c.hours}</span></li>}
            </ul>
          ) : <p className="lp-fnote">Contact details coming soon.</p>}
        </div>
      </div>
      <div className="lp-wrap lp-legal">
        <span>&copy; {new Date().getFullYear()} {SITE.owner}. All rights reserved.</span>
        <span><Link to="/privacy">Privacy Policy</Link> &middot; <Link to="/terms">Terms and Conditions</Link></span>
      </div>
    </footer>
  )
}

export default function Landing() {
  return (
    <MotionConfig reducedMotion="user">
      <div className="lp">
        <a className="lp-skip" href="#main">Skip to content</a>
        <ScrollBar />
        <Header />
        <main id="main">
          <Hero />
          <Marquee items={MARQUEE} />
          <About />
          <Products />
          <Branches />
          <FindFit />
          <Applications />
          <How />
          <Safety />
          <Sustainability />
          <FaqSection />
          <CallToAction />
        </main>
        <Footer />
        <BackToTop />
      </div>
    </MotionConfig>
  )
}
