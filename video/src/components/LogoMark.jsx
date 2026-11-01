import { evolvePath } from '@remotion/paths'
import { colors } from '../theme'

const WAVE = 'M6 20.5c3.5 0 3.5-9 7-9s3.5 9 7 9 3.5-9 6-9'

/** The EnergyFlow mark (frontend/src/components/ui/Logo.jsx); `draw` (0–1) traces the wave. */
export function LogoMark({ size, draw = 1 }) {
  const wave = evolvePath(draw, WAVE)
  return (
    <svg viewBox="0 0 32 32" width={size} height={size} style={{ display: 'block' }}>
      <rect width="32" height="32" rx="7" fill={colors.glow} />
      <path
        d={WAVE}
        fill="none"
        stroke={colors.bg}
        strokeWidth="2.6"
        strokeLinecap="round"
        strokeLinejoin="round"
        strokeDasharray={wave.strokeDasharray}
        strokeDashoffset={wave.strokeDashoffset}
        opacity={draw > 0 ? 1 : 0}
      />
    </svg>
  )
}
