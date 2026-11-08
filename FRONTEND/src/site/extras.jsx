import { useEffect, useRef, useState } from 'react'
import { motion, AnimatePresence, useScroll, useSpring, useTransform, useReducedMotion } from 'framer-motion'
import { ArrowUp, Check, ChevronDown } from 'lucide-react'

export function ScrollBar() {
  const { scrollYProgress } = useScroll()
  const x = useSpring(scrollYProgress, { stiffness: 120, damping: 24 })
  return <motion.div className="lp-progress" style={{ scaleX: x }} aria-hidden="true" />
}

export function BackToTop() {
  const [show, setShow] = useState(false)
  useEffect(() => {
    const on = () => setShow(window.scrollY > 700)
    on()
    addEventListener('scroll', on, { passive: true })
    return () => removeEventListener('scroll', on)
  }, [])
  const go = () => scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
  return (
    <button className={'lp-top' + (show ? ' show' : '')} aria-label="Back to top" onClick={go} tabIndex={show ? 0 : -1}>
      <ArrowUp size={20} />
    </button>
  )
}

// Highlights the nav link of the section currently in view
export function useSpy(ids) {
  const [active, setActive] = useState(ids[0])
  useEffect(() => {
    const els = ids.map(id => document.getElementById(id)).filter(Boolean)
    const io = new IntersectionObserver(
      entries => entries.forEach(e => e.isIntersecting && setActive(e.target.id)),
      { rootMargin: '-45% 0px -50% 0px' }
    )
    els.forEach(el => io.observe(el))
    return () => io.disconnect()
  }, []) // eslint-disable-line
  return active
}

// 3D tilt with a cursor spotlight
export function Tilt({ children }) {
  const ref = useRef(null)
  const reduce = useReducedMotion()
  function move(e) {
    if (reduce) return
    const r = ref.current.getBoundingClientRect()
    const x = (e.clientX - r.left) / r.width, y = (e.clientY - r.top) / r.height
    ref.current.style.transform = `perspective(900px) rotateX(${(0.5 - y) * 7}deg) rotateY(${(x - 0.5) * 9}deg)`
    ref.current.style.setProperty('--mx', x * 100 + '%')
    ref.current.style.setProperty('--my', y * 100 + '%')
  }
  const leave = () => { if (ref.current) ref.current.style.transform = '' }
  return <div ref={ref} className="lp-tilt" onMouseMove={move} onMouseLeave={leave}>{children}</div>
}

export function Marquee({ items }) {
  return (
    <div className="lp-marquee" aria-hidden="true">
      <div className="lp-track">
        {[...items, ...items].map((t, i) => <span key={i}>{t}<i /></span>)}
      </div>
    </div>
  )
}

export function Steps({ steps }) {
  const ref = useRef(null)
  const { scrollYProgress } = useScroll({ target: ref, offset: ['start 75%', 'end 60%'] })
  const h = useTransform(scrollYProgress, [0, 1], ['0%', '100%'])
  return (
    <div className="lp-steps" ref={ref}>
      <div className="lp-rail" aria-hidden="true"><motion.div className="lp-rail-fill" style={{ height: h }} /></div>
      <ol>
        {steps.map(({ icon: Icon, t, d }, i) => (
          <motion.li key={t} initial={{ opacity: 0, x: -24 }} whileInView={{ opacity: 1, x: 0 }}
            viewport={{ once: true, margin: '-80px' }} transition={{ duration: 0.6 }}>
            <span className="lp-step-n"><Icon size={22} /></span>
            <div><small>Step {i + 1}</small><h3>{t}</h3><p>{d}</p></div>
          </motion.li>
        ))}
      </ol>
    </div>
  )
}

export function Faq({ items }) {
  const [open, setOpen] = useState(0)
  return (
    <div className="lp-faq">
      {items.map(([q, a], i) => (
        <div key={q} className={'lp-qa' + (open === i ? ' open' : '')}>
          <h3>
            <button id={'qh' + i} aria-expanded={open === i} aria-controls={'qa' + i} onClick={() => setOpen(open === i ? -1 : i)}>
              {q}<ChevronDown size={20} />
            </button>
          </h3>
          <AnimatePresence initial={false}>
            {open === i && (
              <motion.div id={'qa' + i} role="region" aria-labelledby={'qh' + i} style={{ overflow: 'hidden' }}
                initial={{ height: 0, opacity: 0 }} animate={{ height: 'auto', opacity: 1 }} exit={{ height: 0, opacity: 0 }}
                transition={{ duration: 0.3 }}>
                <p>{a}</p>
              </motion.div>
            )}
          </AnimatePresence>
        </div>
      ))}
    </div>
  )
}

export function Finder({ items }) {
  const [i, setI] = useState(0)
  const p = items[i], Icon = p.icon
  return (
    <div className="lp-find">
      <div role="tablist" aria-label="Choose your situation">
        {items.map((x, k) => (
          <button key={x.who} role="tab" aria-selected={k === i} className={k === i ? 'on' : ''} onClick={() => setI(k)}>{x.who}</button>
        ))}
      </div>
      <AnimatePresence mode="wait">
        <motion.div key={i} className="lp-find-p" role="tabpanel"
          initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -14 }} transition={{ duration: 0.25 }}>
          <span className="lp-ico big"><Icon size={30} /></span>
          <div>
            <h3>{p.t}</h3>
            <p>{p.pitch}</p>
            <ul>{p.b.map(x => <li key={x}><Check size={16} />{x}</li>)}</ul>
            <a className="lp-link" href="#contact" style={{ marginTop: 18 }}>Talk to us <span aria-hidden="true">→</span></a>
          </div>
        </motion.div>
      </AnimatePresence>
    </div>
  )
}

export function Checklist({ items }) {
  const [done, setDone] = useState([])
  const toggle = i => setDone(d => (d.includes(i) ? d.filter(x => x !== i) : [...d, i]))
  const pct = Math.round((done.length / items.length) * 100)
  const C = 2 * Math.PI * 40
  const msg = done.length === items.length
    ? 'Nice work. Keep re-checking these regularly.'
    : `${done.length} of ${items.length} checked`
  return (
    <div className="lp-check">
      <div className="lp-check-h">
        <div className="lp-ring" aria-hidden="true">
          <svg viewBox="0 0 100 100" width="88" height="88">
            <circle cx="50" cy="50" r="40" fill="none" stroke="#DCE7E7" strokeWidth="9" />
            <circle cx="50" cy="50" r="40" fill="none" stroke="#00A19C" strokeWidth="9" strokeLinecap="round"
              strokeDasharray={C} strokeDashoffset={C * (1 - pct / 100)} style={{ transition: 'stroke-dashoffset .5s' }} />
          </svg>
          <b>{pct}%</b>
        </div>
        <div>
          <h3>Home safety check</h3>
          <p role="status" aria-live="polite" style={{ color: 'var(--mut)', margin: '4px 0 0' }}>{msg}</p>
        </div>
      </div>
      <ul>
        {items.map((t, i) => (
          <li key={t}>
            <label>
              <input type="checkbox" checked={done.includes(i)} onChange={() => toggle(i)} />
              <span className="box"><Check size={14} /></span>
              {t}
            </label>
          </li>
        ))}
      </ul>
      <small style={{ color: 'var(--mut)' }}>A reminder, not an inspection. Always follow your supplier&rsquo;s guidance.</small>
    </div>
  )
}
