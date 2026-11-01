import { useLayoutEffect, useRef, useState } from 'react'
import { AbsoluteFill, continueRender, delayRender, Easing, Img, interpolate, staticFile, useCurrentFrame } from 'remotion'
import { ArrowUp, Circle, CircleCheck, Database, History, LoaderCircle, MessageSquareText, Power, SquarePen, X } from 'lucide-react'
import { AppWindow, ScreenText, WIN } from '../components/AppWindow'
import { Cursor } from '../components/Cursor'
import { Sfx } from '../components/Sfx'
import { Rich } from '../components/Text'
import { clamp, colors, sans } from '../theme'
import { copy } from '../copy'

// "Ask EnergyFlow" (frontend/src/features/assistant/AssistantPanel.jsx) opened over the Overview and
// enlarged for video: a question answered from the data, then a Turn Off that waits for "Yes".
// Positions are in the window's content area unless they say otherwise.
const PANEL = { x: 480, top: 44, w: 640, header: 76, composer: 132 }
const BODY = { top: PANEL.top + PANEL.header, h: WIN.h - PANEL.top - PANEL.header - PANEL.composer, w: PANEL.w - 40, pad: 20, gap: 18 }
const ASK_BUTTON = { x: 713, y: 9, w: 177, h: 30 } // "Ask EnergyFlow" in the screenshot's top bar
const SEND = { x: PANEL.x + PANEL.w - 18 - 8 - 22, y: WIN.h - PANEL.composer + 14 + 30 } // centre of the send button
const ORIGIN = { x: WIN.x, y: WIN.y + WIN.bar } // the content area's corner on screen

// Key moments, in frames.
const T = { ask: 44, type1: [70, 136], send1: 150, answer1: 176, type2: [252, 282], send2: 288, answer2: 312, confirm: 362, steps: [368, 380, 392, 406] }

const TEXT = { fontFamily: sans, fontSize: 19, lineHeight: 1.5, color: colors.text }

/** **bold** spans, as MarkdownLite draws them. */
function Md({ text }) {
  return text.split(/(\*\*[^*]+\*\*)/).map((part, i) =>
    part.startsWith('**') ? (
      <strong key={i} style={{ fontWeight: 600 }}>
        <Rich>{part.slice(2, -2)}</Rich>
      </strong>
    ) : (
      <Rich key={i}>{part}</Rich>
    ),
  )
}

/** Paragraphs, and "- " lines as a bulleted list. */
function Answer({ lines }) {
  const blocks = []
  for (const line of lines) {
    if (line.startsWith('- ') && Array.isArray(blocks.at(-1))) blocks.at(-1).push(line.slice(2))
    else blocks.push(line.startsWith('- ') ? [line.slice(2)] : line)
  }
  return blocks.map((block, i) =>
    Array.isArray(block) ? (
      <div key={i} style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 4 }}>
        {block.map((item) => (
          <div key={item} style={{ display: 'flex', gap: 10 }}>
            <span style={{ color: colors.text3 }}>•</span>
            <span>
              <Md text={item} />
            </span>
          </div>
        ))}
      </div>
    ) : (
      <p key={i} style={{ margin: i === 0 ? 0 : '10px 0 0' }}>
        <Md text={block} />
      </p>
    ),
  )
}

function UserBubble({ text }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
      <div style={{ ...TEXT, fontSize: 20, maxWidth: '85%', padding: '12px 18px', borderRadius: 14, borderBottomRightRadius: 4, background: colors.brandSubtle }}>{text}</div>
    </div>
  )
}

function AssistantCard({ source, children }) {
  const t = copy.assistant
  return (
    <div style={{ ...TEXT, padding: '16px 18px', borderRadius: 14, border: `1.5px solid ${colors.border}`, background: colors.surface }}>
      {children}
      <div style={{ marginTop: 14, paddingTop: 12, display: 'flex', alignItems: 'center', gap: 12, borderTop: `1.5px solid ${colors.border}`, fontSize: 15 }}>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, height: 28, padding: '0 10px', borderRadius: 6, border: `1.5px solid ${colors.borderStrong}`, color: colors.text2, fontWeight: 500 }}>
          <Database size={15} strokeWidth={2.2} />
          {t.fromData}
        </span>
        <span style={{ color: colors.text3 }}>
          {t.sources}: <span style={{ color: colors.text2, textDecoration: 'underline', textDecorationColor: colors.borderStrong, textUnderlineOffset: 3 }}>{source}</span>
        </span>
      </div>
    </div>
  )
}

function Thinking({ frame }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '14px 18px', borderRadius: 14, border: `1.5px solid ${colors.border}`, background: colors.surface, fontFamily: sans, fontSize: 18, color: colors.text2 }}>
      <span style={{ display: 'flex', gap: 6 }}>
        {[0, 1, 2].map((i) => (
          <span key={i} style={{ width: 9, height: 9, borderRadius: 5, background: colors.brand, opacity: 0.35 + 0.65 * (0.5 + 0.5 * Math.sin((frame - i * 5) / 4)) }} />
        ))}
      </span>
      {copy.assistant.thinking}
    </div>
  )
}

/** queued → sent → executed → verified (frontend/src/features/control/CommandTimeline.jsx). */
function Timeline({ frame }) {
  const t = copy.assistant
  const done = T.steps.filter((at) => frame >= at).length
  return (
    <div style={{ marginTop: 14, padding: '14px 16px', borderRadius: 10, border: `1.5px solid ${colors.border}`, background: 'rgba(27, 36, 32, 0.6)' }}>
      <div style={{ marginBottom: 12, fontSize: 16, fontWeight: 500, color: colors.text2 }}>{t.sent}</div>
      {t.steps.map((step, i) => {
        const finished = i < done
        const active = i === done
        const Icon = finished ? CircleCheck : active ? LoaderCircle : Circle
        return (
          <div key={step} style={{ position: 'relative', display: 'flex', gap: 12, paddingBottom: i < t.steps.length - 1 ? 12 : 0 }}>
            {i < t.steps.length - 1 && <span style={{ position: 'absolute', left: 10, top: 27, bottom: 3, width: 1.5, background: finished ? colors.good : colors.border }} />}
            <Icon
              size={21}
              strokeWidth={2.2}
              color={finished ? colors.good : active ? colors.text3 : colors.borderStrong}
              style={{ flexShrink: 0, marginTop: 2, transform: active ? `rotate(${frame * 12}deg)` : undefined }}
            />
            <div style={{ flex: 1, display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 12, fontSize: 16.5 }}>
              <span style={{ color: finished ? colors.text : active ? colors.text2 : colors.text3 }}>{finished ? step : t.waiting[i]}</span>
              {finished && <span style={{ fontSize: 14, color: colors.text3, fontVariantNumeric: 'tabular-nums' }}>{t.times[i]}</span>}
            </div>
          </div>
        )
      })}
    </div>
  )
}

/** The Turn Off proposal: Yes / No until "Yes" is pressed, then the command's progress. */
function TurnOffAnswer({ frame, measure = false }) {
  const t = copy.assistant
  const busy = frame >= T.confirm + 2
  const press = interpolate(frame, [T.confirm, T.confirm + 3, T.confirm + 8], [1, 0.95, 1], clamp)
  const button = { display: 'inline-flex', alignItems: 'center', gap: 8, height: 44, boxSizing: 'border-box', padding: '0 18px', borderRadius: 10, fontSize: 18 }
  return (
    <AssistantCard source={t.source2}>
      <Answer lines={t.answer2} />
      {frame < T.steps[0] ? (
        <div style={{ marginTop: 14, display: 'flex', gap: 10 }}>
          <span data-measure={measure ? 'yes' : undefined} style={{ ...button, background: colors.critical, color: '#ffffff', fontWeight: 600, transform: `scale(${press})` }}>
            {busy ? <LoaderCircle size={18} strokeWidth={2.4} style={{ transform: `rotate(${frame * 14}deg)` }} /> : <Power size={18} strokeWidth={2.4} />}
            {t.yes}
          </span>
          <span style={{ ...button, border: `1.5px solid ${colors.borderStrong}`, color: colors.text, fontWeight: 500, opacity: busy ? 0.55 : 1 }}>{t.no}</span>
        </div>
      ) : (
        <Timeline frame={frame} />
      )}
    </AssistantCard>
  )
}

function Header() {
  const t = copy.assistant
  return (
    <div style={{ height: PANEL.header, boxSizing: 'border-box', display: 'flex', alignItems: 'center', gap: 14, padding: '0 14px 0 20px', borderBottom: `1.5px solid ${colors.border}` }}>
      <span style={{ width: 46, height: 46, borderRadius: 10, display: 'grid', placeItems: 'center', background: colors.brandSubtle, color: colors.brand }}>
        <MessageSquareText size={26} strokeWidth={2} />
      </span>
      <div style={{ flex: 1, fontFamily: sans }}>
        <div style={{ fontSize: 22, fontWeight: 600, color: colors.text }}>{t.panelTitle}</div>
        <div style={{ fontSize: 16, color: colors.text3 }}>{t.panelSubtitle}</div>
      </div>
      {[History, SquarePen, X].map((Icon, i) => (
        <span key={i} style={{ width: 40, height: 40, display: 'grid', placeItems: 'center', color: colors.text3 }}>
          <Icon size={22} strokeWidth={2} />
        </span>
      ))}
    </div>
  )
}

function Composer({ frame }) {
  const t = copy.assistant
  const typed = (text, [start, end]) => text.slice(0, Math.round(interpolate(frame, [start, end], [0, text.length], clamp)))
  const draft = frame < T.send1 ? typed(t.question1, T.type1) : frame < T.send2 ? typed(t.question2, T.type2) : ''
  const typing = (frame >= T.type1[0] && frame < T.send1) || (frame >= T.type2[0] && frame < T.send2)
  const caret = typing || frame % 30 < 15
  const busy = (frame >= T.send1 && frame < T.answer1) || (frame >= T.send2 && frame < T.answer2)
  return (
    <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, height: PANEL.composer, boxSizing: 'border-box', padding: '14px 18px 0', borderTop: `1.5px solid ${colors.border}`, fontFamily: sans }}>
      <div style={{ height: 60, boxSizing: 'border-box', display: 'flex', alignItems: 'center', gap: 10, padding: '0 8px 0 16px', borderRadius: 12, border: `1.5px solid ${colors.brand}`, background: colors.surface }}>
        <div style={{ flex: 1, display: 'flex', alignItems: 'center', fontSize: 20, whiteSpace: 'nowrap', overflow: 'hidden', color: colors.text }}>
          {draft}
          <span style={{ width: 2, height: 24, margin: '0 1px', background: colors.text, opacity: caret ? 1 : 0 }} />
          {draft === '' && (
            <span style={{ color: colors.text3 }}>
              <Rich>{t.placeholder}</Rich>
            </span>
          )}
        </div>
        <span style={{ width: 44, height: 44, borderRadius: 10, display: 'grid', placeItems: 'center', background: colors.brand, color: colors.onBrand, opacity: draft && !busy ? 1 : 0.4 }}>
          <ArrowUp size={22} strokeWidth={2.4} />
        </span>
      </div>
      <div style={{ marginTop: 10, fontSize: 14, lineHeight: 1.4, color: colors.text3 }}>{t.footer}</div>
    </div>
  )
}

/** Measures the hidden copy of every message once, after the fonts load, so the conversation can scroll smoothly. */
function useMessageSizes(sheet) {
  const [sizes, setSizes] = useState(null)
  const [handle] = useState(() => delayRender('Measuring the conversation'))
  useLayoutEffect(() => {
    let done = false
    const finish = () => {
      if (done) return
      done = true
      continueRender(handle)
    }
    document.fonts.ready.then(() => {
      if (done) return
      const get = (key) => sheet.current.querySelector(`[data-measure="${key}"]`)
      const yes = get('yes')
      setSizes({
        ...Object.fromEntries(['q1', 'think', 'a1', 'q2', 'a2', 'a2done'].map((key) => [key, get(key).offsetHeight])),
        yes: { x: yes.offsetLeft + yes.offsetWidth / 2, y: yes.offsetTop + yes.offsetHeight / 2 },
      })
      finish()
    })
    return finish
  }, [handle, sheet])
  return sizes
}

export function Assistant() {
  const frame = useCurrentFrame()
  const t = copy.assistant
  const sheet = useRef(null)
  const sizes = useMessageSizes(sheet)

  const grow = (at, length = 9) => interpolate(frame, [at, at + length], [0, 1], { ...clamp, easing: Easing.out(Easing.cubic) })
  const open = interpolate(frame, [T.ask + 2, T.ask + 14], [0, 1], { ...clamp, easing: Easing.out(Easing.cubic) })
  const pressed = interpolate(frame, [T.ask, T.ask + 4], [0, 1], clamp)

  // The conversation sticks to the bottom: each message grows in and pushes the earlier ones up.
  const messages = sizes
    ? [
        { key: 'q1', height: sizes.q1, show: grow(T.send1), node: <UserBubble text={t.question1} /> },
        { key: 'think1', height: sizes.think, show: grow(T.send1 + 4) * (1 - grow(T.answer1, 6)), node: <Thinking frame={frame} /> },
        {
          key: 'a1',
          height: sizes.a1,
          show: grow(T.answer1),
          node: (
            <AssistantCard source={t.source1}>
              <Answer lines={t.answer1} />
            </AssistantCard>
          ),
        },
        { key: 'q2', height: sizes.q2, show: grow(T.send2), node: <UserBubble text={t.question2} /> },
        { key: 'think2', height: sizes.think, show: grow(T.send2 + 4) * (1 - grow(T.answer2, 6)), node: <Thinking frame={frame} /> },
        { key: 'a2', height: sizes.a2 + (sizes.a2done - sizes.a2) * grow(T.steps[0], 10), show: grow(T.answer2), node: <TurnOffAnswer frame={frame} /> },
      ]
    : []
  let bottom = BODY.h - BODY.pad
  const placed = []
  for (const m of [...messages].reverse()) {
    if (m.show <= 0.001) continue
    const height = m.height * m.show
    bottom -= height
    placed.push({ ...m, top: bottom, height })
    bottom -= BODY.gap * m.show
  }

  // The pointer, on screen: Ask EnergyFlow, the send button, then "Yes, turn it off".
  const at = (x, y) => ({ x: ORIGIN.x + x, y: ORIGIN.y + y })
  const ask = at(ASK_BUTTON.x + ASK_BUTTON.w / 2 + 8, ASK_BUTTON.y + ASK_BUTTON.h / 2 + 6)
  const send = at(SEND.x + 4, SEND.y + 4)
  const yes = sizes ? at(PANEL.x + BODY.pad + sizes.yes.x + 10, BODY.top + BODY.h - BODY.pad - sizes.a2 + sizes.yes.y + 6) : send
  const rest = { x: 1850, y: 980 }
  const pointer = [
    { frame: 8, x: 1900, y: 1120 },
    { frame: T.ask - 4, ...ask },
    { frame: T.ask + 10, ...ask },
    { frame: T.type1[0] + 10, ...rest },
    { frame: T.send1 - 30, ...rest },
    { frame: T.send1 - 4, ...send },
    { frame: T.send1 + 10, ...send },
    { frame: T.answer1 + 10, ...rest },
    { frame: T.confirm - 30, ...rest },
    { frame: T.confirm - 4, ...yes },
    { frame: T.confirm + 16, ...yes },
    { frame: T.confirm + 44, x: 1900, y: 1120, easing: Easing.in(Easing.cubic) },
  ]

  return (
    <AbsoluteFill>
      <ScreenText index={4} count={4} eyebrow={t.eyebrow} title={t.title} body={t.body} />
      <AppWindow>
        <Img src={staticFile('shots/overview.webp')} style={{ position: 'absolute', left: 0, top: 0, width: WIN.w, height: WIN.h }} />
        <div style={{ position: 'absolute', left: 0, top: PANEL.top, right: 0, bottom: 0, background: `rgba(7, 16, 13, ${0.4 * open})` }} />
        <div
          style={{
            position: 'absolute',
            left: ASK_BUTTON.x - 4,
            top: ASK_BUTTON.y - 4,
            width: ASK_BUTTON.w + 8,
            height: ASK_BUTTON.h + 8,
            boxSizing: 'border-box',
            borderRadius: 8,
            border: `2px solid ${colors.glow}`,
            boxShadow: '0 0 18px rgba(97, 214, 154, 0.5)',
            opacity: pressed,
          }}
        />

        <div
          style={{
            position: 'absolute',
            left: PANEL.x,
            top: PANEL.top,
            width: PANEL.w,
            bottom: 0,
            background: colors.surface,
            borderLeft: `1.5px solid ${colors.border}`,
            boxShadow: '-24px 0 60px rgba(0, 0, 0, 0.45)',
            opacity: open,
            transform: `translateX(${(1 - open) * 120}px)`,
          }}
        >
          <Header />
          <div
            style={{
              position: 'absolute',
              left: 0,
              top: PANEL.header,
              width: PANEL.w,
              height: BODY.h,
              overflow: 'hidden',
              maskImage: 'linear-gradient(to bottom, transparent 0, #000 36px)',
            }}
          >
            {placed.map((m) => (
              <div key={m.key} style={{ position: 'absolute', left: BODY.pad, top: m.top, width: BODY.w, height: m.height, overflow: 'hidden', opacity: Math.min(1, m.show * 1.5) }}>
                {m.node}
              </div>
            ))}
          </div>
          <Composer frame={frame} />
        </div>

        {/* A hidden copy of each message, measured once. */}
        <div ref={sheet} aria-hidden="true" style={{ position: 'absolute', left: -5000, top: 0, width: BODY.w, visibility: 'hidden' }}>
          <div data-measure="q1">
            <UserBubble text={t.question1} />
          </div>
          <div data-measure="think">
            <Thinking frame={0} />
          </div>
          <div data-measure="a1">
            <AssistantCard source={t.source1}>
              <Answer lines={t.answer1} />
            </AssistantCard>
          </div>
          <div data-measure="q2">
            <UserBubble text={t.question2} />
          </div>
          <div data-measure="a2" style={{ position: 'relative' }}>
            <TurnOffAnswer frame={0} measure />
          </div>
          <div data-measure="a2done">
            <TurnOffAnswer frame={T.steps[0]} />
          </div>
        </div>
      </AppWindow>

      <Cursor path={pointer} clicks={[T.ask, T.send1, T.confirm]} />
      <Sfx name="whoosh" at={T.ask + 2} volume={0.3} />
      <Sfx name="typing" at={T.type1[0]} duration={T.type1[1] - T.type1[0]} volume={1} />
      <Sfx name="message" at={T.answer1} volume={0.5} />
      <Sfx name="typing" at={T.type2[0]} duration={T.type2[1] - T.type2[0]} volume={1} />
      <Sfx name="enter" at={T.send2} volume={0.6} />
      <Sfx name="message" at={T.answer2} volume={0.5} />
      {T.steps.slice(0, -1).map((step) => (
        <Sfx key={step} name="tick" at={step} volume={0.45} />
      ))}
      <Sfx name="success" at={T.steps[T.steps.length - 1]} volume={0.6} />
    </AbsoluteFill>
  )
}
