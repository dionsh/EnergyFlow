import { useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CircleAlert, CircleCheck, Factory, Gauge, Lightbulb, Snowflake, Wind, Zap } from 'lucide-react'
import { cn } from '../../lib/cn'

// Layout in SVG user units; the drawing scales to its container.
const W = 468
const H = 424
const METER = { x: 150, y: 232, r: 22 }
const CARD = { x: 284, w: 176, h: 48 }
const MACHINES = [
  { key: 'compressor', icon: Wind, y: 88, particles: 4 },
  { key: 'moulder', icon: Factory, y: 184, particles: 5 },
  { key: 'chiller', icon: Snowflake, y: 280, particles: 3 },
  { key: 'lighting', icon: Lightbulb, y: 376, particles: 2 },
]
const FEED = `M68,${METER.y} L${METER.x - METER.r},${METER.y}`
const branch = (y) => `M${METER.x + METER.r},${METER.y} C${METER.x + 72},${METER.y} ${CARD.x - 64},${y} ${CARD.x},${y}`

function useReducedMotion() {
  const query = '(prefers-reduced-motion: reduce)'
  const [reduced, setReduced] = useState(() => typeof window !== 'undefined' && Boolean(window.matchMedia?.(query).matches))
  useEffect(() => {
    const media = window.matchMedia?.(query)
    if (!media) return undefined
    const onChange = () => setReduced(media.matches)
    media.addEventListener('change', onChange)
    return () => media.removeEventListener('change', onChange)
  }, [])
  return reduced
}

/** Dots travelling along a path, evenly spaced (SVG SMIL: no script per frame). */
function Particles({ d, count, dur, className }) {
  return Array.from({ length: count }, (_, i) => (
    <circle key={i} r="3.4" className={className}>
      <animateMotion dur={`${dur}s`} begin={`${(-i * dur) / count}s`} repeatCount="indefinite" path={d} />
    </circle>
  ))
}

/**
 * The landing page's hero: electricity flows from the grid through the main
 * meter to four machines, each with its own meter. One 12-second story loops:
 * the compressor keeps running after hours, EnergyFlow flags it, and it is
 * switched off and confirmed by the meter (Measure → Detect → Act → Prove).
 *
 * Motion pauses off screen. With reduced motion the same picture is drawn
 * still: dotted flows and the detected waste, no animation.
 */
export function EnergyFlowAnimation({ className }) {
  const { t } = useTranslation()
  const reduced = useReducedMotion()
  const id = useId().replace(/:/g, '')
  const frame = useRef(null)
  const svg = useRef(null)
  const [paused, setPaused] = useState(false)

  useEffect(() => {
    if (reduced || typeof IntersectionObserver === 'undefined' || !frame.current) return undefined
    const observer = new IntersectionObserver(([entry]) => {
      setPaused(!entry.isIntersecting)
      if (entry.isIntersecting) svg.current?.unpauseAnimations?.()
      else svg.current?.pauseAnimations?.()
    })
    observer.observe(frame.current)
    return () => observer.disconnect()
  }, [reduced])

  const steps = [
    ['measure', 'ef-step-on'],
    ['detect', reduced ? 'ef-step-on' : 'ef-step-detect'],
    ['act', reduced ? '' : 'ef-step-act'],
    ['prove', reduced ? '' : 'ef-step-act'],
  ]

  return (
    <figure className={cn('ef-flow relative rounded-lg border border-line bg-surface', className)} data-paused={paused} ref={frame}>
      <ol className="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-line px-4 py-2.5 text-xs font-medium text-ink-3" aria-hidden="true">
        {steps.map(([key, state], i) => (
          <li key={key} className="flex items-center gap-1.5">
            {i > 0 && <span className="text-line-strong">→</span>}
            <span className={cn('ef-step', state)}>{t(`landing.flow.steps.${key}`)}</span>
          </li>
        ))}
      </ol>

      <div className="relative">
        <svg ref={svg} viewBox={`0 0 ${W} ${H}`} className="block h-auto w-full" role="img" aria-label={t('landing.flow.aria')}>
          <defs>
            <pattern id={`${id}-dots`} width="16" height="16" patternUnits="userSpaceOnUse">
              <circle cx="1.5" cy="1.5" r="1" className="fill-line" />
            </pattern>
          </defs>
          <rect width={W} height={H} fill={`url(#${id}-dots)`} />

          {/* Wires: the feed, then one branch per machine. */}
          <path d={FEED} className="fill-none stroke-line-strong" strokeWidth="2" />
          {MACHINES.map((m) => (
            <path key={m.key} d={branch(m.y)} className="fill-none stroke-line-strong" strokeWidth="2" />
          ))}

          {reduced ? (
            <g className="fill-none" strokeWidth="3.4" strokeLinecap="round" strokeDasharray="0.1 10">
              <path d={FEED} className="stroke-brand" />
              {MACHINES.map((m) => <path key={m.key} d={branch(m.y)} className={m.key === 'compressor' ? 'stroke-warning' : 'stroke-brand'} />)}
            </g>
          ) : (
            <>
              <Particles d={FEED} count={4} dur={1.4} className="fill-brand" />
              {MACHINES.map((m) => (m.key === 'compressor' ? (
                <g key={m.key}>
                  <g className="ef-ph-run"><Particles d={branch(m.y)} count={m.particles} dur={2.8} className="fill-brand" /></g>
                  <g className="ef-ph-waste">
                    <path d={branch(m.y)} className="fill-none stroke-warning" strokeWidth="2" opacity="0.7" />
                    <Particles d={branch(m.y)} count={m.particles} dur={2.8} className="fill-warning" />
                  </g>
                </g>
              ) : (
                <Particles key={m.key} d={branch(m.y)} count={m.particles} dur={2.8} className="fill-brand" />
              )))}
            </>
          )}

          {/* Grid supply */}
          <rect x="8" y={METER.y - 28} width="60" height="56" rx="8" className="fill-surface stroke-line-strong" strokeWidth="1.5" />
          <Zap x="26" y={METER.y - 12} width="24" height="24" className="text-brand" />
          <text x="38" y={METER.y + 52} textAnchor="middle" className="fill-ink-2 text-[14px] font-medium">{t('landing.flow.grid')}</text>

          {/* Main meter */}
          <circle cx={METER.x} cy={METER.y} r={METER.r} className="fill-surface stroke-brand" strokeWidth="2" />
          <Gauge x={METER.x - 11} y={METER.y - 11} width="22" height="22" className="text-brand" />
          <text x={METER.x} y={METER.y + 44} textAnchor="middle" className="fill-ink-2 text-[14px] font-medium">{t('landing.flow.meter')}</text>

          {/* Machines, each with the meter that watches it */}
          {MACHINES.map((m, i) => {
            const Icon = m.icon
            const waste = m.key === 'compressor'
            return (
              <g key={m.key}>
                <circle cx={CARD.x - 14} cy={m.y} r="3.5" className="fill-brand" />
                {!reduced && <circle cx={CARD.x - 14} cy={m.y} r="3.5" className="ef-ping fill-brand" style={{ animationDelay: `${i * 0.6}s` }} />}
                <rect x={CARD.x} y={m.y - CARD.h / 2} width={CARD.w} height={CARD.h} rx="6" className="fill-surface stroke-line-strong" strokeWidth="1.5" />
                {waste && (
                  <g className={reduced ? '' : 'ef-ph-waste'}>
                    <rect x={CARD.x} y={m.y - CARD.h / 2} width={CARD.w} height={CARD.h} rx="6" className="fill-warning-subtle stroke-warning" strokeWidth="1.5" />
                  </g>
                )}
                {waste && !reduced && (
                  <g className="ef-ph-off">
                    <rect x={CARD.x} y={m.y - CARD.h / 2} width={CARD.w} height={CARD.h} rx="6" className="fill-good-subtle stroke-good" strokeWidth="1.5" />
                  </g>
                )}
                <Icon x={CARD.x + 11} y={m.y - 10} width="20" height="20" className="text-ink-2" />
                <text x={CARD.x + 37} y={m.y + 5} className="fill-ink text-[13.5px] font-medium">{t(`landing.flow.${m.key}`)}</text>
                <circle cx={CARD.x + CARD.w - 13} cy={m.y} r="4.5" className={waste && reduced ? 'fill-warning' : 'fill-brand'} />
                {waste && !reduced && (
                  <>
                    <circle cx={CARD.x + CARD.w - 13} cy={m.y} r="4.5" className="ef-ph-waste fill-warning" />
                    <circle cx={CARD.x + CARD.w - 13} cy={m.y} r="4.5" className="ef-ph-off fill-ink-3" />
                  </>
                )}
              </g>
            )
          })}
        </svg>

        {/* What EnergyFlow says about the compressor (HTML, so the text stays readable on a phone). */}
        <div className="pointer-events-none absolute right-[2%] top-[1.5%]" aria-hidden="true">
          <span className={cn('flex items-center gap-1.5 rounded-sm border border-warning/60 bg-warning-subtle px-2 py-1 text-[11.5px] font-medium text-warning-text shadow-sm sm:text-xs', !reduced && 'ef-ph-waste')}>
            <CircleAlert className="size-3.5 shrink-0" />
            {t('landing.flow.detected')}
          </span>
          {!reduced && (
            <span className="ef-ph-off absolute right-0 top-0 flex items-center gap-1.5 whitespace-nowrap rounded-sm border border-good/50 bg-good-subtle px-2 py-1 text-[11.5px] font-medium text-good-text shadow-sm sm:text-xs">
              <CircleCheck className="size-3.5 shrink-0" />
              {t('landing.flow.verified')}
            </span>
          )}
        </div>
      </div>

      <figcaption className="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-line px-4 py-2.5 text-xs text-ink-3">
        <span className="inline-flex items-center gap-1.5"><span className="size-2 rounded-full bg-brand" aria-hidden="true" />{t('landing.flow.legendEnergy')}</span>
        <span className="inline-flex items-center gap-1.5"><span className="size-2 rounded-full bg-warning" aria-hidden="true" />{t('landing.flow.legendWaste')}</span>
        <span className="inline-flex items-center gap-1.5"><span className="size-2 rounded-full bg-good" aria-hidden="true" />{t('landing.flow.legendVerified')}</span>
        <span className="basis-full text-[11.5px]">{t('landing.flow.caption')}</span>
      </figcaption>
    </figure>
  )
}
