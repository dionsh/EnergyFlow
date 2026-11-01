import { AbsoluteFill, interpolate, interpolateColors, spring, useCurrentFrame, useVideoConfig } from 'remotion'
import { Activity, FileText, Leaf, Lightbulb, MessageSquareText, Power, ScanLine, TrendingDown, TriangleAlert } from 'lucide-react'
import { Sfx } from '../components/Sfx'
import { Eyebrow, Reveal, Rich, Title } from '../components/Text'
import { clamp, colors, sans } from '../theme'
import { copy } from '../copy'

// The app's own icons for each area (frontend/src/app/navigation.js).
const ICONS = {
  live: Activity,
  waste: TriangleAlert,
  opportunities: Lightbulb,
  control: Power,
  impact: TrendingDown,
  carbon: Leaf,
  reports: FileText,
  scan: ScanLine,
  assistant: MessageSquareText,
}
const TILE = { w: 530, h: 172, gapX: 30, gapY: 30 }
const LEFT = (1920 - (3 * TILE.w + 2 * TILE.gapX)) / 2
const TOP = 300

function Tile({ item, i }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const enter = spring({ frame: frame - (18 + i * 5), fps, config: { damping: 14, stiffness: 110 } })
  const sweep = 120 + i * 9
  const glow = interpolate(frame, [sweep, sweep + 8, sweep + 30], [0, 1, 0.25], clamp)
  const Icon = ICONS[item.icon]

  return (
    <div
      style={{
        position: 'absolute',
        left: LEFT + (i % 3) * (TILE.w + TILE.gapX),
        top: TOP + Math.floor(i / 3) * (TILE.h + TILE.gapY),
        width: TILE.w,
        height: TILE.h,
        boxSizing: 'border-box',
        display: 'flex',
        gap: 24,
        padding: 28,
        borderRadius: 20,
        background: 'rgba(21, 28, 25, 0.9)',
        border: `1.5px solid ${interpolateColors(glow, [0, 1], [colors.border, colors.brand])}`,
        boxShadow: `0 0 ${40 * glow}px rgba(63, 185, 132, ${0.25 * glow})`,
        opacity: Math.min(1, enter),
        transform: `translateY(${(1 - enter) * 40}px) scale(${0.92 + 0.08 * enter})`,
      }}
    >
      <div style={{ width: 68, height: 68, flexShrink: 0, borderRadius: 16, display: 'grid', placeItems: 'center', background: colors.brandSubtle, color: colors.brand }}>
        <Icon size={36} strokeWidth={2} />
      </div>
      <div style={{ fontFamily: sans }}>
        <div style={{ fontSize: 30, fontWeight: 600, color: colors.text }}>
          <Rich>{item.title}</Rich>
        </div>
        <div style={{ marginTop: 8, fontSize: 22, lineHeight: 1.4, color: colors.text2 }}>
          <Rich>{item.body}</Rich>
        </div>
      </div>
    </div>
  )
}

export function Features() {
  const t = copy.features
  return (
    <AbsoluteFill>
      <div style={{ position: 'absolute', left: LEFT, top: 110 }}>
        <Reveal delay={2}>
          <Eyebrow>{t.eyebrow}</Eyebrow>
        </Reveal>
        <Reveal delay={6}>
          <Title size={64} style={{ marginTop: 16 }}>
            {t.title}
          </Title>
        </Reveal>
      </div>
      {t.items.map((item, i) => (
        <Tile key={item.icon} item={item} i={i} />
      ))}
      <Reveal delay={80} style={{ position: 'absolute', left: 0, right: 0, bottom: 84, textAlign: 'center' }}>
        <div style={{ fontFamily: sans, fontSize: 28, color: colors.text3 }}>{t.note}</div>
      </Reveal>
      {/* One plink per tile as it lands (5 frames apart), then a shimmer for the sweep. */}
      <Sfx name="cascade" at={18} volume={0.45} />
      <Sfx name="shimmer" at={120} volume={0.35} />
    </AbsoluteFill>
  )
}
