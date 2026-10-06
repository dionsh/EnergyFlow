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
| GET | `/energy/flow?period` | V | Sankey nodes and links (grid → nodes → machines → productive/waste), with € and CO₂ totals |
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
| GET | `/scores` | V | EnergyFlow Score + ESG Readiness with breakdowns and history |
| GET | `/ml/models` | V | Active model cards |

## Carbon and ESG

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/carbon/summary?period` | V | Scope 1, Scope 2 (location; market = n/a), intensities, factor used, avoided emissions |
| GET | `/carbon/breakdown?period&group_by=machine\|department\|month` | V | |
| GET | `/carbon/trend?granularity=day\|week\|month&from&to` | V | |
| CRUD | `/carbon/activity-data` | M | Scope 1 fuel entries |
| GET | `/esg/vsme-b3?year=2026` | V | B3 datapoint table with status per row (`auto`/`manual`/`estimated`/`missing`) |
| GET | `/esg/readiness` | V | Score, checklist, next 3 steps |
| PUT | `/esg/answers/{item_key}` | A | B2 practices answers |

## Reports

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/reports` | V | List |
| POST | `/reports` `{type, period_start, period_end, language}` | M | Build the snapshot (deterministic) + narrative (LLM, optional) → draft |
| GET | `/reports/{id}` | V | Snapshot + narrative (the frontend renders the web view and the PDF) |
| PATCH | `/reports/{id}/narrative` | M | Edit narrative sections before finalising |
| POST | `/reports/{id}/finalize` | A | Locks the report (audited); a new version is needed to change it |

## Assistant

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET / POST | `/assistant/conversations` | V | List / create |
| GET | `/assistant/conversations/{id}` | V | Messages |
| POST | `/assistant/conversations/{id}/messages` `{content, context?}` | V | Answer (rate-limited). `context` = optional page context (e.g. `{machine_id: 3}`) for "Explain this". |
| POST | `/assistant/explain` `{kind: alert\|chart\|recommendation, id}` | V | Short grounded explanation of one item |

## Demo (demo company only, guarded by `companies.is_demo`)

| Method | Path | Purpose |
|---|---|---|
| GET | `/demo/state` | Virtual clock, speed, active scenarios |
| POST | `/demo/clock` `{set_local: "21:40"}` or `{advance_days: 7}` | Set clock / fast-forward (generates data + runs jobs for the range) |
| POST | `/demo/scenarios` `{scenario, machine_id, params}` · DELETE `/demo/scenarios/{id}` | Trigger / stop S1–S6 |
| POST | `/demo/reset` | Rebuild the deterministic story |

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
```json
{
  "data": {
    "answer_markdown": "Your compressor (CMP-01) was the largest consumer in September: **3,412 kWh, 27 % of the site**. It also ran **14.5 h outside working hours** …",
    "sources": [
      { "tool": "get_energy_summary", "label": "Energy by machine · 1–30 Sep 2026" },
      { "tool": "get_waste_summary", "label": "After-hours waste · 1–30 Sep 2026" }
    ],
    "suggested_actions": [ { "type": "open_recommendation", "id": 12, "label_key": "assistant.action.view_recommendation" } ],
    "refused": false, "grounded": true, "language": "en"
  }
}
```

### Refusal (no main-model call)
```json
{ "data": { "answer_markdown": "I can only help with your company's energy, costs, carbon and sustainability data…",
  "refused": true, "scope_category": "off_topic",
  "suggestions": ["Which machine cost us the most this month?", "How much did we waste after working hours this week?", "What should we change tomorrow?"] } }
```

## Rate limits (initial)

| Bucket | Limit |
|---|---|
| Login | 5 / min per IP + email |
| Assistant | 30 messages / hour per user; 200 / day per company |
| Ingest | 30 requests / min per device |
| Report generation | 10 / hour per company |
