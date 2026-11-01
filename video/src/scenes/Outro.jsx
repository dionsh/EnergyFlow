import { AbsoluteFill, Img, interpolate, spring, staticFile, useCurrentFrame, useVideoConfig } from 'remotion'
import { LogoMark } from '../components/LogoMark'
import { Sfx } from '../components/Sfx'
import { Reveal, Title } from '../components/Text'
import { clamp, colors, mono, sans } from '../theme'
import { copy } from '../copy'

// The logo's wave (components/LogoMark.jsx) stretched across the frame: half-waves 180 px wide, 70 px high.
const WAVE = `M-100 965c90 0 90-70 180-70s90 70 180 70${' s90-70 180-70s90 70 180 70'.repeat(6)}`

function FlowLine() {
  const frame = useCurrentFrame()
  const draw = interpolate(frame, [0, 40], [1000, 0], clamp)
  return (
    <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0 }}>
      <defs>
        <filter id="outro-glow" x="-10%" y="-50%" width="120%" height="200%">
          <feGaussianBlur stdDeviation="5" result="blur" />
          <feMerge>
            <feMergeNode in="blur" />
            <feMergeNode in="SourceGraphic" />
          </feMerge>
        </filter>
      </defs>
      <path d={WAVE} pathLength={1000} fill="none" stroke={colors.glow} strokeOpacity={0.2} strokeWidth={3} strokeDasharray={1000} strokeDashoffset={draw} />
      {/* Three pulses: one dash per third of the path, moving along it. */}
      <path
        d={WAVE}
        pathLength={1000}
        fill="none"
        stroke={colors.glow}
        strokeWidth={4}
        strokeLinecap="round"
        strokeDasharray="36 297.33"
        strokeDashoffset={-frame * 2.6}
        filter="url(#outro-glow)"
        opacity={interpolate(frame, [20, 40], [0, 0.9], clamp)}
      />
    </svg>
  )
}

/** The promise, the name, and a way to try it: the Demo Day QR code (docs/demo-day). */
export function Outro() {
  const frame = useCurrentFrame()
  const { fps, durationInFrames } = useVideoConfig()
  const t = copy.outro
  const pop = spring({ frame: frame - 4, fps, config: { damping: 12, stiffness: 140 } })
  const wave = interpolate(frame, [10, 28], [0, 1], clamp)
  const qr = spring({ frame: frame - 48, fps, config: { damping: 16, stiffness: 100 } })
  const fadeOut = interpolate(frame, [durationInFrames - 24, durationInFrames], [0, 1], clamp)

  return (
    <AbsoluteFill>
      <FlowLine />
      <div style={{ position: 'absolute', left: 150, top: 220, width: 1100 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 28 }}>
          <div style={{ opacity: Math.min(1, pop), transform: `scale(${0.6 + 0.4 * pop})` }}>
            <LogoMark size={104} draw={wave} />
          </div>
          <Reveal delay={12} distance={16}>
            <div style={{ fontFamily: sans, fontSize: 76, fontWeight: 600, letterSpacing: '-0.01em', color: colors.text }}>EnergyFlow</div>
          </Reveal>
        </div>
        <Reveal delay={24}>
          <Title size={68} style={{ marginTop: 64 }}>
            {t.title[0]}
          </Title>
        </Reveal>
        <Reveal delay={36}>
          <Title size={68} color={colors.glow} style={{ marginTop: 8 }}>
            {t.title[1]}
          </Title>
        </Reveal>
        <Reveal delay={60}>
          <div style={{ marginTop: 56, fontFamily: sans, fontSize: 26, color: colors.text3 }}>{t.footer}</div>
        </Reveal>
      </div>

      <div
        style={{
          position: 'absolute',
          left: 1340,
          top: 200,
          width: 430,
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          opacity: Math.min(1, qr),
          transform: `translateX(${(1 - qr) * 80}px)`,
        }}
      >
        <div style={{ padding: 26, borderRadius: 28, background: '#ffffff', boxShadow: '0 40px 100px rgba(0, 0, 0, 0.55), 0 0 0 6px rgba(97, 214, 154, 0.18)' }}>
          <Img src={staticFile('demo-qr.svg')} style={{ display: 'block', width: 330, height: 330 }} />
        </div>
        <div style={{ marginTop: 30, fontFamily: sans, fontSize: 36, fontWeight: 600, color: colors.text }}>{t.demo}</div>
        <div style={{ marginTop: 10, fontFamily: mono, fontSize: 24, whiteSpace: 'nowrap', color: colors.text2 }}>{t.url}</div>
      </div>

      <AbsoluteFill style={{ backgroundColor: '#000000', opacity: fadeOut }} />
      <Sfx name="impact" at={4} volume={0.6} />
      <Sfx name="whoosh" at={48} volume={0.3} />
    </AbsoluteFill>
  )
}
