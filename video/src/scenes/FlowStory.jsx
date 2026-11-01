import { AbsoluteFill, Easing, interpolate, interpolateColors, spring, useCurrentFrame, useVideoConfig } from 'remotion'
import { evolvePath, getLength, getPointAtLength } from '@remotion/paths'
import { CircleAlert, CircleCheck, Factory, Gauge, Lightbulb, LoaderCircle, Moon, Power, Snowflake, Wind, Zap } from 'lucide-react'
import { Cursor } from '../components/Cursor'
import { Sfx } from '../components/Sfx'
import { Eyebrow, Reveal, Rich, Title } from '../components/Text'
import { clamp, colors, mono, sans } from '../theme'
import { copy } from '../copy'

// The landing hero's story (frontend/src/features/landing/EnergyFlowAnimation.jsx), told in full: the
// shift ends, the compressor keeps running, EnergyFlow flags it, it is turned off and the meter confirms it.
const GRID = { x: 220, y: 660 }
const METER = { x: 470, y: 660, r: 46 }
const CARD = { x: 820, w: 470, h: 104 }
const PANEL = { x: 1330, y: 330, w: 470, h: 296 }
const BUTTON = { x: PANEL.x + 28, y: PANEL.y + 208, w: 200, h: 60 }

const FEED = `M${GRID.x + 60},${GRID.y} L${METER.x - METER.r},${METER.y}`
const branch = (y) => `M${METER.x + METER.r},${METER.y} C${METER.x + METER.r + 120},${METER.y} ${CARD.x - 150},${y} ${CARD.x - 30},${y}`

// Key moments, in frames.
const T = { shiftEnd: 110, detect: 150, click: 262, off: 292, verified: 304 }

/** From the Turn off click to the meter's confirmation: the music steps back for it (Promo.jsx). */
export const TURN_OFF = [T.click, T.verified]

// Draw in kW. The end of the shift winds each machine down, except the compressor: it runs until EnergyFlow turns it off.
const MACHINES = [
  { icon: Wind, y: 390, kw: 5.46, stop: [T.off, T.off + 10], particles: 6 },
  { icon: Factory, y: 570, kw: 11.8, stop: [112, 132], particles: 7 },
  { icon: Snowflake, y: 750, kw: 6.4, stop: [118, 138], particles: 5 },
  { icon: Lightbulb, y: 930, kw: 1.9, stop: [124, 140], particles: 4 },
].map((m) => ({ ...m, path: branch(m.y), length: getLength(branch(m.y)) }))
const FEED_LENGTH = getLength(FEED)

const SHIFT_END = 21 * 60
const TARIFF = 0.0889 // €/kWh: the Overview alert's €0.32 for 3.6 kWh
const GRID_FACTOR = 0.901 // kg CO₂e/kWh, Kosovo 2025

// Clock minutes: fast through the evening, slowing once the compressor is flagged.
const minutesAt = (frame) =>
  interpolate(frame, [0, T.shiftEnd, 250, T.off, 450], [20 * 60 + 44, SHIFT_END, SHIFT_END + 40, SHIFT_END + 41, SHIFT_END + 43], clamp)

function power(m, i, frame) {
  const noise = 1 + 0.05 * Math.sin(frame / 7 + i * 2) + 0.03 * Math.sin(frame / 3.1 + i)
  return m.kw * noise * interpolate(frame, m.stop, [1, 0], clamp)
}

const wasteColor = (frame) => interpolateColors(frame, [T.detect, T.detect + 10], [colors.glow, colors.warning])

/** Dots of energy running along a wire; `opacity` follows how much power flows through it. */
function Particles({ path, length, count, period, opacity, color }) {
  const frame = useCurrentFrame()
  if (opacity <= 0.01) return null
  return Array.from({ length: count }, (_, k) => {
    const t = (frame / period + k / count) % 1
    const p = getPointAtLength(path, t * length)
    const ends = Math.min(1, t / 0.06, (1 - t) / 0.06)
    return <circle key={k} cx={p.x} cy={p.y} r={7} fill={color} opacity={opacity * ends} />
  })
}

function Wires() {
  const frame = useCurrentFrame()
  const feed = evolvePath(interpolate(frame, [10, 26], [0, 1], clamp), FEED)
  const branches = interpolate(frame, [18, 48], [0, 1], { ...clamp, easing: Easing.inOut(Easing.cubic) })
  const flow = interpolate(frame, [40, 56], [0, 1], clamp)
  const total = MACHINES.reduce((sum, m, i) => sum + power(m, i, frame), 0)
  const waste = interpolate(frame, [T.detect, T.detect + 10, T.off, T.off + 8], [0, 1, 1, 0], clamp)

  return (
    <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0 }}>
      <defs>
        <filter id="flow-glow" x="-50%" y="-50%" width="200%" height="200%">
          <feGaussianBlur stdDeviation="5" result="blur" />
          <feMerge>
            <feMergeNode in="blur" />
            <feMergeNode in="SourceGraphic" />
          </feMerge>
        </filter>
      </defs>
      <path d={FEED} fill="none" stroke={colors.borderStrong} strokeWidth={3} strokeDasharray={feed.strokeDasharray} strokeDashoffset={feed.strokeDashoffset} />
      {MACHINES.map((m) => {
        const wire = evolvePath(branches, m.path)
        return <path key={m.y} d={m.path} fill="none" stroke={colors.borderStrong} strokeWidth={3} strokeDasharray={wire.strokeDasharray} strokeDashoffset={wire.strokeDashoffset} />
      })}
      <path d={MACHINES[0].path} fill="none" stroke={colors.warning} strokeWidth={3} opacity={0.7 * waste} />

      <g filter="url(#flow-glow)">
        <Particles path={FEED} length={FEED_LENGTH} count={4} period={42} opacity={flow * Math.min(1, total / 8)} color={wasteColor(frame)} />
        {MACHINES.map((m, i) => (
          <Particles
            key={m.y}
            path={m.path}
            length={m.length}
            count={m.particles}
            period={84}
            opacity={(flow * power(m, i, frame)) / m.kw}
            color={i === 0 ? wasteColor(frame) : colors.glow}
          />
        ))}
      </g>

      {/* The meter on each machine's supply, pinging while it measures power. */}
      {MACHINES.map((m, i) => {
        const on = power(m, i, frame) > 0.05
        const color = i === 0 ? wasteColor(frame) : colors.glow
        const ping = (((frame - i * 18) % 72) + 72) % 72 / 72
        return (
          <g key={m.y} opacity={flow}>
            {on && <circle cx={CARD.x - 30} cy={m.y} r={7 * (1 + 2.4 * ping)} fill={color} opacity={0.5 * (1 - ping)} />}
            <circle cx={CARD.x - 30} cy={m.y} r={7} fill={on ? color : colors.text3} />
          </g>
        )
      })}
    </svg>
  )
}

function Label({ x, y, opacity, style, children }) {
  return (
    <div style={{ position: 'absolute', left: x - 150, top: y, width: 300, textAlign: 'center', fontFamily: sans, fontSize: 26, fontWeight: 500, color: colors.text2, opacity, ...style }}>
      {children}
    </div>
  )
}

function Supply() {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const grid = spring({ frame: frame - 4, fps, config: { damping: 14 } })
  const meter = spring({ frame: frame - 10, fps, config: { damping: 14 } })
  const total = MACHINES.reduce((sum, m, i) => sum + power(m, i, frame), 0)
  const node = { position: 'absolute', boxSizing: 'border-box', display: 'grid', placeItems: 'center', background: colors.surface }

  return (
    <>
      <div
        style={{
          ...node,
          left: GRID.x - 60,
          top: GRID.y - 56,
          width: 120,
          height: 112,
          borderRadius: 20,
          border: `2px solid ${colors.borderStrong}`,
          color: colors.glow,
          opacity: Math.min(1, grid),
          transform: `scale(${0.7 + 0.3 * grid})`,
        }}
      >
        <Zap size={52} strokeWidth={2} />
      </div>
      <Label x={GRID.x} y={GRID.y + 76} opacity={Math.min(1, grid)}>
        {copy.flow.grid}
      </Label>

      <div
        style={{
          ...node,
          left: METER.x - METER.r,
          top: METER.y - METER.r,
          width: METER.r * 2,
          height: METER.r * 2,
          borderRadius: '50%',
          border: `3px solid ${colors.brand}`,
          color: colors.brand,
          opacity: Math.min(1, meter),
          transform: `scale(${0.7 + 0.3 * meter})`,
        }}
      >
        <Gauge size={44} strokeWidth={2} />
      </div>
      <Label x={METER.x} y={METER.y + METER.r + 20} opacity={Math.min(1, meter)}>
        {copy.flow.meter}
      </Label>
      <Label x={METER.x} y={METER.y + METER.r + 58} opacity={Math.min(1, meter)} style={{ fontFamily: mono, fontSize: 28, color: colors.text }}>
        {total.toFixed(1)} kW
      </Label>
    </>
  )
}

function MachineCard({ m, i }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const enter = spring({ frame: frame - (20 + i * 6), fps, config: { damping: 200 }, durationInFrames: 24 })
  const kw = power(m, i, frame)
  const on = kw > 0.05
  const flagged = i === 0
  const states = [T.detect, T.detect + 10, T.off, T.off + 12]
  const border = flagged ? interpolateColors(frame, states, [colors.borderStrong, colors.warning, colors.warning, colors.good]) : colors.borderStrong
  const background = flagged ? interpolateColors(frame, states, [colors.surface, colors.warningSubtle, colors.warningSubtle, colors.goodSubtle]) : colors.surface
  const dot = !on ? colors.text3 : flagged ? wasteColor(frame) : colors.glow
  const Icon = m.icon

  return (
    <div
      style={{
        position: 'absolute',
        left: CARD.x,
        top: m.y - CARD.h / 2,
        width: CARD.w,
        height: CARD.h,
        boxSizing: 'border-box',
        display: 'flex',
        alignItems: 'center',
        gap: 18,
        padding: '0 26px 0 20px',
        borderRadius: 16,
        background,
        border: `2px solid ${border}`,
        opacity: enter,
        transform: `translateX(${(1 - enter) * 60}px)`,
      }}
    >
      <div style={{ width: 60, height: 60, borderRadius: 14, display: 'grid', placeItems: 'center', background: colors.surface2, color: on ? colors.text : colors.text3 }}>
        <Icon size={32} strokeWidth={2} />
      </div>
      <div style={{ flex: 1, fontFamily: sans, fontSize: 31, fontWeight: 600, color: on || flagged ? colors.text : colors.text2 }}>{copy.flow.machines[i]}</div>
      <div style={{ fontFamily: mono, fontSize: 30, fontWeight: 500, whiteSpace: 'nowrap', color: on ? colors.text : colors.text3 }}>{kw.toFixed(1)} kW</div>
      <div style={{ width: 16, height: 16, borderRadius: 8, background: dot, boxShadow: on ? `0 0 14px ${dot}` : 'none' }} />
    </div>
  )
}

function Steps() {
  const frame = useCurrentFrame()
  const stepColors = [
    colors.glow,
    interpolateColors(frame, [T.detect, T.detect + 8, T.verified, T.verified + 8], [colors.text3, colors.warning, colors.warning, colors.glow]),
    interpolateColors(frame, [T.off, T.off + 8], [colors.text3, colors.good]),
    interpolateColors(frame, [T.verified, T.verified + 8], [colors.text3, colors.good]),
  ]
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
      {copy.flow.steps.map((step, i) => (
        <div key={step} style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          {i > 0 && <span style={{ fontFamily: sans, fontSize: 28, color: colors.borderStrong }}>→</span>}
          <span
            style={{
              padding: '8px 22px',
              borderRadius: 999,
              border: `2px solid ${stepColors[i]}`,
              background: `color-mix(in srgb, ${stepColors[i]} 14%, transparent)`,
              color: stepColors[i],
              fontFamily: sans,
              fontSize: 26,
              fontWeight: 600,
            }}
          >
            {step}
          </span>
        </div>
      ))}
    </div>
  )
}

function Clock() {
  const frame = useCurrentFrame()
  const minutes = Math.floor(minutesAt(frame))
  const after = minutes >= SHIFT_END
  const hh = String(Math.floor(minutes / 60)).padStart(2, '0')
  const mm = String(minutes % 60).padStart(2, '0')
  return (
    <div style={{ textAlign: 'right' }}>
      <div style={{ fontFamily: sans, fontSize: 80, fontWeight: 600, lineHeight: 1, letterSpacing: '-0.01em', color: colors.text, fontVariantNumeric: 'tabular-nums' }}>
        {hh}:{mm}
      </div>
      <div
        style={{
          marginTop: 14,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'flex-end',
          gap: 10,
          fontFamily: sans,
          fontSize: 26,
          fontWeight: 600,
          color: after ? colors.warning : colors.text3,
        }}
      >
        {after && <Moon size={26} strokeWidth={2.2} />}
        {after ? copy.flow.afterHours : copy.flow.shiftEnds}
      </div>
    </div>
  )
}

/** The compressor's power as the meter saw it: running after hours, then 0 kW once it is off. */
function Proof() {
  const frame = useCurrentFrame()
  const w = PANEL.w - 56
  const drop = w * 0.72
  const running = Array.from({ length: 25 }, (_, k) => `${((k / 24) * drop).toFixed(1)} ${(14 + 3 * Math.sin(k * 1.7)).toFixed(1)}`)
  const before = `M${running.join(' L')} L${drop} 62`
  const after = `M${drop} 62 L${w} 62`
  const settled = evolvePath(interpolate(frame, [T.verified, T.verified + 30], [0, 1], clamp), after)
  return (
    <svg width={w} height={70} style={{ display: 'block', marginTop: 18, overflow: 'visible' }}>
      <path d={before} fill="none" stroke={colors.warning} strokeWidth={3} strokeLinejoin="round" opacity={0.85} />
      <path d={after} fill="none" stroke={colors.good} strokeWidth={3} strokeDasharray={settled.strokeDasharray} strokeDashoffset={settled.strokeDashoffset} />
      <circle cx={drop} cy={62} r={6} fill={colors.good} />
    </svg>
  )
}

const panelCard = (accent, background) => ({
  position: 'absolute',
  inset: 0,
  boxSizing: 'border-box',
  padding: 28,
  borderRadius: 20,
  background,
  border: `2px solid ${accent}`,
  boxShadow: '0 30px 80px rgba(0, 0, 0, 0.5)',
  fontFamily: sans,
})

/** The alert EnergyFlow raises, its Turn off button, then the meter's confirmation. */
function Panel() {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const t = copy.flow
  if (frame < T.detect) return null

  const show = spring({ frame: frame - (T.detect + 6), fps, config: { damping: 16, stiffness: 120 } })
  const swap = interpolate(frame, [T.verified - 2, T.verified + 10], [0, 1], clamp)
  const kwh = (MACHINES[0].kw * (minutesAt(Math.min(frame, T.off)) - SHIFT_END)) / 60
  const pressed = interpolate(frame, [T.click, T.click + 3, T.click + 8], [1, 0.94, 1], clamp)
  const busy = frame >= T.click + 4
  const heading = { display: 'flex', alignItems: 'flex-start', gap: 14, height: 72, fontSize: 27, fontWeight: 600, lineHeight: '34px' }

  return (
    <div
      style={{
        position: 'absolute',
        left: PANEL.x,
        top: PANEL.y,
        width: PANEL.w,
        height: PANEL.h,
        opacity: Math.min(1, show),
        transform: `translateX(${(1 - show) * 80}px)`,
      }}
    >
      <div style={{ ...panelCard(colors.warning, colors.warningSubtle), opacity: 1 - swap }}>
        <div style={{ ...heading, color: colors.warning }}>
          <CircleAlert size={32} strokeWidth={2.4} style={{ flexShrink: 0, marginTop: 1 }} />
          {t.detected}
        </div>
        <div style={{ marginTop: 8, height: 34, fontSize: 24, color: colors.text2 }}>{t.detectedDetail}</div>
        <div style={{ marginTop: 6, height: 40, fontSize: 25, fontWeight: 500, color: colors.text, fontVariantNumeric: 'tabular-nums' }}>
          <Rich>{`${kwh.toFixed(1)} kWh · €${(kwh * TARIFF).toFixed(2)} · ${(kwh * GRID_FACTOR).toFixed(1)} kg CO₂e`}</Rich>
        </div>
        <div
          style={{
            position: 'absolute',
            left: BUTTON.x - PANEL.x,
            top: BUTTON.y - PANEL.y,
            width: BUTTON.w,
            height: BUTTON.h,
            boxSizing: 'border-box',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: 10,
            borderRadius: 12,
            background: colors.brand,
            color: colors.onBrand,
            fontSize: 26,
            fontWeight: 600,
            transform: `scale(${pressed})`,
          }}
        >
          {busy ? (
            <LoaderCircle size={26} strokeWidth={2.6} style={{ transform: `rotate(${frame * 14}deg)` }} />
          ) : (
            <Power size={26} strokeWidth={2.6} />
          )}
          {busy ? t.turningOff : t.turnOff}
        </div>
      </div>

      <div style={{ ...panelCard(colors.good, colors.goodSubtle), opacity: swap, transform: `scale(${0.96 + 0.04 * swap})` }}>
        <div style={{ ...heading, color: colors.good }}>
          <CircleCheck size={32} strokeWidth={2.4} style={{ flexShrink: 0, marginTop: 1 }} />
          {t.verified}
        </div>
        <div style={{ marginTop: 8, fontSize: 24, color: colors.text2 }}>{t.verifiedDetail}</div>
        <div style={{ marginTop: 6, fontSize: 24, color: colors.text3 }}>{t.verifiedNote}</div>
        <Proof />
      </div>
    </div>
  )
}

// The pointer comes in from the bottom right, presses Turn off, and leaves.
const TARGET = { x: BUTTON.x + BUTTON.w / 2 + 12, y: BUTTON.y + BUTTON.h / 2 + 8 }
const POINTER = [
  { frame: T.click - 40, x: 1780, y: 1120 },
  { frame: T.click - 6, ...TARGET },
  { frame: T.click + 18, ...TARGET },
  { frame: T.click + 40, x: TARGET.x + 240, y: TARGET.y + 320, easing: Easing.in(Easing.cubic) },
]

function Sounds() {
  return (
    <>
      {MACHINES.map((m, i) => (
        <Sfx key={m.y} name="tick" at={20 + i * 6} volume={0.35} />
      ))}
      <Sfx name="power-up" at={40} volume={0.35} />
      <Sfx name="wind-down" at={112} volume={0.45} />
      <Sfx name="alert" at={T.detect + 6} volume={0.6} />
      <Sfx name="power-down" at={T.off} volume={0.6} />
      <Sfx name="success" at={T.verified} volume={0.6} />
    </>
  )
}

export function FlowStory() {
  const t = copy.flow
  return (
    <AbsoluteFill>
      <div style={{ position: 'absolute', left: 150, top: 84, width: 1250 }}>
        <Reveal delay={2}>
          <Eyebrow>{t.eyebrow}</Eyebrow>
        </Reveal>
        <Reveal delay={6}>
          <Title size={60} style={{ marginTop: 14 }}>
            {t.title}
          </Title>
        </Reveal>
        <Reveal delay={12} style={{ marginTop: 34 }}>
          <Steps />
        </Reveal>
      </div>
      <Reveal delay={8} style={{ position: 'absolute', right: 120, top: 84 }}>
        <Clock />
      </Reveal>

      <Wires />
      <Supply />
      {MACHINES.map((m, i) => (
        <MachineCard key={m.y} m={m} i={i} />
      ))}
      <Panel />
      <Cursor path={POINTER} clicks={[T.click]} />
      <Sounds />

      <Reveal delay={30} style={{ position: 'absolute', left: 150, bottom: 40 }}>
        <div style={{ fontFamily: sans, fontSize: 22, color: colors.text3 }}>{t.demoNote}</div>
      </Reveal>
    </AbsoluteFill>
  )
}
