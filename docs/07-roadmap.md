# 07 — Implementation Roadmap

**Window:** Tue 7 Oct → **Sat 15 Nov 2026 (Hackathon & Demo Day)**, 40 days.
**Rule:** feature freeze **Sun 9 Nov**. The last 5 days are for rehearsal, fixes and fallbacks only.

## 1. Scope (MoSCoW)

### Must, for Demo Day
- **Platform:**
  - auth (register, login, logout, roles)
  - company profile (VSME B1 fields), sites, schedules and production overrides
  - tariff templates (official ERO values)
  - seeded emission factor
  - machines, devices and channels (basic admin)
- **Data spine:**
  - deterministic simulator + catch-up
  - demo seed (75-day story)
  - **Demo Director** (clock, S1–S6, fast-forward, reset)
  - ingest API
  - rollups, state detection, `machine_live`
  - job runner + cron
- **Real hardware:** `tools/hw-bridge` + a metering smart plug, giving a real **Turn Off** on stage.
- **Screens:** Overview · Live (with Energy Flow) · Machines + detail (Power / Energy & cost / Operating hours / Events) · Devices + detail · Waste & Alerts · Opportunities (+ What-if) · Automations · Impact · Carbon & ESG (Overview / Breakdown / VSME B3 / Methodology) · Reports · Settings (core) · notifications.
- **Detection:** `AFTER_HOURS`, `IDLE_WASTE`, `NIGHT_CYCLING`, `CONSUMPTION_DRIFT`, `SPIKE`, `DEVICE_OFFLINE`.
- **Optimization:** generators for AfterHoursSchedule, CompressedAirLeak, TouShift and EfficiencyDrift; what-if replay; accept → policy; commands with telemetry verification.
- **Forecast v0** (end of month kWh / € / CO₂ with range) · **M&V** per intervention + company view · **EnergyFlow Score**.
- **Monthly sustainability report:** snapshot + LLM narrative + PDF.
- **Assistant:** scope guard + tools + grounding + fallbacks.
- **EN + SQ** for every demo-path screen and the report.
- **Hosted deploy** (Vercel / Render / Aiven / cron) + **local Docker fallback**.

### Should
- Fingerprint v0 (features, nearest centroid, drift) + model cards
- Bill-variance explainer
- Production output → kWh/unit
- Scope 1 activity data
- ESG readiness checklist
- Dark mode
- Utility bills → coverage
- `PEAK_COINCIDENCE`, `LOW_PF`, `POWER_QUALITY`

### Could
- Fingerprint v1 trained on a public dataset
- Own ESP32 + PZEM node in an enclosure
- *Closing Time* routine
- In-app morning brief
- Carbon exposure (shadow price)
- Email invitations

### Won't (pitch as roadmap)
- NILM disaggregation
- PVGIS rooftop-solar what-if
- Sector benchmarking
- Viber / Telegram alerts
- CBAM module
- **Native mobile app.** The hackathon ships a responsive web app (PWA-capable); a native app comes after the pilot.
- OTA firmware

---

## 2. Team roles (for up to 5 people)

| Role | Owns |
|---|---|
| **Product & pitch lead** | Story, demo script, Albanian copy, report narrative prompts, Stage-4 proposal, Q&A prep, ERO tariff data entry, ARBK name check |
| **Frontend lead** | Design system, app shell, all screens, charts, i18n, PDF |
| **Backend lead** | Slim app, auth, tenancy, ingest, jobs, API, deploy, security |
| **Data / ML** | Simulator profiles, detection rules, baselines, forecast, M&V, fingerprint, `ml/` Python, model cards |
| **IoT / hardware** | hw-bridge, smart plug, ESP32 prototype, Devices pages, device emulator; then helps backend |

With fewer people, merge *Product + Frontend* and *IoT + Backend*.

---

## 3. Week-by-week plan

### Week 1 · 7–13 Oct: Foundations, deployed on day 5
- Monorepo, Docker Compose (php-apache + mysql + vite), CI.
- Slim skeleton: router, error handler, JSON envelope, PDO, migrations runner.
- **Migrations for every table** in [04-database.md](04-database.md); reference seeds (machine types, factor, holidays, tariff template).
- Auth + sessions + CompanyScope + RBAC. Vercel rewrite → Render → Aiven working **end-to-end in production by Sat 11 Oct**.
- Frontend: tokens, fonts, app shell (sidebar, top bar), primitives (Button, Input, Card, StatTile, Table, Badge, Drawer, Modal, Toast, Empty, Skeleton, ErrorCard), i18n wiring, the API client and formatters.
- Data/ML: simulator profiles drafted; public dataset review (HIPE / IMDELD / WHITED: licence and access).
- Hardware: buy or borrow a metering smart plug with a local API; bridge proof-of-concept that prints readings.
- **Definition of done:** you can register, log in and see an empty Overview on the public URL.

### Week 2 · 14–20 Oct: The data spine
- `SimulatorEngine` (stateless, deterministic) + `SimulationClock` + catch-up + `seed-demo.php` (75-day story).
- Ingest pipeline (validation, raw insert, `machine_live`, state events) + device auth/HMAC.
- `rollup_15m`, job runner + `/internal/jobs/run` + cron-job.org. Weather sync (Open-Meteo).
- TariffEngine (TOU seasons, blocks, VAT, fixed) + unit tests. ScheduleService.
- API: `/live`, `/overview`, `/machines`, `/machines/{id}/timeseries`, `/devices`, `/energy/flow`, `/energy/consumption`.
- Screens: **Overview, Live, Machines, Machine detail, Devices, Device detail** on real API data.
- hw-bridge posts real plug readings into the ingest API.
- **Definition of done:** the live screen updates every 5 s from simulator + real plug; Ylli Plast's 75 days of history are browsable.

### Week 3 · 21–27 Oct: Detect → recommend → act
- Detection rules + `waste_events` + `alerts` + SeverityPolicy + notifications.
- Baselines + CUSUM drift. Forecast v0.
- Recommendation generators + what-if engine + accept → policy. PolicyEngine + device commands + **verification by telemetry** (simulated and real plug).
- Screens: **Waste & Alerts** (with evidence drawer), **Opportunities** (+ What-if), **Automations**, notification drawer, Turn Off flow.
- **Definition of done:** the S1 scenario detects after-hours operation, *Turn off* shows verified, the plug physically switches, and a recommendation can be accepted.

### Week 4 · 28 Oct – 3 Nov: Prove and report
- M&V service + `impact` job; Impact screen.
- Carbon ledger, Carbon & ESG screens, VSME B3 mapper, methodology panel.
- Report builder (snapshot) + narrative (Groq) + report web view + **PDF**.
- Assistant: ScopeGuard, ToolRegistry (11 tools), grounding check, fallbacks, side panel UI.
- EnergyFlow Score. Demo Director panel + fast-forward + reset.
- **Golden path runs end-to-end (rough) by Sun 2 Nov.**

### Week 5 · 4–9 Nov: Story, polish, Should-items
- Tune the simulator so the story reads clearly (numbers plausible, not round). Calibrate against the public datasets.
- Should-items in order: Fingerprint v0 → bill variance → kWh/unit → Scope 1 → readiness → dark mode.
- Complete the Albanian translations. Check every empty, loading and error state. Tablet layout. Performance (no request > 800 ms warm).
- Security pass: tenancy tests, rate limits, headers, secrets audit.
- **Feature freeze Sun 9 Nov, 23:59.**

### Final stretch · 10–14 Nov
- **10 full rehearsals** of the golden path on the hosted stack and 2 on the local fallback.
- Record the backup video. Print the one-page hardware and architecture handout.
- Warm-up script. Prepare a hotspot / travel router for the bridge and laptop.
- Bug fixes only.

### Demo Day · Sat 15 Nov
- T−60 min: warm-up, reset demo, set clock, check the plug's connection, run one assistant question.

---

## 4. Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Scope creep / shallow everything | High | High | MoSCoW + weekly cut review; golden path first |
| Render cold start or Aiven power-off on stage | Medium | High | Cron keep-warm, T−60 warm-up, **local Docker fallback** |
| Groq rate limit or outage mid-demo | Medium | Medium | Guard model, fallback model, deterministic answers, optional Developer tier |
| Venue Wi-Fi blocks the plug or laptop | Medium | High | Our own hotspot; simulated twin of the lamp as last resort |
| Simulated data looks fake (too smooth or too random) | Medium | High | Calibrate from public datasets; have someone with factory experience review the charts |
| Wrong tariff numbers | Medium | Medium | Only the official ERO decision; `source_note` required; shown in Settings |
| PHP environment friction on Windows | Medium | Medium | Docker from day 1; one owner for the dev environment |
| ML credibility questions | High | Medium | Model cards, honest method chips, prepared answers |
| LLM states a wrong number live | Low | High | Grounding check, rehearsed questions, deterministic fallbacks |
| Demo company name collides with a real firm | Low | Medium | ARBK check; "fictional" label |

---

## 5. Jury Q&A preparation (short answers to rehearse)

1. **"Is this real data?"** "One node is real and on this table. The factory is simulated because we don't have a pilot yet. The simulator uses real Pristina weather, Kosovo's tariff structure and grid factor, and machine profiles calibrated on public industrial datasets. The software doesn't know the difference: simulated and real data go through the same API."
2. **"How do you measure 3-phase machines? Accuracy?"** "EF-Node 3P uses a 3-phase metering IC with three CTs per load. For pilots, our gateway reads certified DIN-rail meters over Modbus, so accuracy is the meter's class."
3. **"Which emission factor?"** "0.901 kg CO₂e/kWh: Ember 2025 for Kosovo, lifecycle and generation-based. It's configurable and versioned, and the caveats are on the Methodology page."
4. **"How do you know the savings are real?"** "An adjusted baseline per machine, normalised for working days and weather, with a 90 % confidence interval. It's IPMVP-inspired, Option B."
5. **"What if it switches off something critical?"** "Critical machines can never be auto-switched. There are control modes per machine, production overrides, an audit trail, and every command is verified by the meter."
6. **"What is the AI trained on?"** "Each model has a card. Today the forecasts and anomaly detection are explainable statistical models. The fingerprint classifier is trained or validated on public industrial datasets. Pilot data retrains them. The LLM only explains our numbers, and it refuses off-topic questions."
7. **"What does it cost an SME, and what's the payback?"** The pricing hypothesis plus the demo's € per month savings. Mark both clearly as hypotheses until the pilot.
8. **"Why would a Kosovo SME care about ESG?"** "Banks and EU buyers ask for this data. VSME exists precisely for that, and CBAM started in 2026."
9. **"How is this different from a smart meter?"** "A meter measures. EnergyFlow explains, acts, proves the result and reports it."
10. **"Security and data?"** "Tenant isolation, hashed secrets, signed device traffic, and no secrets in the browser."

---

## 6. Decisions needed from the team before coding starts

1. **Backend language:** PHP 8.3 + Slim 4 (recommended) vs Python FastAPI. This depends on the team's fluency.
2. **Frontend:** TypeScript (recommended) or plain JavaScript.
3. **Demo company:** "Ylli Plast Sh.p.k." (fictional plastics manufacturer), or another sector you'd rather show.
4. **Languages:** English + Albanian for the hackathon (Serbian later)?
5. **Hardware on stage:** which metering smart plug can you get in Kosovo this week, and is bringing hardware allowed at the venue?
6. **Team:** size, roles and the **Stage-4 refined-proposal deadline** (10 pages).
7. **Groq:** free tier only, or add a payment method for the Developer tier on demo week?
8. **Local dev:** Docker Desktop (recommended) or native PHP + MySQL.
9. **Repository:** a GitHub (private) monorepo, and who gets access.
