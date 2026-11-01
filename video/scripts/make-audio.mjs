// The video's music and sound effects, synthesised from code: no samples, nothing to license.
// `npm run audio` writes them to public/audio/*.wav (`dev` and `render` run it first). Every run
// gives the same files.
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const SR = 44100
const TAU = 2 * Math.PI
const OUT = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'audio')

// ------------------------------------------------------------------ building blocks

/** Seeded white noise in -1…1 (mulberry32), so every run sounds the same. */
function noise(seed) {
  let a = seed >>> 0
  return () => {
    a = (a + 0x6d2b79f5) >>> 0
    let t = Math.imul(a ^ (a >>> 15), 1 | a)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return (((t ^ (t >>> 14)) >>> 0) / 4294967296) * 2 - 1
  }
}

const hz = (note) => 440 * 2 ** ((note - 69) / 12)
const len = (seconds) => Math.max(1, Math.round(seconds * SR))
const mono = (seconds) => new Float32Array(len(seconds))
const stereo = (seconds) => ({ L: new Float32Array(len(seconds)), R: new Float32Array(len(seconds)) })
const panGains = (pan) => [Math.cos(((pan + 1) * Math.PI) / 4), Math.sin(((pan + 1) * Math.PI) / 4)]

/** RBJ biquad: lowpass, highpass or bandpass; `set` retunes it on the fly. */
class Filter {
  constructor(type, freq, q = 0.707) {
    this.type = type
    this.x1 = this.x2 = this.y1 = this.y2 = 0
    this.set(freq, q)
  }

  set(freq, q = this.q) {
    this.q = q
    const w = (TAU * Math.min(Math.max(freq, 10), SR * 0.45)) / SR
    const cos = Math.cos(w)
    const alpha = Math.sin(w) / (2 * q)
    const a0 = 1 + alpha
    const [b0, b1, b2] =
      this.type === 'lowpass' ? [(1 - cos) / 2, 1 - cos, (1 - cos) / 2] : this.type === 'highpass' ? [(1 + cos) / 2, -(1 + cos), (1 + cos) / 2] : [alpha, 0, -alpha]
    this.b0 = b0 / a0
    this.b1 = b1 / a0
    this.b2 = b2 / a0
    this.a1 = (-2 * cos) / a0
    this.a2 = (1 - alpha) / a0
  }

  run(x) {
    const y = this.b0 * x + this.b1 * this.x1 + this.b2 * this.x2 - this.a1 * this.y1 - this.a2 * this.y2
    this.x2 = this.x1
    this.x1 = x
    this.y2 = this.y1
    this.y1 = y
    return y
  }
}

/** One sample of a band-limited sawtooth (polyBLEP), for a phase in 0…1 advancing by dt. */
function saw(phase, dt) {
  let v = 2 * phase - 1
  if (phase < dt) {
    const t = phase / dt
    v -= t + t - t * t - 1
  } else if (phase > 1 - dt) {
    const t = (phase - 1) / dt
    v -= t * t + t + t + 1
  }
  return v
}

/** A short fade at the end, so nothing stops with a click. */
function tail(sound, seconds = 0.01) {
  const k = Math.min(sound.length, len(seconds))
  for (let i = 0; i < k; i++) sound[sound.length - 1 - i] *= i / k
  return sound
}

/** Adds a mono sound into a stereo track at `at` seconds. */
function place(track, sound, at, gain = 1, pan = 0) {
  const offset = Math.round(at * SR)
  const [l, r] = panGains(pan)
  for (let i = 0; i < sound.length; i++) {
    const j = offset + i
    if (j < 0 || j >= track.L.length) continue
    track.L[j] += sound[i] * gain * l
    track.R[j] += sound[i] * gain * r
  }
}

/** Adds a stereo track into another at `at` seconds. */
function mix(track, other, at = 0, gain = 1) {
  const offset = Math.round(at * SR)
  for (let i = 0; i < other.L.length; i++) {
    const j = offset + i
    if (j < 0 || j >= track.L.length) continue
    track.L[j] += other.L[i] * gain
    track.R[j] += other.R[i] * gain
  }
}

/** Freeverb: eight damped combs and four allpasses a side. Returns only the reverb. */
function reverb(track, room = 0.84, damp = 0.3) {
  const n = track.L.length
  const out = { L: new Float32Array(n), R: new Float32Array(n) }
  for (const [side, spread] of [['L', 0], ['R', 23]]) {
    const combs = [1116, 1188, 1277, 1356, 1422, 1491, 1557, 1617].map((size) => ({ buf: new Float32Array(size + spread), i: 0, low: 0 }))
    const passes = [556, 441, 341, 225].map((size) => ({ buf: new Float32Array(size + spread), i: 0 }))
    const o = out[side]
    for (let k = 0; k < n; k++) {
      const input = (track.L[k] + track.R[k]) * 0.015
      let s = 0
      for (const c of combs) {
        const y = c.buf[c.i]
        c.low = y * (1 - damp) + c.low * damp
        c.buf[c.i] = input + c.low * room
        if (++c.i === c.buf.length) c.i = 0
        s += y
      }
      for (const a of passes) {
        const b = a.buf[a.i]
        a.buf[a.i] = s + b * 0.5
        if (++a.i === a.buf.length) a.i = 0
        s = b - s
      }
      o[k] = s * 3
    }
  }
  return out
}

/** A ping-pong echo: left, then right, then left… each repeat softer and darker. */
function pingPong(track, seconds, feedback) {
  const d = len(seconds)
  const n = track.L.length
  const out = { L: new Float32Array(n), R: new Float32Array(n) }
  const left = new Float32Array(d)
  const right = new Float32Array(d)
  const toneL = new Filter('lowpass', 2800)
  const toneR = new Filter('lowpass', 2800)
  let i = 0
  for (let k = 0; k < n; k++) {
    const yl = left[i]
    const yr = right[i]
    left[i] = toneL.run((track.L[k] + track.R[k]) * 0.5 + yr * feedback)
    right[i] = toneR.run(yl * feedback)
    out.L[k] = yl
    out.R[k] = yr
    if (++i === d) i = 0
  }
  return out
}

function peak(track) {
  let max = 0
  for (const ch of [track.L, track.R]) for (let i = 0; i < ch.length; i++) max = Math.max(max, Math.abs(ch[i]))
  return max
}

function scale(track, gain) {
  for (const ch of [track.L, track.R]) for (let i = 0; i < ch.length; i++) ch[i] *= gain
  return track
}

/** Scales a bus so its loudness while it plays (RMS of its non-silent samples) is `db` dBFS. */
function level(track, db) {
  let sum = 0
  let count = 0
  for (const ch of [track.L, track.R]) {
    for (let i = 0; i < ch.length; i++) {
      if (Math.abs(ch[i]) < 1e-4) continue
      sum += ch[i] * ch[i]
      count++
    }
  }
  return count === 0 ? track : scale(track, 10 ** (db / 20) / Math.sqrt(sum / count))
}

/** A mono sound in stereo, with some room around it. */
function roomy(sound, amount = 0.25, ring = 1.2) {
  const track = stereo(sound.length / SR + ring)
  place(track, sound, 0)
  if (amount > 0) mix(track, reverb(track, 0.8, 0.4), 0, amount)
  return track
}

function save(name, track, top = 0.8) {
  scale(track, top / (peak(track) || 1))
  const n = track.L.length
  const data = Buffer.alloc(44 + n * 4)
  data.write('RIFF', 0)
  data.writeUInt32LE(36 + n * 4, 4)
  data.write('WAVE', 8)
  data.write('fmt ', 12)
  data.writeUInt32LE(16, 16)
  data.writeUInt16LE(1, 20)
  data.writeUInt16LE(2, 22)
  data.writeUInt32LE(SR, 24)
  data.writeUInt32LE(SR * 4, 28)
  data.writeUInt16LE(4, 32)
  data.writeUInt16LE(16, 34)
  data.write('data', 36)
  data.writeUInt32LE(n * 4, 40)
  for (let i = 0; i < n; i++) {
    data.writeInt16LE(Math.round(Math.max(-1, Math.min(1, track.L[i])) * 32767), 44 + i * 4)
    data.writeInt16LE(Math.round(Math.max(-1, Math.min(1, track.R[i])) * 32767), 46 + i * 4)
  }
  writeFileSync(join(OUT, `${name}.wav`), data)
  console.log(`audio/${name}.wav  ${(n / SR).toFixed(2)} s`)
}

// ------------------------------------------------------------------ instruments

function kick() {
  const out = mono(0.5)
  const rnd = noise(1)
  let phase = 0
  for (let i = 0; i < out.length; i++) {
    const t = i / SR
    phase += (46 + 115 * Math.exp(-t * 30)) / SR
    out[i] = Math.tanh(1.8 * Math.sin(TAU * phase) * Math.exp(-t * 6)) * 0.95 + rnd() * Math.exp(-t * 700) * 0.25
  }
  return tail(out)
}

function hat(open, seed) {
  const out = mono(open ? 0.45 : 0.09)
  const rnd = noise(seed)
  const high = new Filter('highpass', 7000, 0.7)
  const ring = new Filter('bandpass', 9500, 1.2)
  for (let i = 0; i < out.length; i++) {
    const x = rnd()
    out[i] = (0.7 * high.run(x) + 0.5 * ring.run(x)) * Math.exp((-i / SR) * (open ? 8 : 55))
  }
  return tail(out)
}

function clap(seed) {
  const out = mono(0.45)
  const rnd = noise(seed)
  const band = new Filter('bandpass', 1250, 0.8)
  const high = new Filter('highpass', 600)
  for (let i = 0; i < out.length; i++) {
    const t = i / SR
    let env = 0
    for (const start of [0, 0.012, 0.024]) if (t >= start) env = Math.max(env, Math.exp(-(t - start) * 160))
    if (t >= 0.024) env = Math.max(env, 0.55 * Math.exp(-(t - 0.024) * 13))
    out[i] = high.run(band.run(rnd())) * env * 2.5
  }
  return tail(out)
}

function crash(seed) {
  const out = mono(2.2)
  const rnd = noise(seed)
  const high = new Filter('highpass', 4500, 0.6)
  const shine = new Filter('bandpass', 7500, 0.5)
  for (let i = 0; i < out.length; i++) {
    const t = i / SR
    const x = rnd()
    out[i] = (0.6 * high.run(x) + 0.5 * shine.run(x)) * Math.exp(-t * 2.4) * Math.min(1, t / 0.002)
  }
  return tail(out, 0.05)
}

function bassNote(freq, length, vel = 1) {
  const out = mono(length + 0.1)
  const tone = new Filter('lowpass', 380, 1)
  let phase = 0
  for (let i = 0; i < out.length; i++) {
    const t = i / SR
    const dt = freq / SR
    phase = (phase + dt) % 1
    const env = Math.min(1, t / 0.008) * (t < length ? 0.8 + 0.2 * Math.exp(-t * 6) : Math.exp(-(t - length) * 40))
    out[i] = vel * env * (0.75 * Math.sin(TAU * phase) + 0.4 * tone.run(saw(phase, dt)))
  }
  return tail(out)
}

/** A warm chord: three slightly detuned saws a note, spread left to right, through a slowly moving lowpass. */
function padChord(notes, length, seed) {
  const attack = 0.9
  const release = 1.8
  const out = stereo(length + release)
  const rnd = noise(seed)
  notes.forEach((note, k) => {
    const voices = [
      [-0.08, -0.6],
      [0, 0],
      [0.08, 0.6],
    ].map(([detune, pan]) => ({ f: hz(note + detune), phase: (rnd() + 1) / 2, gains: panGains(pan), filter: new Filter('lowpass', 1200, 0.7) }))
    for (let i = 0; i < out.L.length; i++) {
      const t = i / SR
      const env = t < attack ? Math.sin(((t / attack) * Math.PI) / 2) : t < length ? 1 : Math.max(0, 1 - (t - length) / release) ** 2
      const cutoff = 900 + 700 * env + 250 * Math.sin(TAU * 0.15 * t + k)
      for (const v of voices) {
        const dt = v.f / SR
        v.phase = (v.phase + dt) % 1
        if (i % 64 === 0) v.filter.set(cutoff, 0.7)
        const s = v.filter.run(saw(v.phase, dt)) * env * 0.33
        out.L[i] += s * v.gains[0]
        out.R[i] += s * v.gains[1]
      }
    }
  })
  return out
}

/** A soft synth pluck: two saws through a lowpass that closes fast. */
function pluck(freq, vel = 1) {
  const out = mono(0.7)
  const tone = new Filter('lowpass', 3000, 1.4)
  let a = 0
  let b = 0.25
  for (let i = 0; i < out.length; i++) {
    const t = i / SR
    const dt = freq / SR
    a = (a + dt) % 1
    b = (b + dt * 1.004) % 1
    if (i % 32 === 0) tone.set(350 + 3800 * Math.exp(-t * 16), 1.4)
    out[i] = vel * tone.run(0.5 * saw(a, dt) + 0.5 * saw(b, dt * 1.004)) * Math.exp(-t * 6.5) * Math.min(1, t / 0.003)
  }
  return tail(out)
}

/** Filtered noise and a tone, both rising, for `length` seconds. */
function riser(length, seed, top = 7000) {
  const out = mono(length)
  const rnd = noise(seed)
  const band = new Filter('bandpass', 300, 1.2)
  let phase = 0
  for (let i = 0; i < out.length; i++) {
    const x = i / out.length
    if (i % 32 === 0) band.set(250 + top * x * x, 1.2)
    phase += (180 + 700 * x * x) / SR
    out[i] = x * x * (0.9 * band.run(rnd()) + 0.12 * Math.sin(TAU * phase))
  }
  return tail(out, 0.02)
}

/** An FM bell: bright at first, mellowing as it rings. */
function bell(freq, length, ratio = 2, index = 2, decay = 6) {
  const out = mono(length)
  for (let i = 0; i < out.length; i++) {
    const t = i / SR
    const mod = Math.sin(TAU * freq * ratio * t) * index * Math.exp(-t * decay * 1.5)
    out[i] = Math.sin(TAU * freq * t + mod) * Math.exp(-t * decay) * Math.min(1, t / 0.002)
  }
  return tail(out)
}

/** Notes at given times (seconds), as one mono sound. */
function sequence(parts, length) {
  const out = mono(length)
  for (const [at, sound, gain = 1] of parts) {
    const offset = len(at)
    for (let i = 0; i < sound.length && offset + i < out.length; i++) out[offset + i] += sound[i] * gain
  }
  return out
}

// ------------------------------------------------------------------ music

// 120 BPM, a bar every 2 s, built round the cut (seconds): intro 0–4, problem and Kosovo 4–16, the
// story and screens 16–42 with the full groove, the chat 42–58 without drums so its clicks and keys
// are heard, features 58–68 with the groove back, and a held C chord under the ending.
function music() {
  const BEAT = 0.5
  const BAR = 2
  const LENGTH = 76
  const CHORDS = [
    { bass: 33, pad: [57, 60, 64, 67, 71] }, // Am9
    { bass: 29, pad: [53, 57, 60, 64, 67] }, // Fmaj9
    { bass: 36, pad: [55, 60, 64, 67, 74] }, // Cadd9
    { bass: 31, pad: [55, 59, 62, 67, 69] }, // Gadd9
  ]
  const END = { bass: 36, pad: [48, 55, 60, 64, 67, 74] } // C
  const part = (bar) => (bar < 2 ? 'intro' : bar < 8 ? 'verse' : bar < 21 ? 'main' : bar < 29 ? 'break' : bar < 34 ? 'main' : 'end')
  const ARP = [0, 2, 4, 1, 3, 2, 4, 3]

  const pads = stereo(LENGTH)
  const low = stereo(LENGTH)
  const arp = stereo(LENGTH)
  const drums = stereo(LENGTH)
  const fx = stereo(LENGTH)
  const kicks = []
  const kickSound = kick()
  const clapSound = clap(3)
  const closed = [hat(false, 4), hat(false, 5), hat(false, 6)]
  const open = hat(true, 7)

  for (let bar = 0; bar <= 34; bar++) {
    const at = bar * BAR
    const p = part(bar)
    const chord = p === 'end' ? END : CHORDS[bar % 4]
    const hit = (sound, beat, gain, pan = 0) => place(drums, sound, at + beat * BEAT, gain, pan)

    mix(pads, padChord(chord.pad, p === 'end' ? LENGTH - at - 2.2 : BAR + 0.2, bar + 1), at)

    if (p === 'verse') for (const beat of [0, 2]) place(low, bassNote(hz(chord.bass), BEAT * 1.8, 0.85), at + beat * BEAT)
    if (p === 'main') {
      for (const [step, length, up] of [[0, 2.5, 0], [3, 1, 0], [6, 1.5, 0], [8, 2.5, 0], [11, 1, 0], [14, 1.5, 7]]) {
        place(low, bassNote(hz(chord.bass + up), (length * BEAT * 0.9) / 4, step % 8 === 0 ? 1 : 0.8), at + (step * BEAT) / 4)
      }
    }
    if (p === 'break') place(low, bassNote(hz(chord.bass), BAR * 0.95, 0.6), at)
    if (p === 'end') place(low, bassNote(hz(END.bass), 5, 0.8), at)

    if (p !== 'end' && bar > 0) {
      const steps = p === 'main' ? 16 : 8
      const notes = chord.pad.map((n) => n + 12)
      for (let s = 0; s < steps; s++) {
        const sixteenth = steps === 16 ? s : s * 2
        const vel = (sixteenth % 4 === 0 ? 1 : 0.7) * { intro: 0.45, verse: 0.55, main: 0.6, break: 0.8 }[p]
        place(arp, pluck(hz(notes[ARP[s % ARP.length] % notes.length]), vel), at + (sixteenth * BEAT) / 4, 1, ((s % 4) - 1.5) * 0.25)
      }
    }

    if (p === 'verse') {
      for (const beat of [0, 2]) {
        hit(kickSound, beat, 0.8)
        kicks.push(at + beat * BEAT)
      }
      for (let e = 0; e < 4; e++) hit(closed[e % 3], e + 0.5, 0.35, 0.2)
    }
    if (p === 'main') {
      for (let beat = 0; beat < 4; beat++) {
        hit(kickSound, beat, 0.95)
        kicks.push(at + beat * BEAT)
      }
      for (const beat of [1, 3]) hit(clapSound, beat, 0.5, -0.1)
      for (let s = 1; s < 16; s++) {
        if (s % 4 === 0) continue
        const offbeat = s % 2 === 0
        hit(offbeat && bar >= 29 ? open : closed[s % 3], s / 4, offbeat ? 0.55 : 0.16, 0.25)
      }
    }
    if (p === 'break') for (let e = 0; e < 4; e++) hit(closed[e % 3], e + 0.5, 0.2, 0.25)
    if (bar === 8 || bar === 29 || bar === 34) place(fx, crash(8 + bar), at, 0.5, 0.2)
  }
  place(fx, riser(2, 9), 14, 0.45) // into the groove at 16 s
  place(fx, riser(2, 10), 56, 0.45) // into the features at 58 s

  // The pads and bass dip under each kick.
  const duck = new Float32Array(len(LENGTH)).fill(1)
  for (const k of kicks) {
    const start = len(k)
    for (let i = 0; i < len(0.35) && start + i < duck.length; i++) duck[start + i] = Math.min(duck[start + i], 1 - 0.45 * Math.exp((-i / SR) * 9))
  }
  for (const bus of [pads, low]) for (const ch of [bus.L, bus.R]) for (let i = 0; i < ch.length; i++) ch[i] *= duck[i]

  // Lean on the mids: laptop and projector speakers barely play the lowest notes.
  level(pads, -19)
  level(low, -21)
  level(arp, -25)
  level(drums, -17)
  level(fx, -26)
  const echo = level(pingPong(arp, 0.375, 0.38), -31)
  const send = stereo(LENGTH)
  mix(send, pads, 0, 0.5)
  mix(send, arp, 0, 0.8)
  mix(send, drums, 0, 0.1)
  const room = level(reverb(send, 0.86, 0.35), -25)

  const track = stereo(LENGTH)
  for (const bus of [pads, low, arp, drums, fx, echo, room]) mix(track, bus)

  // Master: clear the deepest rumble, round off the peaks, let the end fade.
  for (const ch of [track.L, track.R]) {
    const clean = new Filter('highpass', 28)
    for (let i = 0; i < ch.length; i++) ch[i] = clean.run(ch[i])
  }
  scale(track, 1 / peak(track))
  for (const ch of [track.L, track.R]) {
    for (let i = 0; i < ch.length; i++) ch[i] = Math.tanh(1.6 * ch[i]) / Math.tanh(1.6)
    const fadeFrom = len(LENGTH - 1.5)
    for (let i = fadeFrom; i < ch.length; i++) ch[i] *= 1 - (i - fadeFrom) / (ch.length - fadeFrom)
  }
  return track
}

// ------------------------------------------------------------------ sound effects

function mouseClick() {
  const out = mono(0.14)
  const rnd = noise(11)
  // A mid "thock" under the snap keeps the click audible over hi-hats.
  const press = [new Filter('bandpass', 4200, 2.2), new Filter('bandpass', 1300, 3)]
  const release = [new Filter('bandpass', 5200, 2.2), new Filter('bandpass', 1900, 3)]
  for (let i = 0; i < out.length; i++) {
    const t = i / SR
    const x = rnd()
    let s = press[0].run(x) * Math.exp(-t * 1100) * 1.6 + press[1].run(x) * Math.exp(-t * 300) * 1.9
    const r = t - 0.075 // the button coming back up
    if (r >= 0) s += release[0].run(x) * Math.exp(-r * 1300) * 0.9 + release[1].run(x) * Math.exp(-r * 450) * 0.6
    out[i] = s
  }
  return tail(out)
}

function keyTap(seed, body = 220, bright = 2600) {
  const out = mono(0.09)
  const rnd = noise(seed)
  const top = new Filter('bandpass', bright, 1.8)
  const thock = new Filter('bandpass', body * 3, 2)
  for (let i = 0; i < out.length; i++) {
    const t = i / SR
    const x = rnd()
    let s = top.run(x) * Math.exp(-t * 700) * 1.3 + thock.run(x) * Math.exp(-t * 260) * 0.8 + Math.sin(TAU * body * t) * Math.exp(-t * 120) * 0.25
    const r = t - 0.045 // the key coming back up
    if (r >= 0) s += top.run(x) * Math.exp(-r * 900) * 0.35
    out[i] = s
  }
  return tail(out)
}

function typing() {
  const track = stereo(3.2)
  const rnd = noise(77)
  let t = 0.02
  for (let k = 0; t < 3; k++) {
    const space = k % 6 === 5
    const tap = keyTap(100 + k, space ? 150 : 200 + 60 * Math.abs(rnd()), space ? 1900 : 2400 + 600 * rnd())
    place(track, tap, t, space ? 0.8 : 0.6 + 0.25 * Math.abs(rnd()), 0.15 * rnd())
    t += 0.045 + 0.03 * Math.abs(rnd()) + (space ? 0.03 : 0)
  }
  mix(track, reverb(track, 0.5, 0.6), 0, 0.08)
  return track
}

function pop() {
  const out = mono(0.18)
  let phase = 0
  for (let i = 0; i < out.length; i++) {
    const t = i / SR
    phase += (300 + 650 * (1 - Math.exp(-t * 45))) / SR
    out[i] = (Math.sin(TAU * phase) + 0.25 * Math.sin(2 * TAU * phase)) * Math.exp(-t * 26) * Math.min(1, t / 0.0015)
  }
  return tail(out)
}

function tick() {
  const out = mono(0.08)
  const rnd = noise(5)
  const high = new Filter('highpass', 3000)
  for (let i = 0; i < out.length; i++) {
    const t = i / SR
    out[i] = Math.sin(TAU * 2400 * t) * Math.exp(-t * 70) * 0.6 + high.run(rnd()) * Math.exp(-t * 900) * 0.5
  }
  return tail(out)
}

/** Air moving past, left to right: noise through a band that opens to `top` Hz and closes again. */
function whoosh(length, top, seed, peakAt = 0.55) {
  const track = stereo(length + 0.6)
  const rnd = noise(seed)
  const bands = [new Filter('bandpass', 400, 0.9), new Filter('bandpass', 500, 0.9)]
  const n = len(length)
  for (let i = 0; i < n; i++) {
    const x = i / n
    const shape = x < peakAt ? (x / peakAt) ** 2 : ((1 - x) / (1 - peakAt)) ** 1.5
    if (i % 32 === 0) {
      bands[0].set(250 + top * shape, 0.9)
      bands[1].set((250 + top * shape) * 1.25, 0.9)
    }
    const s = (bands[0].run(rnd()) + 0.6 * bands[1].run(rnd())) * shape
    const [l, r] = panGains(-0.7 + 1.4 * x)
    track.L[i] += s * l
    track.R[i] += s * r
  }
  mix(track, reverb(track, 0.7, 0.4), 0, 0.15)
  return track
}

function impact() {
  const out = mono(2.2)
  const rnd = noise(31)
  const thump = new Filter('lowpass', 180, 0.8)
  let phase = 0
  for (let i = 0; i < out.length; i++) {
    const t = i / SR
    phase += (36 + 50 * Math.exp(-t * 9)) / SR
    out[i] = Math.sin(TAU * phase) * Math.exp(-t * 2.2) * 0.9 + Math.sin(TAU * phase * 2.5) * Math.exp(-t * 12) * 0.4 + thump.run(rnd()) * Math.exp(-t * 14) * 1.2
  }
  const shine = bell(2093, 1.6, 2, 0.8, 3)
  for (let i = 0; i < shine.length; i++) out[i] += shine[i] * 0.12
  return tail(out, 0.05)
}

function hit() {
  const out = mono(0.8)
  const rnd = noise(41)
  const snap = new Filter('bandpass', 1800, 0.9)
  let phase = 0
  for (let i = 0; i < out.length; i++) {
    const t = i / SR
    phase += (65 + 90 * Math.exp(-t * 35)) / SR
    out[i] = Math.sin(TAU * phase) * Math.exp(-t * 11) + snap.run(rnd()) * Math.exp(-t * 45) * 1.4
  }
  const ring = bell(1046.5, 0.8, 2, 1.2, 6)
  for (let i = 0; i < ring.length; i++) out[i] += ring[i] * 0.25
  return tail(out)
}

const fading = (x, t) => Math.min(1, t / 0.01) * (1 - x) ** 1.4
const swelling = (x) => Math.sin(Math.PI * x)

/** A tone gliding from `from` to `to` Hz through a lowpass moving from `cutFrom` to `cutTo`; `shape` is its loudness over x = 0…1. */
function glide(length, from, to, cutFrom, cutTo, shape = fading) {
  const out = mono(length)
  const tone = new Filter('lowpass', cutFrom, 1.3)
  let a = 0
  let b = 0
  for (let i = 0; i < out.length; i++) {
    const x = i / out.length
    const f = from * (to / from) ** x
    a = (a + f / SR) % 1
    b = (b + (f * 2.005) / SR) % 1
    if (i % 32 === 0) tone.set(cutFrom * (cutTo / cutFrom) ** x, 1.3)
    out[i] = tone.run(0.6 * saw(a, f / SR) + 0.4 * saw(b, (f * 2.005) / SR)) * shape(x, i / SR)
  }
  return tail(out, 0.03)
}

function powerDown() {
  const relay = keyTap(901, 120, 1700)
  return sequence([
    [0, relay, 1.2],
    [0.01, glide(0.8, 230, 30, 1800, 100), 0.9],
  ], 0.9)
}

const SFX = {
  click: () => roomy(mouseClick(), 0.06, 0.3),
  tick: () => roomy(tick(), 0.12, 0.4),
  pop: () => roomy(pop(), 0.15, 0.5),
  typing,
  enter: () => roomy(keyTap(950, 140, 1800), 0.1, 0.4),
  whoosh: () => whoosh(0.75, 3200, 61),
  'whoosh-soft': () => whoosh(0.6, 1800, 62, 0.5),
  swish: () => whoosh(0.35, 4500, 63, 0.3),
  zoom: () => whoosh(0.9, 1600, 64, 0.75),
  riser: () => roomy(riser(1, 65, 6000), 0.2, 0.6),
  impact: () => roomy(impact(), 0.35, 1.5),
  hit: () => roomy(hit(), 0.25, 1),
  'sweep-up': () => {
    const up = sequence([
      [0, glide(1.6, 320, 640, 3000, 3000, swelling), 1],
      [0, glide(1.6, 480, 960, 3000, 3000, swelling), 0.6],
    ], 1.6)
    return roomy(up, 0.3, 1)
  },
  'power-up': () => roomy(glide(0.9, 70, 230, 250, 2600, swelling), 0.15, 0.6),
  'wind-down': () => roomy(glide(1.4, 180, 45, 1500, 150), 0.15, 0.6),
  'power-down': () => roomy(powerDown(), 0.15, 0.6),
  alert: () =>
    roomy(
      sequence([
        [0, bell(1318.5, 1.2, 2, 2.2, 4.5)],
        [0.16, bell(1046.5, 1.4, 2, 2.2, 4)],
      ], 1.6),
      0.3,
    ),
  success: () =>
    roomy(
      sequence(
        [523.25, 659.25, 784, 1046.5].map((f, i) => [i * 0.07, bell(f, i === 3 ? 1.6 : 0.9, 2, 1.5, i === 3 ? 3 : 5), i === 3 ? 1 : 0.8]),
        1.9,
      ),
      0.35,
    ),
  message: () =>
    roomy(
      sequence([
        [0, bell(1046.5, 0.7, 2, 1, 8)],
        [0.075, bell(1568, 0.7, 2, 0.9, 8)],
      ], 0.8),
      0.2,
    ),
  shimmer: () => {
    const rnd = noise(71)
    const notes = [1318.5, 1568, 1760, 2093, 2349.3, 2637, 3136]
    const parts = notes.map((f, i) => [i * 0.07 + 0.02 * Math.abs(rnd()), bell(f, 0.9, 2, 0.6, 7), 0.5])
    return roomy(sequence(parts, 1.4), 0.45, 1.2)
  },
  // One plink per feature tile: they land 5 frames (1/6 s) apart.
  cascade: () => {
    const notes = [523.25, 587.33, 659.25, 784, 880, 1046.5, 1174.7, 1318.5, 1568]
    return roomy(sequence(notes.map((f, i) => [i / 6, bell(f, 0.6, 3, 1.2, 9), 0.7]), 2.2), 0.25, 0.8)
  },
}

mkdirSync(OUT, { recursive: true })
for (const [name, make] of Object.entries(SFX)) save(name, make())
save('music', music(), 0.89)
