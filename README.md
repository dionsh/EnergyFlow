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
