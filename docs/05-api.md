# 05 — API Design (REST, `/api/v1`)

## Conventions

| Topic | Rule |
|---|---|
| Base | `/api/v1`. The browser calls it **same-origin** (Vercel proxies to Render). Devices call Render directly. |
| Auth (users) | `ef_session` HttpOnly cookie. Every mutation also needs the `X-EF-Client: web` header (CSRF). |
| Auth (devices) | `Authorization: Device <key>` + `X-EF-Timestamp` + `X-EF-Signature` (HMAC-SHA256) |
| Auth (internal) | `X-Cron-Secret` |
| Tenancy | `company_id` **always** comes from the session; it is never accepted from the client |
| Success | `{ "data": …, "meta": { "server_time": "…", "period": {…}, "units": {…} } }` |
| Errors | `{ "error": { "code": "validation_failed", "message": "…", "fields": { "name": "required" } } }` with 400/401/403/404/409/422/429/500 |
| Time | ISO 8601 UTC in and out. Period params are `from`/`to`, or `period=today\|7d\|30d\|mtd\|month:2026-09\|year:2026`, resolved in the company timezone |
| Units | Numbers are raw (kWh, €, kg CO₂e, kW). The frontend formats them. Every response states its units in `meta.units`. |
| Lists | `?page=1&per_page=25` → `meta.pagination`; sort uses whitelisted fields only |
| Explainability | Insight-bearing objects include `evidence` and `method` (`rule \| statistical \| pattern \| ml \| llm`) |
| Text | Server returns `title_key` + `params`; the client renders them through i18n. LLM text is returned as markdown. |

Roles: **V** viewer, **M** manager, **A** admin, **O** owner. Each role includes the one before it (O ⊃ A ⊃ M ⊃ V).

---

## Auth and account

| Method | Path | Role | Purpose |
|---|---|---|---|
| POST | `/auth/register` | public | Create company + owner (name, email, password, company name, city) |
| POST | `/auth/login` | public | Set session cookie (rate-limited) |
| POST | `/auth/password/forgot` | public | Request a one-hour reset link; always returns the same message to prevent account discovery |
| POST | `/auth/password/reset` | public | Use the one-time token and a new password; revokes existing sessions |
| POST | `/auth/logout` | V | Revoke session |
| GET | `/auth/me` | V | User, role, company summary, locale, `is_demo` |
| PATCH | `/auth/me` | V | Name, locale, password change |
| POST | `/invitations` · GET · DELETE `/invitations/{id}` | A | Invite employees |
| POST | `/invitations/accept` | public | Accept with token → set password |

## Company setup

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET / PATCH | `/company` | V / A | Profile (VSME B1 fields: legal form, NACE, employees, turnover) |
| CRUD | `/sites` | A | Sites with coordinates |
| CRUD | `/departments` | A | Cost centres |
| CRUD | `/users` | A | List, change role, disable |
| CRUD | `/schedules` (+ nested `rules`) | A | Working hours |
| CRUD | `/calendar-days` | A | Closures and company holidays (national holidays come from the seed) |
| CRUD | `/production-overrides` | M | "Extra shift tonight", so overtime isn't flagged as waste |
| GET / PUT | `/production-output?from&to` | M | Daily output, for intensity per unit |
| CRUD | `/tariffs` (+ windows, rates) | A | Tariff plans, each requiring `source_note` |
| GET | `/tariffs/{id}/bill-preview?month=2026-09` | V | Bill calculation lines for a month |
| CRUD | `/utility-bills` | M | Enter KESCO bills (reconciliation, pre-install baseline) |
| GET / POST | `/emission-factors` | V / A | List defaults + custom; add a custom factor (source required) |
| PATCH | `/company/emission-factor` | A | Select the active grid factor (audited) |

## Machines and devices

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/machines` | V | List with live state, today kWh, month kWh, anomaly status |
| POST / PATCH / DELETE | `/machines/{id}` | A | Create, edit, archive (thresholds, schedule, criticality, control mode) |
| GET | `/machines/{id}` | V | Detail: live, period summary, schedule, efficiency, anomaly status, explanation keys |
| GET | `/machines/{id}/timeseries?metric=power\|energy\|cost\|co2\|pf\|temp&from&to&resolution=auto` | V | Charts (auto: 1 min ≤ 24 h, 15 min ≤ 7 d, 1 h ≤ 31 d, 1 d beyond) |
| GET | `/machines/{id}/operating-hours?period` | V | Running / idle / off hours, in and out of schedule |
| GET | `/machines/{id}/fingerprint` | V | Reference vs current features, drift score, predicted type + top-3 |
| GET | `/machines/{id}/baseline` | V | Hour-of-day baseline bands (for chart overlays) |
| POST | `/machines/{id}/commands` `{command}` | M | Turn off / on / standby. Rejected for `criticality=critical` (409). |
| GET | `/machines/{id}/commands` | V | Command history with verification |
| GET | `/devices` | V | Fleet: status, last seen, signal, channels, simulated flag |
| POST | `/devices` | A | Provision → **returns the device secret once** |
| GET | `/devices/{id}` | V | Latest V, I, P, f, PF, temperature per channel; transmission stats |
| GET | `/devices/{id}/readings?from&to` | V | Raw electrical series (≤ 72 h) |
| PUT | `/devices/{id}/channels` | A | Map channels to machines (versioned) |
| POST | `/devices/{id}/rotate-key` | A | New secret |

## Device-facing (hardware, hw-bridge, emulator)

| Method | Path | Purpose |
|---|---|---|
| POST | `/ingest/readings` | Batch readings + events + status (payload in [03-architecture.md §3.2](03-architecture.md#32-telemetry-protocol-identical-for-real-nodes-the-bridge-and-the-device-emulator)). Returns `{accepted, duplicates, server_time}`. |
| GET | `/device/commands` | Pending commands for this device |
| POST | `/device/commands/{id}/ack` | `{status: acknowledged\|executed\|failed, detail}` |

## Live and overview

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/live` | V | Site kW now, every machine's live row, devices online, `last_reading_at`, running-after-hours flags, live waste meter. In demo mode this also triggers simulator catch-up. |
| GET | `/overview?date` | V | KPI set: current kW, today kWh, MTD kWh, estimated bill (forecast), MTD CO₂, verified savings, open alerts by severity, machines running, EnergyFlow Score |
| GET | `/energy-flow` | V | This month's flow: site kWh/€/CO₂, per machine kWh, € and quantified waste, unmetered rest, productive vs waste (drawn as a Sankey) |
| GET | `/score` | V | EnergyFlow Score: six parts (value, weight, numbers behind it), change vs the previous 7 days, the part to fix next |
| GET | `/energy/consumption?period&group_by=machine\|department\|day\|hour\|tariff_period` | V | Breakdown series |
| GET | `/energy/load-profile?period` | V | Day × hour heatmap matrix |
| GET | `/energy/bill-variance?a=2026-08&b=2026-09` | V | Decomposition (waterfall) + drivers |
| GET | `/energy/coverage?period` | V | Metered vs incomer vs billed kWh |

## Waste, alerts and notifications

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/waste/summary?period` | V | Totals by type and by machine (kWh, €, CO₂), Waste Score, trend |
| GET | `/waste-events?period&type&machine_id&severity&status` | V | List |
| GET | `/waste-events/{id}` | V | Detail + evidence + suggested action |
| GET | `/alerts?status&severity` | V | List |
| GET | `/alerts/{id}` | V | One alert with its evidence (baseline, observed values, thresholds; for spikes the minute series) |
| POST | `/alerts/{id}/acknowledge` · `/alerts/{id}/resolve` | M | Lifecycle |
| GET | `/notifications?unread=1` | V | Notification centre |
| POST | `/notifications/{id}/read` · `/notifications/read-all` | V | |

## Optimization and automation

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/recommendations?status&band` | V | Ranked opportunities |
| GET | `/recommendations/{id}` | V | Evidence, what-if result, proposed policy |
| POST | `/recommendations/{id}/accept` `{mode}` | M | Creates the automation policy (T₀ for M&V) |
| POST | `/recommendations/{id}/dismiss` `{reason}` | M | Feedback loop / suppression |
| POST | `/what-if` `{type, machine_id, params, window_days}` | V | Replay → `{baseline, scenario, delta, annualized, assumptions}` |
| CRUD | `/policies` | M | Automation policies (activate / deactivate) |
| GET | `/commands?period` | V | Fleet-wide command log |

## Forecast, impact, scores

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/forecast?horizon=day\|week\|month` | V | kWh / € / CO₂ P10–P50–P90, hourly profile, model card summary |
| GET | `/impact/summary?period` | V | Before/after at company level (raw + adjusted) |
| GET | `/impact/interventions` | V | Every intervention with its verification status |
| GET | `/impact/interventions/{id}` | V | Baseline model, adjustments, CI, daily series |
| GET | `/score` | V | EnergyFlow Score (see Live and overview). ESG Readiness is `/esg/readiness`; the month-end forecast with its P10–P90 range and backtest is `projection` in `/overview` |
| GET | `/ml/models` | V | Active model cards |

## Carbon and ESG

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/carbon/summary?period` | V | Scope 1, Scope 2 (location; market = n/a), intensities, factor used, avoided emissions |
| GET | `/carbon/breakdown?period&group_by=machine\|department\|month` | V | |
| GET | `/carbon/trend?granularity=day\|week\|month&from&to` | V | |
| GET · DELETE | `/fuel-records` · `/fuel-records/{id}` | V · M | Scope 1 fuel entries (added through Scan) |
| GET | `/esg/vsme-b3?year=2026` | V | B3 datapoint table with status per row (`auto`/`manual`/`estimated`/`missing`) |
| GET | `/esg/readiness` | V | Score, checklist, next 3 steps |
| PUT | `/esg/answers/{item_key}` | A | B2 practices answers |

## Scan (camera)

Photos are read by a vision model on Groq and never stored. The user reviews every field before `check` and `save`.

| Method | Path | Role | Purpose |
|---|---|---|---|
| POST | `/scan/read` `{kind, image}` | V | `kind` = `bill`, `meter`, `nameplate`, `fuel` or `label`; `image` = JPEG/PNG/WebP data URL ≤ 1 MB → `{recognised, fields}`. 20/h per user, `SCAN_DAILY_LIMIT` per company |
| POST | `/scan/check` `{kind, fields, machine_id?}` | V | Deterministic checks → `{verdict, checks[], derived}`; bills: `consistent` / `check` / `likely_fake` |
| POST | `/scan/save` `{kind, fields, machine_id?}` | M | Bill → `utility_bills` (billed vs metered), meter → `meter_readings`, nameplate → machine rating, fuel → Scope 1 `activity_data` |
| GET · DELETE | `/bills` · `/bills/{id}` | V · M | Saved bills with the metered kWh for the same period and coverage |
| GET · DELETE | `/meter-readings` · `/meter-readings/{id}` | V · M | Main-meter readings |

## Reports

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/reports` | V | List |
| POST | `/reports` `{type: daily\|weekly\|monthly, period, language}` | M | Build a frozen snapshot + grounded narrative → draft. `period` is `YYYY-MM-DD` for daily or a week anchor date for weekly, and `YYYY-MM` for monthly. |
| GET | `/reports/{id}` | V | Snapshot + narrative (the frontend renders the web view and the PDF) |
| PATCH | `/reports/{id}/narrative` | M | Edit narrative sections before finalising |
| POST | `/reports/{id}/finalize` | A | Locks the report (audited); a new version is needed to change it |

## Assistant

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET / POST | `/assistant/conversations` | V | List / create (own conversations; the shared demo guest's are per browser session) |
| GET / DELETE | `/assistant/conversations/{id}` | V | Messages / delete |
| POST | `/assistant/conversations/{id}/messages` `{content, language?, context?}` | V | Answer (rate-limited). `context` = page context: `{page, machine_id?, waste_event_id?, recommendation_id?}`. "Explain this" buttons send a question with the item's id as context (no separate `/assistant/explain`). |
| POST | `/assistant/messages/{id}/action` `{state: confirmed\|cancelled, command_id?}` | V | Records the answer to a Turn Off card. The Turn Off itself is `POST /machines/{id}/commands` (manager+), never the assistant. |
| GET | `/assistant/suggestions?page=&machine_id=&language=` | V | Starter questions for the page and what is happening now |

## Demo (demo company only, guarded by `companies.is_demo`)

| Method | Path | Purpose |
|---|---|---|
| GET | `/demo/state` | Virtual clock, offset, story anchor, generated-until |
| POST | `/demo/advance` `{hours}` or `{minutes}` | Fast-forward (generates the data and runs the analytics pipeline for the range) |
| POST | `/demo/spike` `{machine_id?, minutes?, percent?}` | Motor overload from the next reading: the machine draws `percent` % (100–200, default 135) of its rating for `minutes` (3–30, default 6). Without `machine_id`: the motor-driven machine drawing the most power. 409 if it is off |
| POST | `/demo/reset` `{scene?}` | Rebuild the deterministic story (`weekday_evening` or `now`) |

## Platform admin (EnergyFlow staff, guarded by `users.is_platform_admin`)

Granted only from the command line (`php bin/platform-admin.php grant <email>`), never through the API, and never to a demo account. Every change is written to `admin_actions` (not to the companies' own audit log).

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/overview` | Platform counts (customer companies and users, active users, devices, activity; the demo company counted apart), sign-ups per week, background jobs, system status, recent admin actions |
| GET | `/admin/users?q=&company_id=&role=&status=&page=` | Every user of every company, 50 per page (`meta.total`) |
| PATCH | `/admin/users/{id}` `{full_name?, email?, role?, locale?, disabled?}` | Edit. Disabling revokes the user's sessions; a new e-mail cancels open reset links. 409 `last_owner` (a company always keeps an active owner), `cannot_disable_self`, `demo_account` |
| DELETE | `/admin/users/{id}?with_company=1` | Delete. A team member's sessions, reset links and assistant conversations go with them; what they created stays with the company. The company's only user takes the company and all its data along, and only with `with_company=1` (otherwise 409 `company_would_be_empty`). 409 `last_owner`, `cannot_delete_self`, `demo_account` |
| POST | `/admin/users/{id}/password-reset` | E-mail the user a reset link (the link never reaches the admin) |
| GET | `/admin/companies?q=` | Every company with its users, owner, machines, devices, last data and reports |

## Internal and health

| Method | Path | Purpose |
|---|---|---|
| POST | `/internal/jobs/run` | Cron entry point (secret); runs due jobs within a time budget |
| GET | `/health` | Database reachability, migration version, job lag, Groq configured (bool) |

---

## Example responses

### `GET /live` (polled every 5 s)
```json
{
  "data": {
    "site_kw": 34.82,
    "machines": [
      { "id": 3, "code": "CMP-01", "name": "Screw compressor", "type": "compressor",
        "state": "running", "state_since": "2026-11-15T19:02:10Z",
        "power_kw": 10.94, "pf": 0.86, "temperature_c": 61.5, "today_kwh": 96.4,
        "scheduled_now": false, "after_hours_for_s": 2460,
        "anomaly": { "severity": "warning", "type": "AFTER_HOURS" },
        "control": { "mode": "approve", "can_turn_off": true } }
    ],
    "devices": { "online": 4, "total": 5 },
    "waste_now": { "since": "2026-11-15T20:00:00Z", "kwh": 7.6, "eur": 0.89, "co2_kg": 6.8 },
    "last_reading_at": "2026-11-15T20:41:00Z"
  },
  "meta": { "server_time": "2026-11-15T20:41:03Z", "units": { "power": "kW", "energy": "kWh", "money": "EUR", "co2": "kg CO2e" } }
}
```
*(Illustrative values. Real responses come from the simulator and database.)*

### `POST /assistant/conversations/{id}/messages`
Answer from data (no model call), with a Turn Off card the user must confirm:
```json
{
  "data": {
    "user": { "id": 81, "role": "user", "content": "turn off the compressor", "language": "en", "created_at": "2026-10-08T19:40:12Z" },
    "assistant": {
      "id": 82, "role": "assistant", "language": "en",
      "source": "data", "intent": "turn_off", "grounded": null,
      "content": "Turn off **Screw air compressor · 11 kW (CMP-01)** now? It is drawing 10.7 kW.

It has run 0 h 40 min after hours: 7.1 kWh, €0.52, 6.4 kg CO₂e so far. …",
      "sources": [ { "label": "Live readings · 21:40", "to": "/live" } ],
      "actions": [ { "type": "turn_off", "state": "pending", "machine": { "id": 112, "code": "CMP-01", "name": "Screw air compressor · 11 kW" }, "needs_confirm": null } ],
      "latency_ms": 31, "created_at": "2026-10-08T19:40:12Z"
    },
    "conversation": { "id": 10, "title": "turn off the compressor" }
  }
}
```
An LLM answer has `"source": "ai"`, `"grounded": true|false`, sources named after the data sections it used, and `actions` of type `navigate` (`{to, page}`) and `ask` (follow-up questions). Off-topic questions get `"source": "refusal"` with the exact localised sentence and three `ask` suggestions; without a configured model, open questions get `"source": "fallback"`. Illustrative values.

## Rate limits (initial)

| Bucket | Limit |
|---|---|
| Login | 5 / min per IP + email |
| Assistant | 120 messages / hour per user and IP; model calls 30 / hour per user and IP and 200 / day per company (`ASSISTANT_DAILY_LIMIT`). Data answers don't use the model. |
| Ingest | 30 requests / min per device |
| Report generation | 10 / hour per company |
