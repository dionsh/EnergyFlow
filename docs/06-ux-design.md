# 06 — UX, Information Architecture and Design System

## 1. Who we design for

| Persona | Context | What they need from EnergyFlow | Main screens |
|---|---|---|---|
| **Owner / general manager** of a 10–50 person SME | Office desktop; phone for alerts. Not an engineer. | "Are we wasting money? What do I do? Can I show the bank or buyer our numbers?" | Overview, Waste, Opportunities, Impact, Reports |
| **Production / maintenance manager** | Tablet on the shop floor | Which machine is on, what's abnormal, switching things off, logging extra shifts | Live, Machines, Waste & Alerts, Automations |
| **Energy / sustainability consultant** | Desktop; serves several SMEs | Method, data quality, M&V, VSME output | Carbon & ESG, Impact, Reports, Methodology |
| **Jury (Demo Day)** | Projector, 5 minutes | A clear story and proof that it's real | The golden path (§6) |

---

## 2. Information architecture

The navigation **mirrors the product loop**: *Monitor → Optimize → Prove*. A first-time user reads the sidebar top to bottom and understands the product.

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│ ◧ EnergyFlow   Ylli Plast · Prishtinë ▾        [ Ask EnergyFlow…  ⌘K ]  🔔3  SQ|EN  ◐  ●│
├──────────────┬───────────────────────────────────────────────────────────────────────┤
│ Overview     │                                                                       │
│              │                                                                       │
│ MONITOR      │                                                                       │
│  Live        │                                                                       │
│  Machines    │                         page content                                  │
│  Devices     │                                                                       │
│              │                                                                       │
│ OPTIMIZE     │                                                                       │
│  Waste & Alerts                                                                      │
│  Opportunities                                                                       │
│  Automations │                                                                       │
│              │                                                                       │
│ PROVE        │                                                                       │
│  Impact      │                                                                       │
│  Carbon & ESG│                                                                       │
│  Reports     │                                                                       │
│              │                                                                       │
│ ──────────── │                                                                       │
│  Settings    │                                                                       │
└──────────────┴───────────────────────────────────────────────────────────────────────┘
```

| EN | SQ | Why it's here (and not where you suggested) |
|---|---|---|
| Overview | Përmbledhje | The one-glance answer to "how are we doing?" |
| Live | Live | Live monitor **and** the Energy Flow visual (live kW mode). The signature screen. |
| Machines | Makineritë | Asset list → machine detail (incl. Fingerprint) |
| Devices | Pajisjet | Hardware is our credibility, so it stays visible rather than buried in Settings |
| Waste & Alerts | Humbjet & Alarmet | Waste events (quantified) + other alerts (offline, over-temperature) as tabs. One inbox for "things wrong". |
| Opportunities | Mundësitë | Recommendations + **What-if simulator**. "Opportunities" sounds like money; "recommendations" sounds like homework. |
| Automations | Automatizimet | Active policies + command log: the "Act" step, auditable |
| Impact | Ndikimi | Before/after with M&V: the "Prove" step |
| Carbon & ESG | Karboni & ESG | CO₂, VSME B3, readiness, methodology |
| Reports | Raportet | Generated, versioned reports + PDF |
| Settings | Cilësimet | Company, sites, users, schedules, tariffs, factors, bills, production output |

- **Notifications** live in the top-bar bell (a drawer), not the nav.
- **Assistant** lives in the top bar (*Ask EnergyFlow*, ⌘K) as a 420 px right-side panel that **knows the current page context**, plus an "Explain" button on alerts and charts. A full-page view exists for long conversations.
- **Demo Director** is a small floating panel (Shift + D) that exists only in the demo company. It never pollutes the nav.
- **Period control** is one row at the top of every analytic page (Today · 7 d · Month · Custom). It is never repeated inside cards.

---

## 3. Screen specifications

Every screen follows the same contract:
- every number has a **unit, a period and a comparison**
- every insight has a **"Why?"** (evidence) and a **method chip** (Rule / Statistical / ML / LLM)
- every list has **empty, loading and error** states.

### 3.1 Overview
1. **KPI row (6 stat tiles):**
   - Now (kW, 1-h sparkline)
   - Today (kWh · € so far)
   - This month (kWh, Δ vs same day last month)
   - Estimated bill (€ P50 with P10–P90 range)
   - CO₂ this month (kg)
   - Verified savings (€ · kg CO₂ this month)
2. **Today vs typical** (8 cols):
   - site kW line over a median band from the baseline
   - out-of-schedule hours shaded as a neutral wash
   - night-tariff window labelled on the x-axis band.

   **EnergyFlow Score** card (4 cols): score, Δ vs last week, and the one sub-score to fix next.
3. **Energy Flow this month** (Sankey, 8 cols: grid → machines → productive / waste, with € and CO₂ totals). **Top 3 opportunities** (4 cols: €/month + tag chip).
4. **Open alerts** (top 3) · **Running now** (compact table) · **End-of-month forecast** (actual MTD bar + forecast remainder with range).

### 3.2 Live
- **Hero:** site power now (≥ 48 px figure), "Updated 3 s ago", and the **live waste meter** "€0.89 wasted since 21:00 · 6.8 kg CO₂".
- **Live Energy Flow:**
  - Grid → EF nodes → machine tiles
  - link thickness ∝ kW
  - each tile shows kW, a state dot + label, and an *after hours* flag
  - flowing-dash motion only on running links, disabled under `prefers-reduced-motion`.
- **Machine table:** Machine · State · kW · PF · Temp · Today kWh · Schedule (in/out) · Anomaly · **Turn off**.
- **Event ticker:** state changes, commands and verifications in the last 30 min.

### 3.3 Machines → Machine detail
- **Header:** name, code, type icon, state badge, control mode, **Turn off** (disabled for critical machines, with the reason shown).
- **Stat tiles:** Now kW · Today kWh · Month kWh · Month € · Month CO₂ · Efficiency (kWh per running hour vs baseline) · Anomaly status.
- **"What EnergyFlow sees"** card:
  - deterministic sentences, e.g. *"Energy per running hour is 11 % above its 28-day baseline (Statistical · CUSUM)"*
  - *"Ran 2 h 40 min outside schedule this week (Rule)"*
  - plus **Explain** (LLM).
- **Tabs:**
  - **Power** (24 h line + baseline band + schedule shading)
  - **Energy & cost** (daily stacked bars, high vs low tariff)
  - **CO₂**
  - **Operating hours** (in- vs out-of-schedule hours per day)
  - **Fingerprint** (reference vs current feature strips, drift score, predicted type with top-3 and a model-card link)
  - **Events**
- **No dual axes.** Power and temperature are two stacked small charts sharing the x-axis.

### 3.4 Waste & Alerts
- **Summary row:**
  - waste this month (kWh · € · CO₂ · % of total)
  - waste by type (horizontal bars)
  - by machine (horizontal bars)
  - Δ vs last month.
- **Waste table:** Machine · Type · When · Duration · kWh · € · CO₂ · Severity · Suggested action · Status.

  A row opens a **480 px drawer** containing:
  - an episode chart with the schedule boundary marked
  - the evidence block
  - the method
  - actions (Turn off / Create policy / Dismiss with reason).
- **Alerts tab:** open / acknowledged / resolved, with filters in one row above the table.

### 3.5 Opportunities (recommendations + what-if)
- **Ranked cards:**
  - priority band, title, machine
  - **€/month · kWh/month · CO₂/month**
  - tag chip (`€ + CO₂` / `€ only` / `Peak`)
  - effort, confidence, payback
  - actions: **Simulate · Accept · Dismiss**.
- **Detail:** evidence, the 30-day **replay chart** (actual vs scenario), assumptions in plain language, and *"Accepting will create: Auto-off CMP-01 at 21:15, mode Approve"*.
- **What-if simulator:** machine + action + parameters → instant result. The result shows a dumbbell for kWh, € and CO₂ (before → after) and the annualised totals.

### 3.6 Automations
- **Policies table:** Machine · Rule · Mode · Since · Times triggered · Verified savings · Toggle.
- **Command log:** Time · Machine · Command · Source (user / policy) · Status timeline `queued → sent → executed → verified (12 s)`.

### 3.7 Impact (before/after proof)
- **Hero comparison:** *Before* (baseline period) vs *After* (reporting period) for Energy, Cost and CO₂, plus a delta row (*13.0 % less energy · €250 · X kg CO₂e avoided*). Toggle between **Adjusted (recommended)** and **Raw**.
- **Daily kWh chart:** an intervention marker line, actual consumption, and the adjusted-baseline line (neutral gray, labelled).
- **Interventions table:** savings ± 90 % CI and *Verified* / *Collecting data (4/7 days)*.
- A **"How we calculate this"** panel: IPMVP-inspired, Option B, adjustments listed.

### 3.8 Carbon & ESG
Tabs:
- **Overview:**
  - CO₂e month / YTD
  - intensity (kg/unit, kg/€1k turnover)
  - verified avoided emissions
  - monthly trend vs last year
  - a **factor chip** `0.901 kgCO₂e/kWh · Ember 2025 · lifecycle` that opens Methodology.
- **Breakdown:** by machine, by department, and by tariff period. The tariff-period view visibly shows that shifting load doesn't cut CO₂.
- **VSME B3:** a datapoint table with value, unit, a status chip (`Auto` / `Manual` / `Estimated` / `Missing`) and the source.
- **Readiness:** a segmented progress bar + checklist + "next 3 steps".
- **Methodology:** the factor and its caveats, scope definitions, M&V method, data coverage, and a simulated-data disclosure in demo mode.

### 3.9 Reports
List → **New report** modal (type, period, language) → A4 web view → **Download PDF** / **Finalize**.

The report reads as a narrative, not a chart dump:
1. Cover (company, period, "Demo company (fictional)" when applicable)
2. **Executive summary** (LLM narrative over frozen numbers, editable)
3. **Key figures:**
   - total energy, cost and CO₂e
   - waste
   - verified savings
   - top consumer
   - largest waste event
   - number of recommendations
4. **Where energy went** (by machine; simplified flow)
5. **Where waste occurred and why**
6. **Actions taken and verified impact**
7. **Carbon:** Scope 1 / 2, intensity, factor used
8. **Compared with the previous period**
9. **Recommendations for next period** (€, CO₂, effort)
10. **VSME B3 table**
11. **Methodology and data quality** (coverage %, factor, M&V, simulated-data notice)

### 3.10 Devices
- **Fleet table:** Serial · Model · Site · ● Online · "last reading 2 s ago" · Signal · Channels → machines · Firmware · `Simulated` badge.
- **Device detail:**
  - a per-channel electrical panel: V, I, P, f, PF, °C, each with a mini-trend
  - transmission stats (packets/min, buffered, last error)
  - channel mapping
  - command queue.

### 3.11 Settings and onboarding
**Onboarding wizard for a new company:**
1. Company (B1 fields)
2. Site
3. Working hours (weekly grid)
4. Tariff (pick the KESCO template and confirm the rates)
5. Add first device, **or "Explore the demo company"**.

Every empty state points to the next step.

---

## 4. Design system

### 4.1 Principles
1. **Numbers first, decoration never.** If an element doesn't help a manager decide, remove it.
2. **Every number carries its unit, period and comparison.**
3. **Colour carries meaning, not mood.** Status colours only for status. Brand green only for primary actions and brand.
4. **Explain on demand.** "Why?" and "How is this calculated?" are always one click away and never in the way.
5. **Calm by default, loud only when it matters.** Only critical things are red.

### 4.2 Colour tokens (UI chrome)

| Token | Light | Dark | Use |
|---|---|---|---|
| `--bg` | `#F6F7F5` | `#0E1412` | Page plane |
| `--surface` | `#FFFFFF` | `#151C19` | Cards, tables, **chart surface** |
| `--surface-2` | `#F0F2EF` | `#1B2420` | Hover, selected rows, subtle fills |
| `--border` | `#E2E5E1` | `#26302B` | Hairlines |
| `--border-strong` | `#CDD2CC` | `#34403A` | Inputs, dividers |
| `--text` | `#14201B` | `#E7ECE9` | Primary ink |
| `--text-2` | `#4A5751` | `#A9B5AF` | Secondary |
| `--text-3` | `#78837D` | `#7C8882` | Muted, axis labels |
| `--brand` | `#0F7B5A` | `#3FB984` | Primary buttons, active nav, links |
| `--brand-hover` | `#0B6649` | `#5CC99A` | |
| `--brand-subtle` | `#E6F2EC` | `#12291F` | Selected state, `€ + CO₂` chip |

**Status (reserved; always with an icon + label):**

| Role | Fill | Text on subtle (light / dark) | Subtle bg (light / dark) |
|---|---|---|---|
| Good / achievement | `#0CA30C` | `#006300` / `#0CA30C` | `#E5F5E5` / `#10301A` |
| Warning | `#FAB219` | `#8A5A00` / `#FAB219` | `#FEF3D9` / `#3A2C0E` |
| Critical | `#D03B3B` | `#A32020` / `#F08A8A` | `#FCEBEA` / `#3A1A1A` |
| Info / insight | `#1C5CAB` | `#1C5CAB` / `#86B6EF` | `#E6EEF8` / `#14243A` |

### 4.3 Data-visualisation palette (validated)

**Machine series** (identity), in this **fixed order**. A machine keeps its slot everywhere and is never recoloured by rank or filter.

| Slot | Hue | Light | Dark |
|---|---|---|---|
| 1 | blue | `#2A78D6` | `#3987E5` |
| 2 | orange | `#EB6834` | `#D95926` |
| 3 | aqua | `#1BAF7A` | `#199E70` |
| 4 | violet | `#4A3AA7` | `#9085E9` |
| 5 | magenta | `#E87BA4` | `#D55181` |
| Other | gray | `#9A9893` | `#6F6E69` |

**Validated** with the dataviz validator against our surfaces (`#FFFFFF` light, `#151C19` dark):
- **Light mode passes:** lightness band, chroma floor, CVD separation (worst adjacent ΔE 9.2) and normal-vision floor (worst 27.6).
- **Dark mode passes the same checks:** CVD separation ΔE 9.4, normal-vision floor 19.7, contrast ≥ 3:1.
- **Light mode warns on contrast.** Aqua (2.8:1) and magenta (2.7:1) sit below 3:1, which **requires** visible legends or direct labels and a "View as table" toggle. Both are part of every chart anyway.

**Rules:**
- More than 5 machines in one chart shows the **top 5 + Other** (or small multiples). Never a 6th generated hue.
- Status hues (green, amber, red) are **not** in the series palette, so "red = machine 4" can never be mistaken for "red = critical".
- **Waste** in the Sankey and stacked bars is a *category with a direct label "Waste"*, drawn in the critical hue at reduced opacity, with a 45° texture available in high-contrast or print mode.
- **Single-series charts** (site power) use slot 1. Baselines and adjusted baselines are **neutral gray lines with direct labels**. Shaded context bands (out-of-schedule, night tariff) are neutral washes with labels, never a hue.
- **Sequential** (load-profile heatmap) uses one blue ramp, light → dark. **Diverging** (Δ vs baseline) is blue ↔ red with a gray midpoint.

### 4.4 Typography
- **IBM Plex Sans** (UI + figures) and **IBM Plex Mono** (device serials, codes, API keys). Both are technical and calm, and cover Albanian diacritics (ë, ç). Served from Google Fonts with `font-display: swap`.
- **Scale (px):** 12 meta · 13 table · **14 body** · 16 section · 20 page title · 24 / 32 stat values · **48 hero figure** (Live kW).
- **Weights:** 400 / 500 / 600. Line height 1.45 for body text, 1.2 for headings. Sentence case everywhere; no all-caps except the 11 px nav group labels.
- **Figures:** proportional for stat tiles and the hero figure; **`tabular-nums` for table columns and axis ticks** so they align.

### 4.5 Spacing, shape, elevation
- 4 px base unit. The scale is 4 · 8 · 12 · 16 · 24 · 32 · 48.
- Page gutter: 24 px on desktop, 16 px on tablet and mobile. 12-column grid, content max-width 1440.
- **Radius:** 4 px (buttons, inputs, badges, chips) · 6 px (cards, tables) · 8 px (modals, drawers). Fully round only for status dots.
- **Elevation:** cards are flat with a 1 px border. Shadows appear **only on overlays** (menus, drawers, modals): `0 8px 24px rgb(16 24 20 / 0.12)`. No glass, no glow, no gradients.

### 4.6 Components

| Component | Spec |
|---|---|
| **Button** | Heights 32 / 36 / 40. Variants: **primary** (brand fill), **secondary** (border-strong outline), **ghost**, **danger** (critical outline → fill on confirm). The icon sits left, 16 px. Loading state shows a spinner in the button, never full-page. |
| **Input / select** | 36 px, label above, helper text below. Errors show critical text with an icon. Units appear as suffixes (`kW`, `€/kWh`). |
| **Card** | Header: title (16/600) + period chip + "?" info. Body. Optional footer link ("View all →"). |
| **Stat tile** | Label (13/500, text-2) → value (24–32/600, proportional) + unit (14, text-2) → delta ("▲ 4.2 % vs last month") coloured semantically. For consumption, ▲ is bad and uses critical ink + icon. → optional sparkline (2 px, slot 1). |
| **Table** | Rows 40 px (compact 32). Sticky header with **units in the header** ("Energy (kWh)"). Numbers right-aligned with tabular figures. No zebra stripes; hover uses surface-2. Row actions appear on hover. |
| **Badge** | Severity (`● Critical`, `▲ Warning`, `i Insight`, `✓ Achievement`). State (`● Running`, `◐ Idle`, `○ Off`, `⚠ Abnormal`). **Method chip** (`Rule`, `Statistical`, `ML`, `LLM`, neutral outline). **Impact tag** (`€ + CO₂`, `€ only`, `Peak`). |
| **Callout / alert** | 3 px semantic left border + subtle background + icon + title + one-sentence body + actions. |
| **Navigation** | 240 px sidebar, collapsing to a 64 px icon rail below 1280 px. Active item: surface-2 + 2 px brand bar. Top bar is 56 px. |
| **Modal** | 480 / 640 px. Consequential confirms state the impact: *"Turn off CMP-01? Compressed air will stop for the whole hall. Production schedule ended 21:00."* |
| **Drawer** | 480 px right panel for details (waste event, alert, command). |
| **Toast** | Bottom-right, 4 s: *"Command sent · verified by meter in 12 s"*. |
| **Empty state** | Line icon + title + one sentence on why it's empty + primary action ("Add your first device") + secondary ("Explore demo company"). |
| **Loading** | Skeletons that match the layout on **first load only**. **Background polling never shows a skeleton flash**; it updates in place. |
| **Error** | Inline card: "Couldn't load consumption data" + Retry + error code. Global offline banner. A **stale-data banner** when live data is older than 30 s. |

### 4.7 Chart grammar
- Recharts for standard charts; custom SVG for the Sankey (d3-sankey layout) and the live flow.
- **Marks:**
  - 2 px lines; bars with 4 px rounded data-ends anchored to the baseline
  - a 2 px surface gap between stacked segments
  - markers ≥ 8 px
  - **one y-axis per chart** (never dual-axis).
- **Chrome:** horizontal hairline gridlines only (solid, `--border`). Axis labels 12 px in text-3. Legend always shown for ≥ 2 series, plus direct labels for ≤ 4.
- **Annotations:** vertical marker lines for interventions ("Policy applied · 3 Nov"), labelled neutral bands for out-of-schedule and night-tariff windows, and a baseline band (median ± MAD) in neutral gray.
- **Hover:** crosshair + tooltip on line and area charts; per-mark tooltip on bars. Tooltips show value + unit + timestamp + comparison. Hit targets are larger than the marks.
- **Accessibility:** every chart has **"View as table"**. Identity is never colour-alone.
- **Formatting** (`Intl.NumberFormat` with the active locale, `sq` or `en`):

  | Quantity | Format |
  |---|---|
  | kW | 1 decimal (2 if < 1) |
  | kWh | 0 decimals when ≥ 100, 1 decimal when < 100 |
  | € | 2 decimals when < 1,000; 0 decimals in KPIs when ≥ 1,000 |
  | CO₂ | kg below 1,000; t (1 decimal) at or above 1,000 |
  | Dates | `dd.MM.yyyy` |
  | Time | 24 h |

### 4.8 Icons, motion, voice
- **Icons:** Lucide, 1.5 px stroke, 16 / 20 px. Machine-type icon map: compressor → `wind`, moulder → `factory`, HVAC → `thermometer`, chiller → `snowflake`, pump → `droplets`, lighting → `lightbulb`, office → `monitor`, device → `cpu`.
- **Motion:** 150–200 ms ease-out (hover, drawers). The live kW value tweens over 300 ms. No counting-up animations. Everything respects `prefers-reduced-motion`.
- **Voice:** plain and specific, euros first.
  - ✅ *"The compressor ran 2 h 40 min after closing on Tuesday. That cost €3.10 and 9.5 kg CO₂."*
  - ❌ *"AI detected an anomalous energy pattern."*

### 4.9 Responsive and presentation
- ≥ 1280: full layout. 1024–1279: collapsible sidebar. 768–1023 (tablet): icon rail, 2–3 column stat grid, tables keep their priority columns. ≥ 360 (phone): bottom bar with Overview · Live · Alerts · Ask, read-mostly.
- **Demo Day:** present in **light mode** (projectors wash out dark UIs). Test at 1280×720 and 1920×1080. A *presentation zoom* (110 %) toggle in the Demo Director.
- **Accessibility:** WCAG AA text contrast; visible focus rings (`--brand`, 2 px offset); full keyboard navigation; status never colour-alone.

---

## 5. Theming
Light is the default. Dark is **selected, not inverted**: every token above has a dark value validated against the dark surface. The toggle is stored per user (localStorage) and defaults to the OS setting.

---

## 6. Demo Day golden path (≈ 5 min, one continuous story)

| Time | Screen | What the jury sees | Line |
|---|---|---|---|
| 0:00 | Title | Problem: SMEs see their energy once a month, on the bill | "Kosovo's grid is ~4.3× more carbon-intensive than the EU average. Every wasted kWh here costs more, in € and CO₂." |
| 0:30 | **Overview** | Ylli Plast (fictional) · Score 64 · month forecast · CO₂ | "This is a real-time view of a 28-person plastics plant in Pristina." |
| 1:00 | **Live** (clock 21:40) | Energy Flow; compressor still on after the 21:00 shift end; waste meter ticking | "The shift ended 40 minutes ago. The compressor didn't." |
| 1:30 | Waste drawer | Evidence: night load/unload cycling → **compressed-air leak signature**; € and CO₂ tonight | "It's not just left on. Its cycling pattern says the air network is leaking." |
| 2:00 | **Turn off** | Confirm modal → command → *verified by meter in 10 s*; **the lamp on stage switches off** | "EnergyFlow doesn't just tell you. It acts, safely, and the meter confirms it." |
| 2:30 | Opportunities | Auto-off policy: €/month, kWh, CO₂ from **replaying the last 30 days**; *Accept* | "Every number comes from this plant's own history." |
| 3:00 | Demo Director → +7 days → **Impact** | Before/after, adjusted for working days and weather; verified savings; Score 64 → 71 | "Measured, not promised." |
| 3:30 | **Assistant** | "Why was our bill higher in October?" → grounded answer + sources · "Did Messi win yesterday?" → polite refusal | "It only knows your energy, and it shows its sources." |
| 4:10 | **Carbon & ESG → Report** | VSME B3 auto-filled, readiness 72 %, methodology; PDF opens | "The same data becomes the sustainability report banks and EU buyers ask for." |
| 4:40 | Devices + close | Real node vs simulated nodes; hardware path; pilot ask | "One real node today. A pilot in your factory next month." |
