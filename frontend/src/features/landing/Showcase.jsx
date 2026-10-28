import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useTheme } from '../../providers/ThemeProvider'
import { Tabs } from '../../components/ui/Tabs'
import { Container, SectionHeading } from './LandingParts'

/** Demo-company screens per language and theme, captured by tools/landing-shots/capture.mjs (1280 × 800 at 1.75×). */
const SHOTS = import.meta.glob('./shots/*.webp', { eager: true, import: 'default' })
const SCREENS = ['overview', 'waste', 'carbon']

/** "See it in action": real screens of the demo company. Only the visible one is downloaded, and only near the viewport. */
export function Showcase() {
  const { t, i18n } = useTranslation()
  const { resolved } = useTheme()
  const [screen, setScreen] = useState(SCREENS[0])
  const language = i18n.language === 'en' ? 'en' : 'sq'
  return (
    <section id="product" aria-labelledby="product-title" className="border-b border-line">
      <Container className="py-14 sm:py-20">
        <SectionHeading id="product-title" eyebrow={t('landing.showcase.eyebrow')} title={t('landing.showcase.title')} body={t('landing.showcase.body')} />
        <Tabs
          className="mt-8"
          items={SCREENS.map((key) => ({ key, label: t(`landing.showcase.items.${key}.tab`) }))}
          value={screen}
          onChange={setScreen}
        />
        <figure role="tabpanel" aria-label={t(`landing.showcase.items.${screen}.tab`)} className="min-w-0">
          <div className="overflow-hidden rounded-lg border border-line-strong bg-surface">
            <img
              key={screen}
              src={SHOTS[`./shots/${screen}-${language}-${resolved}.webp`]}
              alt={t(`landing.showcase.items.${screen}.alt`)}
              width="2240"
              height="1400"
              loading="lazy"
              decoding="async"
              className="block h-auto w-full"
            />
          </div>
          <figcaption className="mt-3 flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-6">
            <span className="text-sm text-ink-2">{t(`landing.showcase.items.${screen}.caption`)}</span>
            <span className="shrink-0 text-xs text-ink-3">{t('landing.showcase.note')}</span>
          </figcaption>
        </figure>
      </Container>
    </section>
  )
}
