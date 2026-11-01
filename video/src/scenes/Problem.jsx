import { AbsoluteFill, Easing, interpolate, random, spring, useCurrentFrame, useVideoConfig } from 'remotion'
import { CircleHelp } from 'lucide-react'
import { Sfx } from '../components/Sfx'
import { Eyebrow, Reveal, Title } from '../components/Text'
import { clamp, colors, sans } from '../theme'
import { copy } from '../copy'

const BILL_TOTAL = 1593 // the demo company's projected monthly bill (Overview screenshot)
const PAPER = { bg: '#eef2ef', ink: '#14201b', ink2: '#4a5751', line: '#cdd2cc', blank: '#d5dad4' }
const QUESTIONS = [
  { left: 985, top: 205, delay: 76, tilt: -4 },
  { left: 1590, top: 500, delay: 88, tilt: 3 },
  { left: 1120, top: 770, delay: 100, tilt: -2 },
]

function Bill({ total, style }) {
  const t = copy.problem
  return (
    <div
      style={{
        position: 'absolute',
        left: 1180,
        top: 250,
        width: 500,
        padding: '44px 44px 36px',
        borderRadius: 20,
        background: PAPER.bg,
        color: PAPER.ink,
        fontFamily: sans,
        boxShadow: '0 50px 120px rgba(0, 0, 0, 0.55)',
        ...style,
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
        <span style={{ fontSize: 22, fontWeight: 600, letterSpacing: '0.12em', textTransform: 'uppercase' }}>{t.billTitle}</span>
        <span style={{ fontSize: 20, color: PAPER.ink2 }}>{t.billPeriod}</span>
      </div>
      <div style={{ height: 2, background: PAPER.line, margin: '26px 0 12px' }} />
      {t.billLines.map((line, i) => (
        <div key={line} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', height: 54 }}>
          <span style={{ fontSize: 24, color: PAPER.ink2 }}>{line}</span>
          <span style={{ width: 80 + random(`bill-${i}`) * 80, height: 14, borderRadius: 7, background: PAPER.blank }} />
        </div>
      ))}
      <div style={{ height: 2, background: PAPER.line, margin: '12px 0 22px' }} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
        <span style={{ fontSize: 28, fontWeight: 600 }}>{t.billTotal}</span>
        <span style={{ fontSize: 64, fontWeight: 700, letterSpacing: '-0.02em', fontVariantNumeric: 'tabular-nums' }}>
          €{Math.round(total).toLocaleString('en-US')}
        </span>
      </div>
      <div style={{ display: 'flex', gap: 4, height: 46, marginTop: 30, opacity: 0.75 }}>
        {Array.from({ length: 46 }, (_, i) => (
          <span key={i} style={{ width: random(`bar-${i}`) > 0.6 ? 6 : 2.5, background: PAPER.ink }} />
        ))}
      </div>
    </div>
  )
}

function Question({ left, top, delay, tilt, label }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const pop = spring({ frame: frame - delay, fps, config: { damping: 10, stiffness: 160 } })
  const bob = frame > delay ? Math.sin((frame - delay) / 14) * 5 : 0
  return (
    <div
      style={{
        position: 'absolute',
        left,
        top,
        display: 'flex',
        alignItems: 'center',
        gap: 12,
        padding: '14px 28px 14px 20px',
        borderRadius: 999,
        background: colors.warningSubtle,
        border: `2px solid ${colors.warning}`,
        color: colors.warning,
        fontFamily: sans,
        fontSize: 34,
        fontWeight: 600,
        whiteSpace: 'nowrap',
        boxShadow: '0 18px 40px rgba(0, 0, 0, 0.45)',
        opacity: Math.min(1, pop),
        transform: `translateY(${bob}px) scale(${0.6 + 0.4 * pop}) rotate(${tilt}deg)`,
      }}
    >
      <CircleHelp size={34} strokeWidth={2.4} />
      {label}
    </div>
  )
}

/** One total a month, and none of the answers a manufacturer needs. */
export function Problem() {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const t = copy.problem
  const enter = spring({ frame: frame - 14, fps, config: { damping: 15, stiffness: 90 } })
  const total = interpolate(frame, [32, 72], [0, BILL_TOTAL], { ...clamp, easing: Easing.out(Easing.cubic) })

  return (
    <AbsoluteFill>
      <div style={{ position: 'absolute', left: 150, top: 340, width: 900 }}>
        <Reveal delay={4}>
          <Eyebrow>{t.eyebrow}</Eyebrow>
        </Reveal>
        <Reveal delay={10}>
          <Title size={96} style={{ marginTop: 24 }}>
            {t.title}
          </Title>
        </Reveal>
        <Reveal delay={44}>
          <Title size={58} color={colors.text2} style={{ marginTop: 22, fontWeight: 500 }}>
            {t.subtitle}
          </Title>
        </Reveal>
      </div>
      <Bill
        total={total}
        style={{ opacity: Math.min(1, enter * 1.5), transform: `translateX(${(1 - enter) * 300}px) rotate(${-3 + (1 - enter) * 10}deg)` }}
      />
      {QUESTIONS.map((spot, i) => (
        <Question key={t.questions[i]} {...spot} label={t.questions[i]} />
      ))}
      <Sfx name="swish" at={14} volume={0.45} />
      {QUESTIONS.map((spot) => (
        <Sfx key={spot.delay} name="pop" at={spot.delay} volume={0.55} />
      ))}
    </AbsoluteFill>
  )
}
