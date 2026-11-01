import { Easing, interpolate, useCurrentFrame } from 'remotion'
import { Sfx } from './Sfx'
import { clamp, colors } from '../theme'

/**
 * A mouse pointer gliding through `path` ({ frame, x, y, easing? }, the tip's position) and clicking,
 * with a click sound, at each frame in `clicks`. It is only on screen from the first waypoint to the last.
 */
export function Cursor({ path, clicks = [] }) {
  const frame = useCurrentFrame()
  const sounds = clicks.map((at) => <Sfx key={at} name="click" at={at} volume={0.9} />)
  if (frame < path[0].frame || frame > path[path.length - 1].frame) return sounds

  const next = Math.max(1, path.findIndex((p) => p.frame >= frame))
  const [from, to] = [path[next - 1], path[next]]
  const t = interpolate(frame, [from.frame, Math.max(to.frame, from.frame + 1)], [0, 1], { ...clamp, easing: to.easing ?? Easing.inOut(Easing.cubic) })
  const x = from.x + (to.x - from.x) * t
  const y = from.y + (to.y - from.y) * t
  const press = Math.min(...clicks.map((at) => interpolate(frame, [at - 2, at + 2, at + 7], [1, 0.82, 1], clamp)), 1)
  const click = clicks.find((at) => frame >= at && frame <= at + 16)
  const ripple = click === undefined ? 0 : (frame - click) / 16

  return (
    <>
      {sounds}
      {click !== undefined && (
        <div
          style={{
            position: 'absolute',
            left: x - 40,
            top: y - 40,
            width: 80,
            height: 80,
            borderRadius: '50%',
            border: `3px solid ${colors.text}`,
            opacity: 0.6 * (1 - ripple),
            transform: `scale(${0.3 + ripple})`,
          }}
        />
      )}
      {/* A pointer whose tip, at (12, 6) in its 48 px box, sits on (x, y). */}
      <svg
        width={48}
        height={48}
        viewBox="0 0 24 24"
        style={{ position: 'absolute', left: x - 12, top: y - 6, transform: `scale(${press})`, transformOrigin: '12px 6px', filter: 'drop-shadow(0 6px 10px rgba(0, 0, 0, 0.5))' }}
      >
        <path d="M6 3l13 7.5-6 1.6-3.2 5.9z" fill="#ffffff" stroke={colors.bg} strokeWidth={1.4} strokeLinejoin="round" />
      </svg>
    </>
  )
}
