import { AbsoluteFill, Easing, interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion'
import { Sfx } from '../components/Sfx'
import { Eyebrow, Reveal, Rich, Title } from '../components/Text'
import { clamp, colors, mono, sans } from '../theme'
import { copy } from '../copy'

// g CO₂e per kWh in 2025, as cited on the landing page (Ember via Our World in Data).
const KOSOVO = 901
const EU = 209.9
const BAR = 1060

function BarRow({ label, grams, decimals, fraction, color }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', height: 96 }}>
      <div style={{ width: 260, fontFamily: sans, fontSize: 34, fontWeight: 600, color: colors.text }}>{label}</div>
      <div style={{ position: 'relative', width: BAR, height: 60, borderRadius: 14, background: colors.surface2 }}>
        <div style={{ position: 'absolute', left: 0, top: 0, bottom: 0, width: BAR * fraction, borderRadius: 14, background: color }} />
      </div>
      <div style={{ marginLeft: 28, fontFamily: mono, fontSize: 30, fontWeight: 500, color: colors.text2, whiteSpace: 'nowrap' }}>
        <Rich>{`${grams.toFixed(decimals)} ${copy.carbon.unit}`}</Rich>
      </div>
    </div>
  )
}

/** Kosovo's grid emits about 4.3 times the EU average per kWh, so every saved kWh counts more. */
export function CarbonFact() {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const t = copy.carbon
  const stat = interpolate(frame, [22, 70], [0, KOSOVO / 1000], { ...clamp, easing: Easing.out(Easing.cubic) })
  const eu = interpolate(frame, [56, 86], [0, 1], { ...clamp, easing: Easing.out(Easing.cubic) })
  const kosovo = interpolate(frame, [66, 112], [0, 1], { ...clamp, easing: Easing.out(Easing.cubic) })
  const badge = spring({ frame: frame - 112, fps, config: { damping: 12, stiffness: 150 } })

  return (
    <AbsoluteFill>
      <div style={{ position: 'absolute', left: 150, top: 120, width: 1620 }}>
        <Reveal delay={2}>
          <Eyebrow color={colors.warning}>{t.eyebrow}</Eyebrow>
        </Reveal>
        <Reveal delay={8}>
          <Title size={72} style={{ marginTop: 20 }}>
            {t.title}
          </Title>
        </Reveal>
      </div>

      <Reveal delay={18} style={{ position: 'absolute', left: 150, top: 320 }}>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 24 }}>
          <span style={{ fontFamily: sans, fontSize: 168, fontWeight: 700, letterSpacing: '-0.03em', color: colors.text, fontVariantNumeric: 'tabular-nums' }}>
            {stat.toFixed(3)}
          </span>
          <span style={{ fontFamily: sans, fontSize: 48, fontWeight: 500, color: colors.text2 }}>
            <Rich>{t.stat}</Rich>
          </span>
        </div>
        <div style={{ fontFamily: sans, fontSize: 32, color: colors.text3 }}>{t.statNote}</div>
      </Reveal>

      <div
        style={{
          position: 'absolute',
          left: 1290,
          top: 352,
          padding: '26px 36px 30px',
          borderRadius: 24,
          background: colors.warningSubtle,
          border: `2px solid ${colors.warning}`,
          opacity: Math.min(1, badge),
          transform: `scale(${0.7 + 0.3 * badge})`,
        }}
      >
        <div style={{ fontFamily: sans, fontSize: 104, fontWeight: 700, lineHeight: 1, color: colors.warning }}>≈ 4.3×</div>
        <div style={{ marginTop: 12, maxWidth: 380, fontFamily: sans, fontSize: 28, color: colors.text2 }}>
          <Rich>{t.multiple}</Rich>
        </div>
      </div>

      <div style={{ position: 'absolute', left: 150, top: 640 }}>
        <BarRow label={t.kosovo} grams={KOSOVO * kosovo} decimals={0} fraction={kosovo} color={colors.warning} />
        <BarRow label={t.eu} grams={EU * eu} decimals={1} fraction={(EU / KOSOVO) * eu} color={colors.text3} />
      </div>

      <Reveal delay={120} style={{ position: 'absolute', left: 150, bottom: 64 }}>
        <div style={{ fontFamily: sans, fontSize: 22, color: colors.text3 }}>{t.source}</div>
      </Reveal>
      <Sfx name="sweep-up" at={56} volume={0.35} />
      <Sfx name="hit" at={112} volume={0.55} />
    </AbsoluteFill>
  )
}
