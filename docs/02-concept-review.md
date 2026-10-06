# 02 — Concept Review: weaknesses, improvements, differentiators

The concept is strong. **Measure → Understand → Detect → Predict → Recommend → Act → Prove** is exactly the closed loop that most energy dashboards lack. Below is an honest stress test, written the way an energy expert on the jury would probe it.

---

## 1. What is already strong
- **One data spine for two challenge areas.** The same metered kWh feed cost, waste, forecasts, CO₂ and the ESG report. Area 06 is not bolted on; it is the "report" step of Area 01.
- **Action, not just monitoring.** The "Turn Off" flow and before/after proof go beyond almost every hackathon dashboard.
- **SME framing.** Euros, schedules and plain-language recommendations, not engineering jargon.

---

## 2. Weaknesses and risks (and the fix for each)

| # | Weakness | Why a jury would catch it | Fix (built into the architecture) |
|---|---|---|---|
| W1 | **Scope is very large for ~40 days.** Around 15 product areas, plus hardware, ML, LLM and PDF. | A shallow demo of everything loses to a deep demo of one loop. | Use a strict MVP cut (see [07-roadmap.md](07-roadmap.md)). Do **one golden-path story** perfectly. Every other screen just has to be correct and calm. |
| W2 | **Signature recognition is circular on simulated data.** If our simulator generates the signatures and our classifier recognises them, 99% accuracy proves nothing. | Any ML person on the jury will ask "what was it trained on?" | (a) Train or validate the classifier on **public real industrial datasets** (HIPE, IMDELD, WHITED). (b) Calibrate the simulator's machine profiles *from* those datasets. (c) Show a **model card** with the training data, held-out accuracy and limitations. (d) Present single-point multi-machine disaggregation (NILM) as **roadmap**, not a current claim. |
| W3 | **"AI" overclaiming.** Calling thresholds and rules "AI" erodes trust. | Experts discount the whole product if one claim is inflated. | Label every feature by method: *Rule*, *Statistical model*, *ML model* or *LLM*. A "How this works" popover sits on every insight. Rules are a feature, not an embarrassment. |
| W4 | **LLM hallucinating numbers.** "You saved €312" when the data says €213. | Fatal if spotted live. | The LLM **never computes numbers**. Numbers come from read-only tools. Answers show the data sources used. A numeric-grounding check compares numbers in the answer against tool outputs. |
| W5 | **Before/after can look cherry-picked.** Comparing September to August ignores weather, working days and production volume. | Energy auditors know M&V (measurement and verification) well. | Use an **IPMVP-inspired** method: an *adjusted* baseline (normalised for working days and heating/cooling degree days), computed **per intervention on the affected machine** (sub-metered, Option-B style). The counterfactual is shown explicitly. |
| W6 | **Carbon methodology ambiguity.** Which factor, which year, lifecycle or combustion, location- or market-based. | A sustainability consultant will ask in the first minute. | A versioned `emission_factors` table with source, year and methodology. Default is Ember 2025 for Kosovo. Reports **snapshot** the factor used. A Methodology panel lists the caveats ([01-research.md §4.1](01-research.md#41-grid-emission-factor)). |
| W7 | **Load shifting ≠ carbon reduction in Kosovo.** Moving load to night saves money but barely changes CO₂ on a lignite-baseload grid. | Claiming CO₂ savings from tariff shifting is a factual error. | The recommendation engine tags every action as **€-saving**, **CO₂-saving** or **both**. Shifting is € only. Eliminating waste is both. This honesty becomes a differentiator. |
| W8 | **Automatic shutdown of industrial equipment is a safety and liability issue.** | "What if it switches off the freezer full of stock?" | **Control modes per machine:** Monitor → Notify → Approve → Auto. A `criticality` flag (critical machines can never be auto-switched). Production-override windows, a grace period, an audit log, and **telemetry verification** that the command actually worked. |
| W9 | **The hardware is not credible as described.** Most workshop machines (compressors, injection moulders, CNC) are **3-phase 400 V**. Single-phase hobby sensors don't fit, and mains wiring is dangerous. | The hardware person on the jury will ask about 3-phase, CT clamps, accuracy and certification. | A modular node design: **EF-Node 1P** (single-phase), **EF-Node 3P** (3-phase metering IC + split-core CTs), and **meter-gateway mode** (ESP32 reads a certified DIN-rail meter over Modbus RS-485, the most realistic option for pilots). Non-invasive CTs, electrician installation, and a CE path on the roadmap. |
| W10 | **Free-tier hosting can fail on stage.** Cold start, database powered off, rate-limited LLM, venue Wi-Fi. | A frozen demo costs more points than a missing feature. | Keep-warm cron, a warm-up checklist, a **local fallback stack on the presenting laptop**, a deterministic assistant fallback, and a recorded backup video. |
| W11 | **"All the data is fake" objection.** | "Is anything here real?" | Real items: **1 live hardware node on stage**, the real Kosovo emission factor, real tariff structure and TOU windows, **real Pristina weather** driving HVAC, and real public datasets for ML. Simulated: the factory's machines, clearly labelled *"Simulated device"* in the Devices page. |
| W12 | **No defined user or business model.** | Investors and GPEK both weigh commercial viability. | Personas (owner, production manager, consultant), a hypothesis of hardware kit + per-node subscription, and pilot channels (GPEK SMEs, energy auditors). See §5. |
| W13 | **The language is only English.** | The jury, SME owners and the pilot are in Kosovo. | i18n from day 1. **Albanian + English** in the UI. The assistant answers in the language it is asked (sq / en / sr). |

---

## 3. Improvements to the concept

1. **Reframe signature recognition as "Energy Fingerprint".** This is more valuable and more defensible. Each machine gets a learned reference fingerprint (power levels, duty cycle, cycle period, start-up inrush ratio, power factor). Two uses:
   - **Onboarding auto-identification:** "This node looks like a *screw compressor*, 86% confidence."
   - **Fingerprint drift:** the current week compared with the reference, which reveals wear, leaks and mis-operation weeks before failure.
2. **Compressed-air leak detection.** A compressor that keeps load/unload cycling at night, with no production running, is a classic signature of air leaks. This is a specific, expert-grade insight that comes straight out of the fingerprint data.
3. **A whole-site incomer meter.** EF-Node on the main incomer, plus sub-meters on the machines. *Unmonitored loads = incomer − Σ sub-meters.* This shows up as its own branch in the Energy Flow. It is a data-quality feature energy auditors respect.
4. **Bill reconciliation and coverage.** Enter (or later, upload) KESCO bills. EnergyFlow compares billed and metered kWh and reports "EnergyFlow covers 91% of your billed consumption". Bills from before installation give a free 12-month baseline.
5. **Bill variance explainer.** "Why was our bill higher this month?" is answered by a deterministic decomposition: Δ consumption per machine, Δ day/night mix, Δ working days, Δ heating degree days, and Δ tariff. The assistant then explains that decomposition. It doesn't guess.
6. **Production-normalised intensity.** Enter daily output (units or kg). EnergyFlow shows **kWh per unit** and **kgCO₂e per unit**. This is the metric a factory owner actually understands, and the basis for any future CBAM or product-carbon story.
7. **Scope 1 manual entry.** Diesel for generators and forklifts, and heating oil, are entered as activity data with standard factors. That makes VSME B3 (Scope 1 + Scope 2) complete instead of electricity-only.
8. **Power-quality log.** The node already measures voltage and frequency. Log sags, swells and outages. This gives Kosovo SMEs evidence for distribution operator (KEDS) complaints and context for motor faults.
9. **Kosovo-native tariff intelligence.** Seasonal day/night windows, blocks, and the open-market mode, with savings valued at the marginal rate.

---

## 4. Differentiators, ranked

Ranked by *jury impact × feasibility in 40 days*. **MVP** = built for Demo Day. **Stretch** = only if the MVP is done. **Pitch** = shown as roadmap.

| Rank | Differentiator | What makes it different | Plan |
|---|---|---|---|
| 1 | **Verified closed loop**: detect → quantify (kWh/€/CO₂) → recommend → act → **telemetry confirms** → M&V proves savings | Dashboards stop at "detect". We show the meter confirming the action worked. | **MVP** |
| 2 | **Hardware-in-the-loop "Turn Off"**: one real node on stage with a relay; pressing *Turn Off* in the app physically switches off a lamp | The single most memorable demo moment possible. Proves the IoT path is real. | **MVP** (smart-plug bridge) / Stretch (own ESP32 build) |
| 3 | **"From meter to VSME report in one click"**: auto-filled VSME B3 table + **ESG Readiness** score + methodology + data-quality statement | Turns Area 06 into a working product feature, not a chart | **MVP** |
| 4 | **Explainable everything**: each alert has *evidence* (metric, baseline, observed, deviation, method); each model has a **model card** | Trust from experts; honest about simulated vs real | **MVP** |
| 5 | **Energy Flow (digital energy twin)**: a Sankey from grid → panels → machines → **productive vs waste** → € and CO₂ | The signature visual. Shows *where* energy goes and *how much is wasted* in one picture. | **MVP** |
| 6 | **What-if on your own history**: "What if the compressor turns off after 21:15?" replays your last 30 days under that policy and gives annualised kWh, €, CO₂ and peak kW | A counterfactual built from the SME's own data, not a generic calculator | **MVP** |
| 7 | **Energy Fingerprint + drift** (incl. compressed-air leak signature) | Real predictive-maintenance value from cheap sensors | **MVP** (statistical) / Stretch (trained classifier) |
| 8 | **Honest €-vs-CO₂ tagging** + Kosovo TOU tariff engine | Domain correctness that experts notice | **MVP** |
| 9 | **Grounded, scoped assistant in Albanian and English**: answers from tools, shows sources, refuses off-topic | Not a ChatGPT clone; useful to a non-technical owner | **MVP** |
| 10 | **EnergyFlow Score (0–100)** with a transparent breakdown | One number for the owner, explainable for the expert | **MVP** |
| 11 | **"Closing Time" routine**: at schedule end, "4 machines still running, €3.10/h", with one tap to switch off all non-critical loads | A daily habit that matches how SMEs actually waste energy | **Stretch** |
| 12 | **Live waste meter**: "€2.84 wasted since 21:00 tonight", ticking | Visceral; makes waste tangible | **MVP** (small) |
| 13 | **Morning brief**: a daily summary at 07:30 (yesterday's waste, today's forecast, one action) | Owners won't open dashboards daily; a brief reaches them | **Stretch** (in-app), Pitch (Viber/Telegram) |
| 14 | **Carbon exposure**: € cost + CO₂ priced at a configurable shadow price (e.g. EU ETS reference) | Prepares SMEs for EU carbon pricing pressure (CBAM context) | **Stretch** |
| 15 | Rooftop-solar what-if using EU JRC **PVGIS** yield for the site's coordinates | Very relevant for Kosovo SMEs; real data source | **Pitch** |
| 16 | Anonymous **sector benchmarking** (kWh/unit vs peers) and **green-loan data pack** | Network effect plus financing angle for investors | **Pitch** |

---

## 5. Product positioning

**One-liner (EN):** *EnergyFlow shows Kosovo SMEs where they waste electricity, switches it off safely, proves the savings, and turns the same data into a ready-to-share sustainability report.*

**One-liner (SQ):** *EnergyFlow u tregon NVM-ve në Kosovë ku e humbin energjinë elektrike, e ndal humbjen në mënyrë të sigurt, e dëshmon kursimin dhe i shndërron të njëjtat të dhëna në raport qëndrueshmërie.*

**Why now, why Kosovo (all sourced in [01-research.md](01-research.md)):**
- Kosovo's grid emitted about **901 gCO₂e/kWh in 2025, ~4.3× the EU-27 average**, so every kWh saved matters more here.
- Larger businesses have been **on the open electricity market since June 2025**, facing higher prices. Smaller ones face a **day/night tariff** most don't actively use.
- EU buyers and banks increasingly ask SMEs for sustainability data, which is VSME's stated purpose, and **CBAM** went live in 2026.

**Business model (hypothesis, to validate in the pilot):**
- A hardware kit (EF-Node per machine or panel) sold or leased.
- A monthly SaaS subscription per site.
- A consultant tier for energy auditors managing several SMEs.
- Channel: GPEK's SME network, energy auditors, and equipment installers.

### How the concept maps to GPEK's innovation lens
| GPEK criterion | EnergyFlow evidence |
|---|---|
| New or significantly improved **for Kosovo** | Kosovo tariff engine (TOU and blocks), Kosovo grid factor, Albanian UI, VSME in a Kosovo SME context |
| **Measurable** environmental impact | M&V-verified kWh and CO₂ avoided per action; methodology shown |
| Commercial viability | Hardware + SaaS hypothesis, € savings per month per SME, payback per recommendation |
| Replication and scaling | Same platform for workshops, bakeries, cold storage, retail; per-sector machine templates; pilot-ready hardware path |
