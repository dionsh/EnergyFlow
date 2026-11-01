import { AbsoluteFill, Easing, Img, interpolate, staticFile, useCurrentFrame } from 'remotion'
import { AppWindow, ScreenText, WIN } from '../components/AppWindow'
import { Sfx } from '../components/Sfx'
import { clamp, colors } from '../theme'
import { copy } from '../copy'

// The landing page's screenshots of the demo company (tools/landing-shots), 2240×1400, and the part
// of each screen the camera moves in on. `scale` caps the zoom where a closer view would cut through
// the cards above the focus.
const SHOT = { w: 2240, h: 1400 }
const SHOTS = {
  overview: { image: 'shots/overview.webp', focus: { x: 461, y: 272, w: 1152, h: 369 } },
  waste: { image: 'shots/waste.webp', focus: { x: 1640, y: 660, w: 556, h: 735 }, scale: 0.62 },
  carbon: { image: 'shots/carbon.webp', focus: { x: 461, y: 385, w: 1297, h: 318 } },
}

const within = (value, min, max) => Math.min(max, Math.max(min, value))

/** One screen of the app in a window: it settles in, then the camera pushes in on what matters. */
export function Showcase({ index, count, shot }) {
  const frame = useCurrentFrame()
  const { image, focus, scale } = SHOTS[shot]
  const t = copy.showcase[shot]

  const zoom = interpolate(frame, [50, 100], [0, 1], { ...clamp, easing: Easing.inOut(Easing.cubic) })
  const spot = interpolate(frame, [96, 112], [0, 1], clamp)

  const s0 = WIN.w / SHOT.w
  const s1 = scale ?? Math.min((0.8 * WIN.w) / focus.w, (0.8 * WIN.h) / focus.h)
  const s = s0 + (s1 - s0) * zoom
  const cx = SHOT.w / 2 + (focus.x + focus.w / 2 - SHOT.w / 2) * zoom
  const cy = SHOT.h / 2 + (focus.y + focus.h / 2 - SHOT.h / 2) * zoom
  const tx = within(WIN.w / 2 - s * cx, WIN.w - s * SHOT.w, 0)
  const ty = within(WIN.h / 2 - s * cy, WIN.h - s * SHOT.h, 0)

  return (
    <AbsoluteFill>
      <ScreenText index={index} count={count} {...t} />
      <AppWindow>
        <div style={{ position: 'absolute', left: 0, top: 0, width: SHOT.w, height: SHOT.h, transformOrigin: '0 0', transform: `translate(${tx}px, ${ty}px) scale(${s})` }}>
          <Img src={staticFile(image)} style={{ display: 'block', width: SHOT.w, height: SHOT.h }} />
          {/* Spotlight: an outline round the focus, and everything else dimmed. */}
          <div
            style={{
              position: 'absolute',
              left: focus.x,
              top: focus.y,
              width: focus.w,
              height: focus.h,
              boxSizing: 'border-box',
              borderRadius: 16,
              border: `${3 / s}px solid ${colors.glow}`,
              boxShadow: `0 0 0 4000px rgba(7, 16, 13, 0.5), 0 0 ${30 / s}px rgba(97, 214, 154, 0.6)`,
              opacity: spot,
            }}
          />
        </div>
      </AppWindow>
      <Sfx name="zoom" at={50} volume={0.35} />
      <Sfx name="shimmer" at={96} volume={0.4} />
    </AbsoluteFill>
  )
}
