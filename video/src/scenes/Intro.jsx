import { AbsoluteFill, Easing, interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion'
import { LogoMark } from '../components/LogoMark'
import { Sfx } from '../components/Sfx'
import { clamp, colors, sans } from '../theme'
import { copy } from '../copy'

// The web intro (frontend/index.html) at video scale: tracks draw in from the edges, pulses of energy
// run along them into the mark, then the name appears. Drawn in the intro's 1600×900 units.
const CX = 800
const CY = 392
const TRACKS = [
  `M-40 150C420 150 480 ${CY} ${CX} ${CY}`,
  `M-40 ${CY}H${CX}`,
  `M-40 760C400 760 500 ${CY} ${CX} ${CY}`,
  `M120 -40C180 300 560 ${CY} ${CX} ${CY}`,
  `M1640 150C1180 150 1120 ${CY} ${CX} ${CY}`,
  `M1640 ${CY}H${CX}`,
  `M1640 760C1200 760 1100 ${CY} ${CX} ${CY}`,
  `M1340 940C1280 640 1040 ${CY} ${CX} ${CY}`,
  `M${CX} -40V${CY}`,
]
const DELAYS = [6.6, 10.2, 8.4, 12, 7.8, 9.6, 6, 11.4, 13.2] // frames: the web intro's 0.20–0.44 s
const WAVES = [0, 46, 92] // later waves keep energy arriving while the name is on screen
const PULSE = 24
const MARK = 168
const SCALE = 1080 / 900

function Ring({ start }) {
  const frame = useCurrentFrame()
  const t = interpolate(frame, [start, start + 27], [0, 1], { ...clamp, easing: Easing.out(Easing.quad) })
  const visible = frame >= start && frame < start + 27
  return (
    <div
      style={{
        position: 'absolute',
        inset: 0,
        borderRadius: '22%',
        border: `3px solid ${colors.glow}`,
        opacity: visible ? 0.55 * (1 - t) : 0,
        transform: `scale(${1 + 1.4 * t})`,
      }}
    />
  )
}

export function Intro() {
  const frame = useCurrentFrame()
  const { fps, durationInFrames } = useVideoConfig()

  const draw = interpolate(frame, [3, 27], [1000, 0], { ...clamp, easing: Easing.bezier(0.3, 0, 0.2, 1) })
  const pop = spring({ frame: frame - 30, fps, config: { damping: 11, stiffness: 140, mass: 0.8 } })
  const wave = interpolate(frame, [35, 52], [0, 1], { ...clamp, easing: Easing.out(Easing.quad) })
  const word = spring({ frame: frame - 40, fps, config: { damping: 200 }, durationInFrames: 18 })
  const tagline = spring({ frame: frame - 64, fps, config: { damping: 200 }, durationInFrames: 22 })
  const camera = interpolate(frame, [0, durationInFrames], [1, 1.035])

  return (
    <AbsoluteFill style={{ transform: `scale(${camera})` }}>
      <svg viewBox="0 0 1600 900" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%' }}>
        <defs>
          <filter id="intro-glow" x="-20%" y="-20%" width="140%" height="140%">
            <feGaussianBlur stdDeviation="4" result="blur" />
            <feMerge>
              <feMergeNode in="blur" />
              <feMergeNode in="SourceGraphic" />
            </feMerge>
          </filter>
        </defs>
        {TRACKS.map((d) => (
          <path key={d} d={d} pathLength={1000} fill="none" stroke={colors.glow} strokeOpacity={0.17} strokeWidth={1.4} strokeDasharray={1000} strokeDashoffset={draw} />
        ))}
        <g filter="url(#intro-glow)">
          {WAVES.flatMap((start, w) =>
            TRACKS.map((d, i) => {
              const t = frame - start - DELAYS[i]
              if (t < 0 || t > PULSE) return null
              const offset = interpolate(t, [0, PULSE], [70, -1000], { easing: Easing.bezier(0.5, 0, 0.25, 1) })
              return (
                <path
                  key={`${w}-${i}`}
                  d={d}
                  pathLength={1000}
                  fill="none"
                  stroke={colors.glow}
                  strokeOpacity={w === 0 ? 1 : 0.6}
                  strokeWidth={2.6}
                  strokeLinecap="round"
                  strokeDasharray="70 1100"
                  strokeDashoffset={offset}
                />
              )
            }),
          )}
        </g>
      </svg>

      <div style={{ position: 'absolute', left: 0, right: 0, top: CY * SCALE - MARK / 2, display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
        <div style={{ position: 'relative', width: MARK, height: MARK }}>
          <Ring start={32} />
          <Ring start={84} />
          <div style={{ opacity: Math.min(1, pop), transform: `scale(${0.55 + 0.45 * pop})` }}>
            <LogoMark size={MARK} draw={wave} />
          </div>
        </div>
        <div
          style={{
            marginTop: 36,
            fontFamily: sans,
            fontSize: 80,
            fontWeight: 600,
            letterSpacing: '-0.01em',
            color: colors.text,
            opacity: word,
            transform: `translateY(${(1 - word) * 16}px)`,
          }}
        >
          EnergyFlow
        </div>
        <div
          style={{
            marginTop: 16,
            fontFamily: sans,
            fontSize: 34,
            fontWeight: 500,
            color: colors.text2,
            opacity: tagline,
            transform: `translateY(${(1 - tagline) * 14}px)`,
          }}
        >
          {copy.tagline}
        </div>
      </div>
      <Sfx name="riser" at={0} volume={0.5} />
      <Sfx name="impact" at={30} volume={0.8} />
    </AbsoluteFill>
  )
}
