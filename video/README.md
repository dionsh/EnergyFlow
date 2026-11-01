# EnergyFlow presentation video

A 74-second video (1920×1080, 30 fps) made with [Remotion](https://www.remotion.dev): the scenes are React components, rendered to MP4.

```powershell
cd video
npm install
npm run dev      # Remotion Studio: preview with a timeline, in the browser
npm run render   # writes out/energyflow-promo.mp4
```

## Running order

| Time | Scene | File |
|---|---|---|
| 0:00 | Intro: energy flows into the logo (the web intro, `frontend/index.html`) | `src/scenes/Intro.jsx` |
| 0:04 | The problem: one bill a month, never which machine, when or why | `src/scenes/Problem.jsx` |
| 0:09 | Why it matters in Kosovo: 0.901 kg CO₂e per kWh, ≈ 4.3× the EU average | `src/scenes/CarbonFact.jsx` |
| 0:14 | The story: the shift ends, the compressor keeps running, EnergyFlow flags it, Turn off, verified by the meter | `src/scenes/FlowStory.jsx` |
| 0:29 | Overview, Waste & Alerts and Carbon & ESG screens of the demo company | `src/scenes/Showcase.jsx` |
| 0:43 | Ask EnergyFlow: a waste question answered from the data, then "Turn off the compressor", confirmed and verified | `src/scenes/Assistant.jsx` |
| 0:58 | Features | `src/scenes/Features.jsx` |
| 1:07 | Outro with the Demo Day QR code | `src/scenes/Outro.jsx` |

## Sound

The music and sound effects are synthesised by `scripts/make-audio.mjs`: original, with nothing to license. `npm run audio` writes them to `public/audio/` (not committed); `dev` and `render` run it first. The music follows the cut: a quiet intro, a beat from the problem onwards, no drums under the chat so its typing and clicks are heard, the groove back for the features and a held chord under the ending. If the scenes change length, the sections in `music()` can be moved to match.

Sound effects are `<Sfx>` elements in the scenes (frames from the start of the scene); every click of the on-screen pointer plays a click by itself (`src/components/Cursor.jsx`).

## Changing it

- **Words:** everything on screen is in `src/copy.js`, so the video can be reworded or translated there.
- **Order and lengths:** `SCENES` in `src/Promo.jsx` (frames at 30 fps).
- **Music:** set `MUSIC` in `src/Promo.jsx` to another file in `public/` to use your own track (one you have the rights to), and `MUSIC_VOLUME` for its level.
- **Screenshots:** `public/shots/` holds copies of `frontend/src/features/landing/shots/*-en-dark.webp`. Copy them again after new landing screenshots are taken.
- **QR code:** `public/demo-qr.svg` is `docs/demo-day/energyflow-demo-qr.svg` and opens https://energyflow-ks.vercel.app/demo.

Figures on screen are the demo company's simulated data, and the video says so; the assistant's answers follow `backend/src/Services/Assistant/DataAnswers.php`. Remotion is free for individuals and companies of up to three people; see https://www.remotion.dev/license.
