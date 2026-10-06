import { useTranslation } from 'react-i18next'
import { Check } from 'lucide-react'
import { Logo } from '../../components/ui/Logo'
import { LanguageSwitch, ThemeMenu } from '../../components/layout/TopBarControls'

export function AuthLayout({ title, subtitle, children, footer }) {
  const { t } = useTranslation()
  const points = t('auth.brand.points', { returnObjects: true })

  return (
    <div className="grid min-h-svh bg-bg lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
      <aside className="hidden flex-col justify-between border-r border-line bg-surface p-10 lg:flex">
        <Logo />
        <div className="max-w-md">
          <h2 className="text-[28px] font-semibold leading-tight tracking-[-0.015em] text-ink">{t('auth.brand.headline')}</h2>
          <ul className="mt-8 flex flex-col gap-4">
            {Array.isArray(points) &&
              points.map((point) => (
                <li key={point} className="flex items-start gap-3 text-sm text-ink-2">
                  <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-subtle text-brand">
                    <Check className="size-3.5" aria-hidden="true" />
                  </span>
                  {point}
                </li>
              ))}
          </ul>
        </div>
        <p className="text-xs text-ink-3">{t('app.tagline')}</p>
      </aside>

      <main className="flex flex-col">
        <div className="flex h-14 items-center justify-between px-6">
          <span className="lg:hidden">
            <Logo />
          </span>
          <div className="ml-auto flex items-center gap-2">
            <LanguageSwitch />
            <ThemeMenu />
          </div>
        </div>
        <div className="flex flex-1 items-center justify-center px-6 pb-16 pt-6">
          <div className="w-full max-w-sm">
            <h1 className="text-xl font-semibold tracking-[-0.01em] text-ink">{title}</h1>
            {subtitle && <p className="mt-1 text-sm text-ink-2">{subtitle}</p>}
            <div className="mt-7">{children}</div>
            {footer && <div className="mt-6 text-sm text-ink-2">{footer}</div>}
          </div>
        </div>
      </main>
    </div>
  )
}
