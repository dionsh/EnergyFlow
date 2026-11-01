import { spring, useCurrentFrame, useVideoConfig } from 'remotion'
import { Eyebrow, Reveal, Rich, Title } from './Text'
import { colors, mono, sans } from '../theme'

// The browser window the app appears in, and its content area (the app at 1:1 with the 2× screenshots at 0.5).
export const WIN = { x: 680, y: 168, w: 1120, h: 700, bar: 44 }

function TitleBar() {
  return (
    <div style={{ height: WIN.bar, display: 'flex', alignItems: 'center', gap: 9, padding: '0 18px', background: '#101714', borderBottom: `1.5px solid ${colors.border}` }}>
      {[0, 1, 2].map((i) => (
        <span key={i} style={{ width: 13, height: 13, borderRadius: 7, background: colors.borderStrong }} />
      ))}
      <div style={{ flex: 1, display: 'flex', justifyContent: 'center' }}>
        <span style={{ padding: '5px 18px', borderRadius: 8, background: colors.surface2, fontFamily: mono, fontSize: 17, color: colors.text3 }}>energyflow-ks.vercel.app</span>
      </div>
      <span style={{ width: 57 }} />
    </div>
  )
}

/** A browser window with the app inside: it rises and settles as the scene starts. */
export function AppWindow({ children }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const enter = spring({ frame, fps, config: { damping: 18, stiffness: 80 } })
  return (
    <div
      style={{
        position: 'absolute',
        left: WIN.x,
        top: WIN.y,
        width: WIN.w,
        height: WIN.h + WIN.bar,
        overflow: 'hidden',
        borderRadius: 18,
        background: colors.surface,
        boxShadow: `0 0 0 1.5px ${colors.borderStrong}, 0 50px 140px rgba(0, 0, 0, 0.65), 0 0 120px rgba(63, 185, 132, 0.12)`,
        opacity: Math.min(1, enter * 1.4),
        transform: `perspective(2400px) translateY(${(1 - enter) * 80}px) rotateX(${(1 - enter) * 12}deg) scale(${0.94 + 0.06 * enter})`,
      }}
    >
      <TitleBar />
      <div style={{ position: 'relative', width: WIN.w, height: WIN.h, overflow: 'hidden' }}>{children}</div>
    </div>
  )
}

/** The words beside the window: "01 / 04", the page, a title and a sentence. */
export function ScreenText({ index, count, eyebrow, title, body }) {
  return (
    <div style={{ position: 'absolute', left: 120, top: 0, bottom: 0, width: 500, display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
      <Reveal delay={6}>
        <div style={{ fontFamily: mono, fontSize: 26, color: colors.brand }}>
          {String(index).padStart(2, '0')} / {String(count).padStart(2, '0')}
        </div>
      </Reveal>
      <Reveal delay={10}>
        <Eyebrow style={{ marginTop: 16 }}>{eyebrow}</Eyebrow>
      </Reveal>
      <Reveal delay={14}>
        <Title size={60} style={{ marginTop: 18 }}>
          {title}
        </Title>
      </Reveal>
      <Reveal delay={20}>
        <div style={{ marginTop: 26, fontFamily: sans, fontSize: 30, lineHeight: 1.45, color: colors.text2 }}>
          <Rich>{body}</Rich>
        </div>
      </Reveal>
    </div>
  )
}
