import { spring, useCurrentFrame, useVideoConfig } from 'remotion'
import { colors, sans } from '../theme'

/** Draws "₂" as a subscript 2: the Latin subset of IBM Plex Sans has no subscript digits. */
export function Rich({ children }) {
  if (typeof children !== 'string' || !children.includes('₂')) return children
  return children.split('₂').map((part, i) => (
    <span key={i}>
      {i > 0 && <span style={{ position: 'relative', top: '0.22em', fontSize: '0.62em' }}>2</span>}
      {part}
    </span>
  ))
}

/** Fades and lifts its children in, `delay` frames into the scene. */
export function Reveal({ delay = 0, distance = 28, style, children }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const p = spring({ frame: frame - delay, fps, config: { damping: 200 }, durationInFrames: 26 })
  return <div style={{ opacity: p, transform: `translateY(${(1 - p) * distance}px)`, ...style }}>{children}</div>
}

export function Eyebrow({ color = colors.brand, style, children }) {
  return (
    <div style={{ fontFamily: sans, fontSize: 24, fontWeight: 600, letterSpacing: '0.16em', textTransform: 'uppercase', color, ...style }}>
      <Rich>{children}</Rich>
    </div>
  )
}

export function Title({ size = 72, color = colors.text, style, children }) {
  return (
    <div style={{ fontFamily: sans, fontSize: size, fontWeight: 600, lineHeight: 1.1, letterSpacing: '-0.02em', color, textWrap: 'balance', ...style }}>
      <Rich>{children}</Rich>
    </div>
  )
}
