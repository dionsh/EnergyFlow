import { useCallback, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  Activity, ArrowRight, BadgeCheck, CalendarClock, Camera, ChartLine, Check, CircleAlert, CircleCheck, Cpu, Factory, FileText, FlaskConical, Leaf, Mail, Menu,
  MessageSquareText, Power, ScanSearch, Target, X,
} from 'lucide-react'
import { cn } from '../../lib/cn'
import { useAuth } from '../../providers/AuthProvider'
import { useApiErrors } from '../../hooks/useApiErrors'
import { useDismiss } from '../../hooks/useDismiss'
import { Button, IconButton } from '../../components/ui/Button'
import { buttonClasses } from '../../components/ui/buttonStyles'
import { Badge } from '../../components/ui/Badge'
import { Logo } from '../../components/ui/Logo'
import { Callout } from '../../components/ui/States'
import { LanguageSwitch, ThemeMenu } from '../../components/layout/TopBarControls'
import { EnergyFlowAnimation } from './EnergyFlowAnimation'

/** Optional public contact address, set at build time (frontend/.env.example). Nothing is shown without it. */
const CONTACT_EMAIL = (import.meta.env.VITE_CONTACT_EMAIL ?? '').trim()

const SECTIONS = ['about', 'features', 'how', 'contact']

/** What the product does today, checked against the code; `status` is shown on every card. */
const FEATURES = [
  { key: 'live', icon: Activity, status: 'available' },
  { key: 'waste', icon: ScanSearch, status: 'available' },
  { key: 'opportunities', icon: Target, status: 'available' },
  { key: 'control', icon: Power, status: 'available' },
  { key: 'impact', icon: BadgeCheck, status: 'available' },
  { key: 'carbon', icon: Leaf, status: 'available' },
  { key: 'reports', icon: FileText, status: 'available' },
  { key: 'insights', icon: ChartLine, status: 'available' },
  { key: 'assistant', icon: MessageSquareText, status: 'available' },
  { key: 'scan', icon: Camera, status: 'available' },
  { key: 'node', icon: Cpu, status: 'prototype' },
]

const STATUS = {
  available: { tone: 'good', icon: CircleCheck },
  prototype: { tone: 'warning', icon: FlaskConical },
  planned: { tone: 'neutral', icon: CalendarClock },
}

function Container({ className, children }) {
  return <div className={cn('mx-auto w-full max-w-[1200px] px-4 sm:px-6 lg:px-8', className)}>{children}</div>
}

function SectionHeading({ id, eyebrow, title, body, className }) {
  return (
    <div className={cn('max-w-2xl', className)}>
      <p className="text-[13px] font-semibold uppercase tracking-[0.06em] text-brand">{eyebrow}</p>
      <h2 id={id} className="mt-2 text-[24px] font-semibold leading-tight tracking-[-0.015em] text-ink sm:text-[30px]">{title}</h2>
      {body && <p className="mt-3 text-[15px] leading-relaxed text-ink-2 sm:text-base">{body}</p>}
    </div>
  )
}

/** The read-only demo company, one click away (the same sign-in as on the login page). */
function useDemo() {
  const { loginDemo } = useAuth()
  const [state, setState] = useState({ busy: false, error: null })
  const start = useCallback(async () => {
    setState({ busy: true, error: null })
    try {
      await loginDemo() // signed in: the route guard now shows the dashboard at "/"
    } catch (error) {
      setState({ busy: false, error })
    }
  }, [loginDemo])
  return { ...state, start }
}

function Header() {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const ref = useRef(null)
  const close = useCallback(() => setOpen(false), [])
  useDismiss(ref, close, open)
  const links = [['top', 'home'], ...SECTIONS.map((s) => [s, s])]
  // From the open menu: close it first (a link removed mid-click is never followed), then scroll once the header
  // has collapsed, stopping where the section's scroll-margin says. Without a behavior, scrollTo follows the page's
  // CSS scroll-behavior, so reduced motion jumps.
  const goTo = (id) => (event) => {
    if (!open) return
    event.preventDefault()
    setOpen(false)
    requestAnimationFrame(() => {
      const target = document.getElementById(id)
      if (!target) return
      const margin = parseFloat(getComputedStyle(target).scrollMarginTop) || 0
      window.scrollTo({ top: target.getBoundingClientRect().top + window.scrollY - margin })
      window.history.replaceState(window.history.state, '', `#${id}`)
    })
  }

  return (
    <header ref={ref} className="sticky top-0 z-30 border-b border-line bg-surface/95 backdrop-blur-sm">
      <Container className="flex h-14 items-center gap-2">
        <a href="#top" className="shrink-0 rounded-sm" onClick={goTo('top')}>
          <Logo />
        </a>
        <nav aria-label={t('landing.nav.label')} className="ml-6 hidden items-center gap-0.5 lg:flex">
          {links.map(([id, key]) => (
            <a key={id} href={`#${id}`} className="rounded-sm px-3 py-2 text-sm text-ink-2 transition-colors hover:bg-surface-2 hover:text-ink">
              {t(`landing.nav.${key}`)}
            </a>
          ))}
        </nav>
        <div className="ml-auto flex items-center gap-1.5 sm:gap-2">
          <div className="hidden items-center gap-1.5 sm:flex">
            <LanguageSwitch />
            <ThemeMenu />
          </div>
          <span className="hidden md:contents">
            <Link to="/login" className={buttonClasses({ variant: 'ghost' })}>{t('landing.nav.login')}</Link>
          </span>
          <Link to="/register" className={buttonClasses({ className: 'px-3 sm:px-3.5' })}>{t('landing.nav.signup')}</Link>
          <IconButton
            label={open ? t('landing.nav.closeMenu') : t('landing.nav.menu')}
            icon={open ? X : Menu}
            className="-mr-1.5 lg:hidden"
            aria-expanded={open}
            aria-controls="landing-menu"
            onClick={() => setOpen((v) => !v)}
          />
        </div>
      </Container>
      {open && (
        <div id="landing-menu" className="animate-fade border-t border-line bg-surface lg:hidden">
          <Container className="flex flex-col gap-1 py-3">
            <nav aria-label={t('landing.nav.label')} className="flex flex-col">
              {links.map(([id, key]) => (
                <a key={id} href={`#${id}`} onClick={goTo(id)} className="flex h-11 items-center rounded-sm px-2 text-[15px] text-ink hover:bg-surface-2">
                  {t(`landing.nav.${key}`)}
                </a>
              ))}
            </nav>
            <div className="mt-2 flex items-center justify-between gap-3 border-t border-line pt-3 sm:hidden">
              <LanguageSwitch />
              <ThemeMenu />
            </div>
            <div className="mt-2 grid grid-cols-2 gap-2 md:hidden">
              <Link to="/login" className={buttonClasses({ variant: 'secondary', size: 'lg' })}>{t('landing.nav.login')}</Link>
              <Link to="/register" className={buttonClasses({ size: 'lg' })}>{t('landing.nav.signup')}</Link>
            </div>
          </Container>
        </div>
      )}
    </header>
  )
}

function DemoError({ error }) {
  const { errorMessage } = useApiErrors()
  if (!error) return null
  return (
    <Callout tone="critical" icon={CircleAlert} className="mt-4">
      {errorMessage(error)}
    </Callout>
  )
}

function Hero({ demo }) {
  const { t } = useTranslation()
  return (
    <section aria-labelledby="hero-title" className="border-b border-line">
      <Container className="grid items-center gap-10 py-10 sm:py-14 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)] lg:gap-14 lg:py-20">
        <div className="min-w-0">
          <Badge tone="brand">{t('app.tagline')}</Badge>
          <h1 id="hero-title" className="mt-4 text-[30px] font-semibold leading-[1.12] tracking-[-0.02em] text-ink sm:text-[40px] lg:text-[46px]">
            {t('landing.hero.title')}
          </h1>
          <p className="mt-5 max-w-xl text-[15px] leading-relaxed text-ink-2 sm:text-[17px]">{t('landing.hero.body')}</p>
          <div className="mt-7 flex flex-col gap-2.5 sm:flex-row sm:flex-wrap">
            <Link to="/register" className={buttonClasses({ size: 'lg', className: 'h-11 px-5 text-[15px]' })}>
              {t('landing.hero.primary')}
              <ArrowRight className="size-4" aria-hidden="true" />
            </Link>
            <a href="#features" className={buttonClasses({ variant: 'secondary', size: 'lg', className: 'h-11 px-5 text-[15px]' })}>
              {t('landing.hero.secondary')}
            </a>
          </div>
          <div className="mt-6 border-t border-line pt-5">
            <Button variant="ghost" icon={Factory} loading={demo.busy} onClick={demo.start} className="-ml-3.5 text-brand hover:text-brand">
              {t('demo.explore')}
            </Button>
            <p className="mt-1 max-w-md text-[13px] text-ink-3">{t('landing.hero.demoHint')}</p>
            <DemoError error={demo.error} />
          </div>
        </div>
        <EnergyFlowAnimation className="min-w-0" />
      </Container>
    </section>
  )
}

function Facts() {
  const { t } = useTranslation()
  const items = t('landing.facts.items', { returnObjects: true })
  return (
    <section aria-labelledby="facts-title" className="border-b border-line bg-surface">
      <Container className="py-8 sm:py-10">
        <h2 id="facts-title" className="text-sm font-semibold text-ink">{t('landing.facts.label')}</h2>
        <dl className="mt-4 grid gap-5 sm:grid-cols-3 sm:gap-6">
          {Array.isArray(items) && items.map((item) => (
            <div key={item.value} className="border-l-2 border-brand pl-4">
              <dt className="text-[22px] font-semibold tracking-[-0.01em] text-ink sm:text-2xl">{item.value}</dt>
              <dd className="mt-1 text-sm text-ink-2">{item.label}</dd>
            </div>
          ))}
        </dl>
        <p className="mt-5 text-xs text-ink-3">{t('landing.facts.sources')}</p>
      </Container>
    </section>
  )
}

function About() {
  const { t } = useTranslation()
  const challenges = t('landing.about.challenges', { returnObjects: true })
  const audience = t('landing.about.audience', { returnObjects: true })
  return (
    <section id="about" aria-labelledby="about-title" className="border-b border-line">
      <Container className="grid gap-10 py-14 sm:py-20 lg:grid-cols-2 lg:gap-16">
        <div className="min-w-0">
          <SectionHeading id="about-title" eyebrow={t('landing.about.eyebrow')} title={t('landing.about.title')} body={t('landing.about.body')} />
          <div className="mt-8 rounded-md border border-brand/30 bg-brand-subtle px-5 py-4">
            <h3 className="text-sm font-semibold text-ink">{t('landing.about.helpsTitle')}</h3>
            <p className="mt-1.5 text-sm leading-relaxed text-ink-2">{t('landing.about.helps')}</p>
          </div>
          <h3 className="mt-8 text-sm font-semibold text-ink">{t('landing.about.audienceTitle')}</h3>
          <ul className="mt-3 flex flex-col gap-2.5">
            {Array.isArray(audience) && audience.map((line) => (
              <li key={line} className="flex items-start gap-3 text-sm text-ink-2">
                <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-subtle text-brand">
                  <Check className="size-3.5" aria-hidden="true" />
                </span>
                {line}
              </li>
            ))}
          </ul>
        </div>
        <div className="min-w-0">
          <h3 className="text-sm font-semibold text-ink">{t('landing.about.challengesTitle')}</h3>
          <ol className="mt-4 grid gap-3 sm:grid-cols-2">
            {Array.isArray(challenges) && challenges.map((c, i) => (
              <li key={c.title} className="rounded-md border border-line bg-surface p-4">
                <span className="font-mono text-xs text-ink-3">{String(i + 1).padStart(2, '0')}</span>
                <p className="mt-1.5 text-[15px] font-semibold text-ink">{c.title}</p>
                <p className="mt-1 text-sm leading-relaxed text-ink-2">{c.body}</p>
              </li>
            ))}
          </ol>
        </div>
      </Container>
    </section>
  )
}

function StatusBadge({ status }) {
  const { t } = useTranslation()
  const style = STATUS[status]
  return <Badge tone={style.tone} icon={style.icon}>{t(`landing.features.status.${status}`)}</Badge>
}

function Features() {
  const { t } = useTranslation()
  const planned = t('landing.features.planned', { returnObjects: true })
  return (
    <section id="features" aria-labelledby="features-title" className="border-b border-line bg-surface">
      <Container className="py-14 sm:py-20">
        <SectionHeading id="features-title" eyebrow={t('landing.features.eyebrow')} title={t('landing.features.title')} body={t('landing.features.body')} />
        <ul className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {FEATURES.map(({ key, icon: Icon, status }) => (
            <li key={key} className="flex flex-col rounded-md border border-line bg-bg p-5">
              <div className="flex items-start justify-between gap-3">
                <span className="flex size-9 items-center justify-center rounded-md bg-brand-subtle text-brand">
                  <Icon className="size-[18px]" aria-hidden="true" />
                </span>
                <StatusBadge status={status} />
              </div>
              <h3 className="mt-4 text-[15px] font-semibold text-ink">{t(`landing.features.items.${key}.title`)}</h3>
              <p className="mt-1.5 text-sm leading-relaxed text-ink-2">{t(`landing.features.items.${key}.body`)}</p>
            </li>
          ))}
          <li className="flex flex-col rounded-md border border-dashed border-line-strong p-5">
            <div className="flex items-start justify-between gap-3">
              <span className="flex size-9 items-center justify-center rounded-md bg-surface-2 text-ink-2">
                <CalendarClock className="size-[18px]" aria-hidden="true" />
              </span>
              <StatusBadge status="planned" />
            </div>
            <h3 className="mt-4 text-[15px] font-semibold text-ink">{t('landing.features.plannedTitle')}</h3>
            <ul className="mt-2 flex list-disc flex-col gap-1.5 pl-4 text-sm leading-relaxed text-ink-2 marker:text-ink-3">
              {Array.isArray(planned) && planned.map((line) => <li key={line}>{line}</li>)}
            </ul>
          </li>
        </ul>
      </Container>
    </section>
  )
}

function HowItWorks() {
  const { t } = useTranslation()
  const steps = t('landing.how.steps', { returnObjects: true })
  return (
    <section id="how" aria-labelledby="how-title" className="border-b border-line">
      <Container className="py-14 sm:py-20">
        <SectionHeading id="how-title" eyebrow={t('landing.how.eyebrow')} title={t('landing.how.title')} />
        <ol className="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          {Array.isArray(steps) && steps.map((step, i) => (
            <li key={step.title} className="relative rounded-md border border-line bg-surface p-5">
              <span className="flex size-8 items-center justify-center rounded-full border border-brand/40 bg-brand-subtle font-mono text-sm font-medium text-brand">{i + 1}</span>
              <h3 className="mt-4 text-[15px] font-semibold text-ink">{step.title}</h3>
              <p className="mt-1.5 text-sm leading-relaxed text-ink-2">{step.body}</p>
            </li>
          ))}
        </ol>
      </Container>
    </section>
  )
}

function Contact({ demo }) {
  const { t } = useTranslation()
  return (
    <section id="contact" aria-labelledby="contact-title">
      <Container className="py-14 sm:py-20">
        <div className="grid gap-8 rounded-lg border border-line bg-surface p-6 sm:p-10 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:items-center">
          <div className="min-w-0">
            <SectionHeading id="contact-title" eyebrow={t('landing.contact.eyebrow')} title={t('landing.contact.title')} body={t('landing.contact.body')} />
            {CONTACT_EMAIL && (
              <a href={`mailto:${CONTACT_EMAIL}`} className="mt-5 inline-flex items-center gap-2 text-[15px] font-medium text-brand [overflow-wrap:anywhere] hover:underline">
                <Mail className="size-4 shrink-0" aria-hidden="true" />
                {CONTACT_EMAIL}
              </a>
            )}
          </div>
          <div className="flex min-w-0 flex-col gap-2.5">
            {CONTACT_EMAIL && (
              <a href={`mailto:${CONTACT_EMAIL}`} className={buttonClasses({ size: 'lg', className: 'h-11' })}>
                <Mail className="size-4" aria-hidden="true" />
                {t('landing.contact.email')}
              </a>
            )}
            <Link to="/register" className={buttonClasses({ variant: CONTACT_EMAIL ? 'secondary' : 'primary', size: 'lg', className: 'h-11' })}>
              {t('landing.contact.signup')}
            </Link>
            <Button variant="secondary" size="lg" icon={Factory} loading={demo.busy} onClick={demo.start} className="h-11">
              {t('demo.explore')}
            </Button>
            <DemoError error={demo.error} />
          </div>
        </div>
      </Container>
    </section>
  )
}

function Footer() {
  const { t } = useTranslation()
  const year = new Date().getFullYear()
  return (
    <footer className="border-t border-line bg-surface">
      <Container className="grid gap-8 py-10 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
        <div className="min-w-0 sm:col-span-2 lg:col-span-1">
          <Logo />
          <p className="mt-3 max-w-sm text-sm text-ink-2">{t('landing.footer.description')}</p>
          <p className="mt-2 max-w-sm text-xs text-ink-3">{t('landing.footer.built')}</p>
        </div>
        <nav aria-label={t('landing.footer.product')} className="min-w-0">
          <h2 className="text-xs font-semibold uppercase tracking-[0.06em] text-ink-3">{t('landing.footer.product')}</h2>
          <ul className="mt-3 flex flex-col gap-2 text-sm">
            {SECTIONS.map((id) => (
              <li key={id}><a href={`#${id}`} className="text-ink-2 hover:text-ink">{t(`landing.nav.${id}`)}</a></li>
            ))}
          </ul>
        </nav>
        <nav aria-label={t('landing.footer.account')} className="min-w-0">
          <h2 className="text-xs font-semibold uppercase tracking-[0.06em] text-ink-3">{t('landing.footer.account')}</h2>
          <ul className="mt-3 flex flex-col gap-2 text-sm">
            <li><Link to="/login" className="text-ink-2 hover:text-ink">{t('landing.nav.login')}</Link></li>
            <li><Link to="/register" className="text-ink-2 hover:text-ink">{t('landing.nav.signup')}</Link></li>
            {CONTACT_EMAIL && (
              <li><a href={`mailto:${CONTACT_EMAIL}`} className="text-ink-2 [overflow-wrap:anywhere] hover:text-ink">{CONTACT_EMAIL}</a></li>
            )}
          </ul>
        </nav>
      </Container>
      <Container className="border-t border-line py-5">
        <p className="text-xs text-ink-3">{t('landing.footer.rights', { year })}</p>
      </Container>
    </footer>
  )
}

/** The public front page at "/" for visitors (RouteGuards); signed-in users get the dashboard there instead. */
export function LandingPage() {
  const demo = useDemo()
  return (
    <div id="top" className="ef-landing min-h-svh bg-bg">
      <Header />
      <main>
        <Hero demo={demo} />
        <Facts />
        <About />
        <Features />
        <HowItWorks />
        <Contact demo={demo} />
      </main>
      <Footer />
    </div>
  )
}
