# EnergyFlow — project status

*Updated 21 Oct 2026. GPEK Digital Green Innovation Competition 2026, Demo Day 15 Nov 2026.*
Detailed design lives in [`docs/`](docs/README.md) (decisions log at the end of `docs/README.md`); deployment in [`DEPLOY.md`](DEPLOY.md).

## Architecture

| Layer | What | Where |
|---|---|---|
| Web app | React 19 (JavaScript) + Tailwind 4, Vite, React Router 7, TanStack Query, Recharts, i18next (sq, en) | `frontend/`, deployed on **Vercel** |
| API | Plain PHP 8.4 + PDO, own router/kernel (`backend/src/Core`), REST under `/api/v1` | `backend/`, **Render** (Docker) |
| Database | MySQL on **Aiven** (prod), MariaDB 10.4 locally; migrations run on every API start | `backend/database/migrations` |
| AI | Groq, called only from the backend: assistant, report narratives, Scan (vision) | `backend/src/Services/Assistant`, `Reports/NarrativeWriter.php`, `Scan/ScanReader.php` |
| Devices | Simulator for the demo; EF-N3 node (prototype design); Shelly Gen2 smart relays through `tools/hw-bridge` | `hardware/`, `tools/hw-bridge` |

The browser talks to one origin: Vite (locally) and Vercel (`frontend/vercel.json`) proxy `/api/*` to the PHP API, so the session cookie is first-party and there is no CORS. Auth is a server-side session (httpOnly cookie); roles owner / admin / manager / viewer; platform admins separately (`/admin`).

## Built and working

- **Public landing page** at `/` for visitors (dashboard there once signed in), with an animated energy-flow diagram and honest feature status.
- **Monitoring:** Overview (EnergyFlow Score, month-end forecast with P10–P90 range, energy-flow Sankey), Live, Machines, Devices.
- **Detection:** after hours, idle, compressed-air leak signature, efficiency drift (CUSUM), power spikes and motor overloads, offline devices, low power factor and peak coincidence (ERO V_2703_2025).
- **Optimisation and action:** ranked opportunities, What-if simulator, Turn Off verified by the meter, automation policies.
- **Proof and reporting:** Impact (IPMVP-inspired, 90 % CI), Carbon & ESG (Scope 2 location-based, Scope 1 from fuel receipts, VSME B3), daily/weekly/monthly reports with PDF.
- **Scan:** bills (consistency / fake checks), meters, nameplates, fuel receipts, energy labels.
- **Ask EnergyFlow:** deterministic data answers + Groq for open questions. Answers general energy, carbon, ESG and compliance questions too, and labels which kind each answer is; company figures are checked against the data.
- **Accounts:** registration, login, read-only demo, password reset (EmailJS), profile, company settings with logo, platform admin panel.
- **Responsive** from 320 px phones to wide desktops (checked page by page on 21 Oct).

## Changed on 21 Oct 2026

- Assistant: wider scope, general-knowledge answers, a "last 7 days" data section, combined questions answered by the model with the data, a three-model chain, smaller prompts, clearer error messages, conversation history without failed turns. New `backend/bin/check-ai.php`.
- Landing page, mobile navigation for it, logo on the sign-in pages links home.
- Responsiveness fixes across the app (see the decisions log for the rules) and card layouts on phones for Live, Machines, Waste events and Reports.
- Fixed: password reset always answered 503 when only EmailJS was configured (`mailConfigured()` still checked the old Resend variables); local development prints the reset link again.
- Tests: stale migration assertion, date-dependent Scan test (failed after the 14th of a month). All 14 suites pass (95 tests).

## Configuration (names only; values live in Render / Vercel / `backend/.env`)

| Variable | Where | Needed for |
|---|---|---|
| `GROQ_API_KEY` | Render, `backend/.env` | Assistant open questions, report narratives, Scan. **Backend only.** |
| `GROQ_MODEL`, `GROQ_MODEL_FALLBACK`, `GROQ_MODEL_FALLBACK_2` | Render (`render.yaml`), `.env` | Optional; defaults `openai/gpt-oss-120b` → `qwen/qwen3.8-27b` → `openai/gpt-oss-20b` |
| `GROQ_VISION_MODEL` | Render, `.env` | Scan (default `qwen/qwen3.8-27b`) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SSL_CA_CONTENT` | Render | Aiven MySQL |
| `APP_KEY`, `CRON_SECRET` | Render (generated) | Device secrets, cron endpoint |
| `FRONTEND_URL`, `EMAILJS_SERVICE_ID`, `EMAILJS_TEMPLATE_ID`, `EMAILJS_PUBLIC_KEY`, `EMAILJS_PRIVATE_KEY` | Render | Password-reset e-mails |
| `DEMO_OWNER_PASSWORD` | Render, `.env` | Optional fixed demo owner password |
| `VITE_CONTACT_EMAIL` | Vercel | Optional public contact address on the landing page |

Check the AI setup: `GET /api/v1/health` → `"ai_configured": true` (deployed) or `php backend/bin/check-ai.php` (local).

## Open items

1. **Production check (manual):** `https://energyflow-api.onrender.com` answered "no server" on 21 Oct. Confirm the Render service URL, make `frontend/vercel.json`'s first rewrite point to it, and set `GROQ_API_KEY` on that service.
2. **Contact details** for the landing page (`VITE_CONTACT_EMAIL`) once the team picks a public address.
3. **Groq free tier** (8K tokens/min per model) limits a live demo to a few open questions per minute; a paid Groq plan removes that limit.
4. **Hardware:** EF-N3 is a prototype design; the on-stage demo uses the hardware bridge with a metering smart plug (`DEMO_HARDWARE_SERIAL`).
5. Planned: German and French interface; equipment-upgrade recommendations (VSD, LED); market-based Scope 2 once Kosovo publishes a residual mix.
6. Assistant arithmetic is checked heuristically (numbers must come from, or be a simple difference/sum/ratio of, numbers in the data). Totals are now pre-computed in the data to avoid model sums.

## Next steps

- Deploy this version, confirm `ai_configured: true`, and run the golden path from `docs/06-ux-design.md` §6 on a phone and the projector.
- Rehearse the Demo Day script with the Demo Director (fault injection, Turn Off, report PDF).

## Commands

```powershell
php backend/tests/run.php        # backend tests (throwaway energyflow_test database)
php backend/bin/check-ai.php     # Groq key and models
npm --prefix frontend run lint
npm --prefix frontend run build
```
