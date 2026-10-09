# EnergyFlow — Planning Documents

**Status (8 Oct 2026):** the full loop is implemented: measure → detect waste → recommend (what-if) → act (Turn Off, automations) → prove (M&V) → report (carbon, VSME B3, PDF), plus the Ask EnergyFlow assistant and the hardware bridge. See the [root README](../README.md#whats-built) and the decisions log below. These documents remain the design reference; where the implementation differs, the decisions log says so.

**Target:** GPEK Digital Green Innovation Competition 2026 · Challenge Areas **01** (energy efficiency and real-time monitoring) + **06** (carbon tracking and ESG reporting) · **Demo Day 15 Nov 2026**.

> *EnergyFlow shows Kosovo SMEs where they waste electricity, switches it off safely, proves the savings, and turns the same data into a ready-to-share sustainability report.*

## Reading order

| # | Document | Answers |
|---|---|---|
| 01 | [Research](01-research.md) | What GPEK rewards; whether the free stack works; what Groq can and can't do; Kosovo emission factor, tariffs, VSME, CBAM (all sourced) |
| 02 | [Concept review](02-concept-review.md) | 13 weaknesses and their fixes; improvements; 16 ranked differentiators; positioning |
| 03 | [Architecture](03-architecture.md) | Hardware family and protocol; deterministic simulator; pipeline without background workers; detection; ML; scoped assistant; actions and safety; M&V; carbon and ESG; security; deployment |
| 04 | [Database](04-database.md) | Full MySQL schema (≈ 40 tables), retention, query paths |
| 05 | [API](05-api.md) | Every endpoint, conventions, device protocol, example payloads |
| 06 | [UX and design system](06-ux-design.md) | Personas, navigation (Monitor → Optimize → Prove), screen specs, tokens, validated chart palette, components, the 5-minute golden path |
| 07 | [Roadmap](07-roadmap.md) | MVP cut, week-by-week plan to 15 Nov, risks, jury Q&A, open decisions |

## Key decisions at a glance

| Topic | Decision |
|---|---|
| Stack | **React (JavaScript) + Tailwind (Vercel) · plain PHP 8.4 + PDO in Docker (Render) · MySQL 8 (Aiven)**. All free; verified against current limits. Same hosting pattern as VenueSphere/SproutSync. |
| No background workers on Render free | **Stateless deterministic simulator** + catch-up on request + external cron calling a job endpoint |
| 1 GB database | Raw 10-second readings kept for 72 h; analytics on 15-minute rollups |
| Real time | Polling (5 s live, 30 s overview) with "updated X s ago" |
| Auth | DB-backed session cookie, same-origin via Vercel rewrite (no CORS, no third-party cookies) |
| ML vs LLM | Our own explainable models (fingerprint, anomaly, forecast) with model cards. **Groq LLM only explains, answers and narrates** over tool data. |
| Assistant scope | Built like DS Banking's NOVA: knowledge base + strict EnergyFlow-only system prompt with an exact refusal ("Did Messi win?") + deterministic answers for data questions (EN/SQ keyword intents, straight from MySQL) + a real energy-data snapshot injected for analysis questions. Groq key in Render env only. Read-only tools are the upgrade path. |
| Grid emission factor | **0.901 kgCO₂e/kWh**: Ember 2025, Kosovo (lifecycle, generation-based), configurable and versioned |
| Tariffs | Kosovo two-rate TOU (seasonal windows) + monthly blocks + open-market mode; values only from the official ERO decision |
| ESG output | VSME Basic Module **B3** table auto-filled from metered data + readiness score |
| Proof of impact | IPMVP-inspired adjusted baseline per intervention, with a 90 % CI |
| Hardware on stage | One real metering smart plug via a bridge (safe), giving a **real Turn Off**; ESP32 node as a stretch |
| Languages | Albanian + English now; German and French later (adding a language = one JSON file + one config line) |

## Decisions log

**7 Oct 2026, confirmed by the team:**
- **Backend:** plain PHP with PDO and no framework, with the folder structure from the brief (`config/ Controllers/ Models/ Services/ Middleware/ Utils/`). This supersedes "Slim 4" in 03-architecture.
- **Frontend:** React **JavaScript** (not TypeScript) + Tailwind CSS.
- **Hosting:** Vercel + Render + Aiven, as in VenueSphere and SproutSync. Repo: https://github.com/dionsh/EnergyFlow (the team pushes).
- **Brand:** the product, company and website name is **EnergyFlow**. The demo SME stays a clearly labelled fictional customer.
- **Hardware:** a prototype only, in the same spirit as SproutSync. The team will provide a reference image.
- **AI:** "trained" the DS Banking way: a knowledge base, a strict scope prompt, deterministic data intents and a data snapshot, on Groq's free tier.
- **Local toolchain:** Laravel Herd PHP 8.4 + Composer, and XAMPP MariaDB 10.4 for the local database. Docker Desktop is not used: this machine has no admin rights, WSL2 or hypervisor, and Render builds the image itself.

**8 Oct 2026, made during implementation:**
- **Groq models:** `openai/gpt-oss-120b`, falling back to `openai/gpt-oss-20b` (both with low reasoning effort), set in env `GROQ_MODEL` / `GROQ_MODEL_FALLBACK`. `llama-3.3-70b-versatile` is not available to this key (404).
- **Assistant pipeline** (03-architecture §9): no separate guard model. Off-topic questions are refused by a keyword check and by the main model (tagged `SCOPE: out`). Data questions are answered deterministically. Open questions get a data snapshot with **only the sections the question needs**, which keeps prompts at about 3–4K tokens, inside the free tier's 8K tokens/minute. The model replies in a tagged plain-text format (not JSON mode, whose strict validation rejected markdown answers).
- **Grounding:** every number, and every percentage however small, must be in the data or the knowledge base. Derived differences, sums and % changes are accepted only between numbers that belong together (the same object, or the same field across a list). Otherwise one retry, then the answer is marked "not fully verified".
- **Assistant actions:** the assistant never acts. A Turn Off is a card; **Yes** calls the same `POST /machines/{id}/commands` as the Live button. The demo's shared guest account keeps conversations per browser session.
- **Turn Off and automations:** policies act through the same command loop as people, verified only by meter telemetry. Simulated history commands are labelled.
- **PDF reports:** the browser's print engine (A4 `@page` CSS, "Save as PDF"), not a PDF library: vector text, same fonts, no extra dependency.
- **Scope 1:** no fuel factors are seeded until DEFRA/DESNZ 2025 values are verified. Scope 1 shows "not reported" or "declared none", never an estimate.
- **Frontend bundle:** every page is its own chunk, libraries are in named vendor chunks, and the chart library loads only with chart pages. Initial load went from 1,040 kB (300 kB gzip) to about 500 kB (159 kB gzip), with idle prefetch of the main pages.
- **Assistant panel layout:** docked beside the page from 1536 px wide; narrower, it overlays the right side, so page layouts aren't squeezed.

**9 Oct 2026, made during implementation:**
- **Scan (camera):** `qwen/qwen3.8-27b` on Groq reads the photo into fields (JSON); env `GROQ_VISION_MODEL`. Photos are compressed in the browser (≤ 1,400 px, < 900 kB), sent once, and never stored. The user confirms every field, and every check is deterministic code, not the model. A bill is **likely fake** with ≥ 2 integrity failures (totals, kWh sum, VAT, future date), or 1 plus a supplier missing from the ERO licence list; otherwise **check** if anything failed or needs a look, else **consistent**. VAT on electricity is 8 % (Law 05/L-037). The camera needs `Permissions-Policy: camera=(self)` (set in `frontend/vercel.json`).
- **Scope 1 factors:** the DESNZ/DEFRA 2025 flat-file values for diesel, petrol, gas oil, LPG and heating oil (100 % mineral) were verified and seeded (migration 0014), with energy content from the same file. This supersedes the 8 Oct note.
- **EnergyFlow Score:** six parts (waste 35 %, schedule 20 %, health 15 %, peak 10 %, follow-through 10 %, coverage 10 %) over 7 days, compared with the 7 days before; scales in `backend/config/scoring.php`. The weights and scales are EnergyFlow's design, not an industry standard, and the UI says so.
- **Forecast range:** P10–P90 from a 14-day backtest of the same day-type method (normal approximation, error scaled by √days left). The typical error is reported as WAPE (Σ|error| ÷ Σactual), because MAPE was inflated by small Sunday totals.
- **Energy flow:** a hand-drawn SVG Sankey (no chart library), from the same metered kWh and quantified waste as the rest of the app.
- **SPIKE:** the reference uses only buckets where the machine was **running**, per day type × hour (with all-hours fallbacks). An hour-of-week median that includes off hours is 0 and would flag every after-hours start, which is the AFTER_HOURS detector's job. 48 simulated hours of normal operation gave a highest z of 4.6 and no alerts. The Demo Director's injected fault is a sustained overload (a share of the nameplate rating while the machine is on), not a multiplier: a cycling compressor's unloaded minutes would hide a multiplied spike.
