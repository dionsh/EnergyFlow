# Deploying EnergyFlow for free

| Piece | Host | Plan |
|---|---|---|
| Database | **Aiven for MySQL** | Free (1 CPU, 1 GB RAM, 1 GB disk) |
| API (`backend/`) | **Render** web service (Docker) | Free |
| Web app (`frontend/`) | **Vercel** | Hobby |
| Scheduler / keep-warm | **cron-job.org** | Free |

Do the steps in this order. The API needs the database, and the web app needs the API's URL.

---

## 1. Aiven: MySQL database

1. Sign in at https://console.aiven.io and choose **Create service → MySQL → Free plan**. Pick a European region if one is offered.
2. When the service is *Running*, open **Overview → Connection information** and note:
   - **Host**
   - **Port**: *not* 3306; Aiven uses its own port.
   - **User**: `avnadmin`
   - **Password**
   - **Database**: `defaultdb`
3. Download the **CA certificate** (`ca.pem`). Keep it outside the repo. `*.pem` is git-ignored.

You don't need to create tables. The API runs its migrations automatically every time it starts.

## 2. Render: the API

1. Go to https://dashboard.render.com and choose **New → Blueprint**. Connect the GitHub repo `dionsh/EnergyFlow`. Render reads `render.yaml`.
2. When asked for the secret values, enter:

   | Key | Value |
   |---|---|
   | `DB_HOST` | Aiven host |
   | `DB_PORT` | Aiven port |
   | `DB_PASS` | Aiven password |
   | `DB_SSL_CA_CONTENT` | the **entire contents** of `ca.pem`, including the BEGIN/END lines |
   | `GROQ_API_KEY` | your Groq key (can be added later) |

   `CRON_SECRET` is generated automatically. `DB_NAME=defaultdb` and `DB_USER=avnadmin` are already set.
3. Deploy. The first Docker build takes a few minutes. Then open
   `https://energyflow-api.onrender.com/api/v1/health`. You should see `"database":"ok"` and a migration version.
4. If Render gave the service a different URL (the name was taken), copy it. You need it in step 3.

**Free-tier behaviour:** the service sleeps after 15 minutes without traffic, and the first request then takes about 1 minute. Step 4 keeps it awake.

## 3. Vercel: the web app

1. Go to https://vercel.com/new and import `dionsh/EnergyFlow`.
2. Set **Root Directory: `frontend`**. The framework preset (Vite) is detected from `frontend/vercel.json`.
3. If your Render URL is not `energyflow-api.onrender.com`, edit the first rewrite in `frontend/vercel.json`, then commit and push.
4. Deploy. No environment variables are needed: `/api/*` is proxied to Render, so the browser stays on one origin. That means no CORS setup, and the login cookie is first-party.

## 4. cron-job.org: keep-warm and background jobs

1. Create a free account at https://cron-job.org.
2. Add a job: `GET https://energyflow-api.onrender.com/api/v1/health` **every 10 minutes**.
   - This keeps Render from sleeping (750 free hours a month covers one always-on service).
   - It also keeps Aiven active, since free services can be powered off when idle.
3. *(Coming with the data pipeline)* A second job will call `POST /api/v1/internal/jobs/run` every 5 minutes with the header `X-Cron-Secret: <CRON_SECRET from Render>`.

---

## Gotchas (learned on SproutSync and VenueSphere)

- **Aiven refuses unencrypted connections.** `DB_SSL_CA_CONTENT` must contain the full certificate.
- **The Render filesystem is wiped** on every deploy and every sleep. Nothing may be stored on disk; all state lives in MySQL.
- **Only `backend/public/` is web-reachable.** `.env`, `src/` and `database/` sit outside the document root, set in `backend/docker/apache-energyflow.conf`.
- **Never use "is this localhost?" checks for security.** Behind Render's proxy every request looks local. Internal endpoints use a secret header instead.
- **Shell and config files must use LF line endings.** This is enforced by `.gitattributes`.
- **`SESSION_SECURE=1` in production, `0` for plain-http local development.** Otherwise the browser drops the login cookie.
- **Vercel Hobby is for non-commercial use.** Fine for the hackathon; move to Pro (or Cloudflare Pages) before a paid pilot.
