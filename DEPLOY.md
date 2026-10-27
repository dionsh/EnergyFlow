# Deploying EnergyFlow for free

| Piece | Host | Plan |
|---|---|---|
| Database | **Aiven for MySQL** | Free (1 CPU, 1 GB RAM, 1 GB disk) |
| API (`backend/`) | **Render** web service (Docker) | Free (0.1 CPU, 512 MB RAM) |
| Web app (`frontend/`) | **Vercel** | Hobby |
| Background jobs / keep-warm | **cron-job.org** | Free |

Do the steps in this order. The API needs the database, the web app needs the API's URL, and the API needs the web app's URL for password-reset links.

Render and Vercel both build from the GitHub repo `dionsh/EnergyFlow` (public), so pushing to `main` is how you deploy. Render redeploys the API only when something under `backend/` changes.

---

## 1. Aiven: MySQL database

1. Sign in at https://console.aiven.io and choose **Create service → MySQL → Free plan**. Pick the region closest to Frankfurt that is offered: Render runs in Frankfurt, and every page makes many small queries, so a far-away database slows the whole app down.
   - Aiven allows **one free MySQL service per organization**. If you already have one (an earlier EnergyFlow attempt or another project), reuse it, and power it on if Aiven paused it. If it holds another project's tables, create a separate database on its **Databases** tab (for example `energyflow`) and change `DB_NAME` in `render.yaml` to it before step 2.
2. When the service is *Running*, open **Overview → Connection information** and note:
   - **Host**
   - **Port**: *not* 3306; Aiven uses its own port.
   - **User**: `avnadmin`
   - **Password**
   - **Database**: `defaultdb`
3. Download the **CA certificate** (`ca.pem`). Keep it outside the repo: `*.pem` is git-ignored, and the repo is public.

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
   | `GROQ_API_KEY` | your Groq key (https://console.groq.com/keys). **Without it Ask EnergyFlow only answers data questions**, and report narratives and Scan are off |
   | `DEMO_OWNER_PASSWORD` | the password for the demo owner `owner@ylli-plast.demo` (simplest: the same as in your `backend/.env`). Step 5 must use the same value. Without it, every Demo Director reset gives the owner a new random password |
   | `FRONTEND_URL` | the Vercel app URL, used in password-reset links. You only get it in step 3, so enter a placeholder such as `https://example.com` for now |
   | `EMAILJS_SERVICE_ID` | EmailJS email service ID |
   | `EMAILJS_TEMPLATE_ID` | EmailJS password-reset template ID |
   | `EMAILJS_PUBLIC_KEY` | EmailJS public key |
   | `EMAILJS_PRIVATE_KEY` | EmailJS private key (keep server-side) |

   `CRON_SECRET` and `APP_KEY` are generated automatically. `DB_NAME=defaultdb` and `DB_USER=avnadmin` are already set.
3. Deploy. The first Docker build takes a few minutes. Then open
   `https://energyflow-api.onrender.com/api/v1/health`. You should see `"database":"ok"`, a migration version (currently `0018_company_logo`) and **`"ai_configured":true`**.
   - `false` means `GROQ_API_KEY` is missing: add it under the service's **Environment** tab (a Blueprint never fills a `sync: false` value by itself), save, and let Render redeploy.
   - A failed deploy or `"database":"unreachable"`: open the service's **Logs** and look for `[migrate] FAILED`. It is almost always the host, the port, the password, or an incomplete `DB_SSL_CA_CONTENT`.
   - A plain-text `Not Found` from Render (header `x-render-routing: no-server`) means no service answers at that URL: use the URL Render shows on the service page.

   Password reset emails use EmailJS. Create an email service and reset template, then set the EmailJS values above. See [`docs/forgot-password-email.md`](docs/forgot-password-email.md) for setup.
4. If Render gave the service a different URL (the name was taken), copy it. You need it in steps 3 and 4.

**Free-tier behaviour:** the service sleeps after 15 minutes without traffic, and the first request then takes about 1 minute. Step 4 keeps it awake. The free instance also has only 0.1 CPU. Normal pages are fine, but a full Demo Director reset (about 15 s on a laptop) takes much longer there, so reset before you go on stage, not during.

## 3. Vercel: the web app

1. Go to https://vercel.com/new and import `dionsh/EnergyFlow`.
2. Set **Root Directory: `frontend`**. The framework preset (Vite) is detected from `frontend/vercel.json`.
3. If your Render URL is not `energyflow-api.onrender.com`, edit the first rewrite in `frontend/vercel.json`, then commit and push.
4. Deploy. No environment variables are needed: `/api/*` is proxied to Render, so the browser stays on one origin. That means no CORS setup, and the login cookie is first-party.
   Optional: `VITE_CONTACT_EMAIL` puts a public contact address on the landing page (build-time variable, so redeploy after setting it). Never put API keys in a `VITE_` variable: those end up in the browser.
5. Copy the production URL from the project page (`energyflow.vercel.app` belongs to someone else, so yours will differ). On Render, open the service's **Environment** tab, set `FRONTEND_URL` to it and save. Render redeploys.

## 4. cron-job.org: background jobs and keep-warm

1. Create a free account at https://cron-job.org.
2. Create one job:
   - **URL:** `https://energyflow-api.onrender.com/api/v1/internal/jobs/run` (your Render URL)
   - **Schedule:** every 5 minutes
   - **Advanced:** request method **POST**, and a header `X-Cron-Secret` whose value is `CRON_SECRET` from the Render service's **Environment** tab
   - Turn on the e-mail notification for failed runs.
3. Use **Test run**. Expect HTTP 200 with `"errors":[]`. A 403 means the secret doesn't match.

Each run advances the demo simulator, rolls up and analyses data from real devices, deletes raw readings older than 72 hours (so the 1 GB Aiven disk doesn't fill up), and marks silent devices offline. Every call is also traffic, so Render doesn't sleep and Aiven doesn't power the database off. You don't need a separate health ping.

## 5. Demo company: load it into Aiven from your computer

Render's free plan has no shell, so run the seeder once from your computer against Aiven. Until then, **Try the demo** doesn't work.

Open PowerShell in `backend/`. These variables last only for this window and win over `backend/.env`, so your local setup stays untouched:

```powershell
$env:DB_HOST = '<Aiven host>'
$env:DB_PORT = '<Aiven port>'
$env:DB_NAME = 'defaultdb'
$env:DB_USER = 'avnadmin'
$env:DB_PASS = '<Aiven password>'
$env:DB_SSL_CA = 'C:\path\to\ca.pem'
$env:DEMO_OWNER_PASSWORD = '<the same value as on Render>'
php bin/migrate.php
php bin/seed-demo.php
```

Use single quotes as shown: inside double quotes PowerShell would expand a `$` in a password.

Over the internet this takes a few minutes (about 15 s against a local database). Then open the Vercel URL and choose **Try the demo**, or sign in as `owner@ylli-plast.demo` with `DEMO_OWNER_PASSWORD` to use the Demo Director. Keep the window open for step 6, then close it.

---

## 6. Platform admin panel

The panel at `/admin` lists every company and user, and lets EnergyFlow staff edit or delete accounts. Nobody has access until you grant it, and only from the command line:

1. Sign up in the deployed app with your own e-mail (a normal company account).
2. In a PowerShell window with the Aiven variables from step 5, run in `backend/`:
   ```
   php bin/platform-admin.php grant you@example.com
   ```
   `php bin/platform-admin.php list` shows who has access; `revoke` takes it away.
3. Reload the app. **Admin** appears at the bottom of the sidebar.

Demo accounts can never be platform admins, and demo users cannot be edited or deleted from the panel (the Demo Director rebuilds them).

---

## 7. Checking the AI assistant

- **Deployed:** `GET /api/v1/health` → `"ai_configured": true`. The admin panel's overview shows the same flag.
- **Locally:** `php bin/check-ai.php` (in `backend/`) checks that `GROQ_API_KEY` is set and accepted, that every configured model is still offered by Groq, and that each one answers. It never prints the key.
- **In the app:** each answer carries a label. *From your data* = answered from the database; *AI · checked against your data* / *AI · general knowledge* = written by Groq; *Limited answer* = the model was unavailable, and the text says why (not configured, key rejected, or free-tier limit; the last clears within a minute).
- The free tier allows 8,000 tokens per minute per model, about 2–3 open questions per minute per model; with the three-model chain, roughly 6–9 per minute in total.

---

## Gotchas (learned on SproutSync and VenueSphere)

- **Aiven refuses unencrypted connections.** `DB_SSL_CA_CONTENT` must contain the full certificate.
- **The API sets its own SQL mode on every connection** (`backend/src/Core/Database.php`). Aiven's default is strict and SQL-standard (ANSI); XAMPP's is non-strict. With one mode everywhere, the local tests check what production does. MariaDB also accepts words that MySQL 8.4 reserves (`manual`, `qualify`, `tablesample`…), so don't use them as column names or aliases.
- **The Render filesystem is wiped** on every deploy and every sleep. Nothing may be stored on disk; all state lives in MySQL.
- **Render's 750 free hours a month are per workspace, shared by all its free services.** One always-awake service uses about 744. If another project's free service in the same workspace is also kept awake, the hours run out and Render suspends every free service until the next month. Keep EnergyFlow in its own workspace.
- **Only `backend/public/` is web-reachable.** `.env`, `src/` and `database/` sit outside the document root, set in `backend/docker/apache-energyflow.conf`.
- **Never use "is this localhost?" checks for security.** Behind Render's proxy every request looks local. Internal endpoints use a secret header instead.
- **Shell and config files must use LF line endings.** This is enforced by `.gitattributes`.
- **`SESSION_SECURE=1` in production, `0` for plain-http local development.** Otherwise the browser drops the login cookie.
- **Vercel preview deployments use the production API.** Branch and pull-request previews proxy `/api` to the same Render service and Aiven database.
- **A real device on production needs Render's `APP_KEY`.** Device secrets are derived from it, so run `bin/provision-device.php` with the step 5 variables plus `$env:APP_KEY` set to Render's value, or the printed secret won't be accepted.
- **Vercel Hobby is for non-commercial use.** Fine for the hackathon; move to Pro (or Cloudflare Pages) before a paid pilot.
