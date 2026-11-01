import { Html5Audio, Sequence, staticFile } from 'remotion'

/** A sound from public/audio (scripts/make-audio.mjs), starting `at` frames into the current scene. */
export function Sfx({ name, at, volume = 1, duration }) {
  return (
    <Sequence from={Math.round(at)} durationInFrames={duration} layout="none" name={`Sound: ${name}`}>
      <Html5Audio src={staticFile(`audio/${name}.wav`)} volume={volume} />
    </Sequence>
  )
}
