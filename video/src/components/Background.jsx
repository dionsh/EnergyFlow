import { AbsoluteFill, useCurrentFrame } from 'remotion'
import { colors } from '../theme'

/** The stage under every scene: the landing hero's dot grid, drifting slowly, with a soft brand glow. */
export function Background() {
  const frame = useCurrentFrame()
  const drift = frame * 0.25
  const breathe = 0.85 + 0.15 * Math.sin(frame / 45)
  return (
    <AbsoluteFill style={{ backgroundColor: colors.bg }}>
      <AbsoluteFill
        style={{
          backgroundImage: 'radial-gradient(circle, rgba(124, 136, 130, 0.22) 1.5px, transparent 2px)',
          backgroundSize: '40px 40px',
          backgroundPosition: `${drift}px ${drift / 2}px`,
        }}
      />
      <AbsoluteFill style={{ opacity: breathe, background: 'radial-gradient(ellipse 70% 60% at 50% 45%, rgba(63, 185, 132, 0.11), transparent 70%)' }} />
      <AbsoluteFill style={{ background: 'radial-gradient(ellipse 120% 100% at 50% 50%, transparent 55%, rgba(0, 0, 0, 0.6) 100%)' }} />
    </AbsoluteFill>
  )
}
