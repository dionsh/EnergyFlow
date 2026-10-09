# EnergyFlow

**Energy intelligence for Kosovo SMEs.** EnergyFlow measures electricity per machine with low-cost IoT devices, detects waste, recommends and safely performs actions, proves the savings, and turns the same data into CO₂ and sustainability (ESG) reports.

Built for the GPEK **Digital Green Innovation Competition 2026**: Challenge Areas 01 (energy efficiency and real-time monitoring) and 06 (carbon tracking and ESG reporting).

> Measure → Understand → Detect → Predict → Recommend → Act → Prove

## Repository layout

| Path | What |
|---|---|
| `backend/` | PHP 8.4 REST API (plain PHP + PDO, no framework). Deployed on **Render** (Docker). |
| `frontend/` | React + Tailwind CSS (Vite). Deployed on **Vercel**. |
| `docs/` | Research, architecture, database, API, UX and roadmap. Start with [`docs/README.md`](docs/README.md). |
| `render.yaml` | Render Blueprint for the API |
| `DEPLOY.md` | Step-by-step free deployment (Aiven → Render → Vercel) |

The database is **MySQL on Aiven** (free tier) in production and MariaDB (XAMPP) locally.

## Local development (Windows)

Prerequisites (already set up on the main dev machine): **PHP 8.4** (Laravel Herd), **MariaDB/MySQL** (XAMPP), **Node 24**.

```powershell
# 1. Database: start MariaDB (XAMPP Control Panel → MySQL → Start), then:
mysql -u root -e "CREATE DATABASE IF NOT EXISTS energyflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 2. API
cd backend
copy .env.example .env
php bin/migrate.php
php -S 127.0.0.1:8000 -t public bin/serve.php

# 3. Web app (second terminal)
cd frontend
npm install
npm run dev
```

Open http://localhost:5173. Vite proxies `/api` to the PHP server, so the browser sees one origin, exactly like production.

To load the demo company (a fictional plastics SME with a 75-day energy story ending at 21:40 on a weekday):

```powershell
php backend/bin/seed-demo.php            # or --scene=now, --days=30
```

The owner signs in as `owner@ylli-plast.demo` with `DEMO_OWNER_PASSWORD` from `backend/.env`; anyone can use **Explore the demo** (read-only).

## What's built

| Module | What it does |
|---|---|
| Overview | EnergyFlow Score (six weighted parts from the measurements, with what to fix next), month-end forecast with a P10–P90 range from a backtest, energy-flow diagram (grid → machines → productive or waste) |
| Live · Machines · Devices | Power per machine every few seconds, consumption, cost and CO₂e per machine, device health |
| Waste & Alerts | After-hours and idle episodes, compressed-air leak signature, efficiency drift (CUSUM), power spikes and motor overloads (robust z-score), offline devices; quantified in kWh, € and CO₂e, each with the evidence behind it |
| Scan | Camera (or photo upload) for an electricity bill (checked for arithmetic, VAT, licensed supplier, meter and tariff: consistent, check or likely fake), a meter reading, a motor nameplate, a fuel receipt (Scope 1) or an appliance energy label. A vision model reads the photo, the user confirms every field, the checks are deterministic. Photos are never stored |
| Opportunities | Ranked recommendations, each quantified by replaying the machine's own data; a What-if simulator |
| Turn Off & Automations | A command loop to the relay, verified only by the meter; policies such as "off 15 min after the shift" |
| Impact | Before/after proof per action: adjusted baseline, 90 % confidence interval (IPMVP-inspired) |
| Carbon & ESG | Scope 2 from metered electricity, Scope 1 from scanned fuel receipts (DESNZ 2025 factors), VSME B3 datapoints, readiness checklist, methodology |
| Reports | Monthly energy and sustainability report, frozen figures, AI-drafted text checked against them, A4 PDF |
| Ask EnergyFlow | Assistant (Ctrl/⌘ K): data questions answered straight from MySQL; open questions by Groq over a data snapshot, every number checked; off-topic questions refused; Turn Off only after the user confirms |
| Demo Director | Virtual clock (+5 min to +7 days), story reset, and fault injection (a motor overload the spike detector must find) |
| Hardware bridge | `tools/hw-bridge`: real smart relays (Shelly Gen2) speak the same signed device protocol as the EF-N3 nodes |

## Checks

```powershell
php backend/tests/run.php        # backend test suite (uses a throwaway energyflow_test database)
npm --prefix frontend run lint
npm --prefix frontend run build
```

## Principles

- **Data-driven.** Every number on screen comes from the API and database. There are no hard-coded values in the frontend.
- **Simulated and real devices are treated the same.** Both feed the same ingest pipeline.
- **Honest AI.** ML models (fingerprint, anomaly, forecast) are labelled by method. The Groq LLM only explains our data and refuses off-topic questions.
- **Sourced facts.** Kosovo's grid factor, tariffs and ESG references are cited in [`docs/01-research.md`](docs/01-research.md).
- **Languages:** Albanian and English. German and French are planned; see `frontend/src/i18n/languages.js`.
