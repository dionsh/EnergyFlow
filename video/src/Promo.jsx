import { AbsoluteFill, Html5Audio, interpolate, staticFile, useVideoConfig } from 'remotion'
import { TransitionSeries, linearTiming, springTiming } from '@remotion/transitions'
import { fade } from '@remotion/transitions/fade'
import { slide } from '@remotion/transitions/slide'
import { Background } from './components/Background'
import { Sfx } from './components/Sfx'
import { Assistant } from './scenes/Assistant'
import { CarbonFact } from './scenes/CarbonFact'
import { Features } from './scenes/Features'
import { FlowStory, TURN_OFF } from './scenes/FlowStory'
import { Intro } from './scenes/Intro'
import { Outro } from './scenes/Outro'
import { Problem } from './scenes/Problem'
import { Showcase } from './scenes/Showcase'
import { clamp } from './theme'

export const FPS = 30

// Background music, from public/: the track scripts/make-audio.mjs composes for this cut. Another
// track (one you have the rights to) can go in public/ and be named here instead.
const MUSIC = 'audio/music.wav'
const MUSIC_VOLUME = 0.5

const FADE = { presentation: fade(), timing: linearTiming({ durationInFrames: 20 }), sound: 'whoosh-soft', volume: 0.3 }
const SLIDE = { presentation: slide({ direction: 'from-right' }), timing: springTiming({ config: { damping: 200 }, durationInFrames: 24 }), sound: 'whoosh', volume: 0.4 }

// The running order, with each scene's length in frames and the transition it enters with.
const SCENES = [
  { id: 'intro', frames: 150, render: () => <Intro /> },
  { id: 'problem', frames: 170, enter: FADE, render: () => <Problem /> },
  { id: 'carbon-fact', frames: 170, enter: FADE, render: () => <CarbonFact /> },
  { id: 'flow', frames: 450, enter: FADE, render: () => <FlowStory /> },
  { id: 'overview', frames: 165, enter: FADE, render: () => <Showcase index={1} count={4} shot="overview" /> },
  { id: 'waste', frames: 165, enter: SLIDE, render: () => <Showcase index={2} count={4} shot="waste" /> },
  { id: 'carbon', frames: 165, enter: SLIDE, render: () => <Showcase index={3} count={4} shot="carbon" /> },
  { id: 'assistant', frames: 470, enter: SLIDE, render: () => <Assistant /> },
  { id: 'features', frames: 285, enter: FADE, render: () => <Features /> },
  { id: 'outro', frames: 230, enter: FADE, render: () => <Outro /> },
]

// Where each scene starts: a transition overlaps the end of the scene before it.
const overlap = (scene) => (scene.enter ? scene.enter.timing.getDurationInFrames({ fps: FPS }) : 0)
const STARTS = SCENES.reduce((starts, scene, i) => [...starts, i === 0 ? 0 : starts[i - 1] + SCENES[i - 1].frames - overlap(scene)], [])
export const DURATION = STARTS.at(-1) + SCENES.at(-1).frames

// The music steps back while the assistant is typed into, and for the story's Turn off, so the keys,
// clicks and the machine powering down are heard.
const CHAT = SCENES.findIndex((scene) => scene.id === 'assistant')
const CHAT_FRAMES = [STARTS[CHAT], STARTS[CHAT] + SCENES[CHAT].frames]
const [CLICK, VERIFIED] = TURN_OFF.map((frame) => STARTS[SCENES.findIndex((scene) => scene.id === 'flow')] + frame)

export function Promo() {
  const { durationInFrames } = useVideoConfig()
  const musicVolume = (f) =>
    interpolate(f, [0, 15, durationInFrames - 60, durationInFrames], [0, MUSIC_VOLUME, MUSIC_VOLUME, 0], clamp) *
    interpolate(f, [CHAT_FRAMES[0], CHAT_FRAMES[0] + 20, CHAT_FRAMES[1] - 20, CHAT_FRAMES[1]], [1, 0.7, 0.7, 1], clamp) *
    interpolate(f, [CLICK - 8, CLICK - 2, VERIFIED + 30, VERIFIED + 50], [1, 0.6, 0.6, 1], clamp)
  return (
    <AbsoluteFill>
      <Background />
      <TransitionSeries>
        {SCENES.flatMap((scene) => [
          scene.enter && <TransitionSeries.Transition key={`${scene.id}-in`} presentation={scene.enter.presentation} timing={scene.enter.timing} />,
          <TransitionSeries.Sequence key={scene.id} durationInFrames={scene.frames}>
            {scene.render()}
          </TransitionSeries.Sequence>,
        ]).filter(Boolean)}
      </TransitionSeries>
      {SCENES.map((scene, i) => scene.enter && <Sfx key={scene.id} name={scene.enter.sound} at={STARTS[i]} volume={scene.enter.volume} />)}
      <Html5Audio src={staticFile(MUSIC)} volume={musicVolume} />
    </AbsoluteFill>
  )
}
