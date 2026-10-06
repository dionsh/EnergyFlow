# 01 — Research

Researched 6 October 2026. Every external fact below has a source link. Items marked **VERIFY** must be confirmed against the primary source before being shown in the product or the pitch.

---

## 1. The competition

**Digital Green Innovation Competition 2026.** Run by Universum College for **GPEK (Greening Private Enterprises in Kosovo)**. GPEK is a project of the Swiss Agency for Development and Cooperation (SDC), co-funded by Sweden and implemented by Helvetas. ([GPEK site](https://gpek.universum-fr.org/), [Helvetas GPEK PDF](https://www.helvetas.org/Publications-PDFs/Eastern-Europe-Caucasus/Kosovo/GPEK/Challenge-fund/English/What%20is%20Considered%20Innovation%20for%20The%20Challenge%20Fund.pdf))

### Timeline (from the [program page](https://gpek.universum-fr.org/sq/program))

| Stage | What happens |
|---|---|
| 1. Initial concept | Short concept submitted online (applications open 10 Aug, close **30 Sep 2026**) |
| 2. Eligibility | Committee selects **30** applicants |
| 3. Capacity building | Workshops, mentoring, business coaching |
| 4. Refined proposal | Standard template, **max. 10 pages** |
| 5. Technical assessment | Committee selects **5 finalists** |
| 6. Mentoring & pitching | Coaching for the final presentation |
| 7. Final hackathon | **Hackathon & Demo Day: 15 November 2026.** Live presentation to a jury |

> **Today is 6 Oct 2026, so we have about 40 days to Demo Day.** This drives the whole roadmap ([07-roadmap.md](07-roadmap.md)). We also need to know when the 10-page refined proposal (Stage 4) is due, because this documentation can feed it directly.

### Rules that matter to us ([FAQ](https://gpek.universum-fr.org/sq/faq))
- Teams of up to **5 people**. Students, startups, freelancers, young SMEs.
- Languages: **Albanian, English, Serbian**.
- A prototype is *not mandatory*, but "helps".
- Prizes: 1st place gets a 3-year full scholarship plus €750 (team members get 50% scholarships). 2nd gets a 50% scholarship plus €500. 3rd gets a 40% scholarship plus €250. Winners also get **6 months of mentoring and pilot access with Kosovo SMEs**.

**Implication:** the real prize is the **pilot with real SMEs**. The prototype has to make a jury believe that EnergyFlow could be installed in a real factory next month. That favours a credible hardware path and honest AI claims over flashy features.

### Our two challenge areas (from the [site](https://gpek.universum-fr.org/))
- **01 — Efiçienca e energjisë dhe monitorimi në kohë reale:** *"Zgjidhje digjitale për matje, optimizim dhe raportim të konsumit të energjisë në NVM."* (Digital solutions for measuring, optimising and reporting SME energy consumption.)
- **06 — Pajtueshmëria mjedisore, gjurmimi i karbonit dhe raportimi:** *"Softuer për matje CO₂, raportim rregullator dhe standardet ESG për NVM."* (Software for CO₂ measurement, regulatory reporting and ESG standards for SMEs.)

The verbs in Area 01 are **measure → optimise → report**. Area 06 picks up exactly where "report" ends. That is the natural seam where the two areas join: **the same metered kWh feed both the savings engine and the carbon/ESG engine.**

### Judging criteria
**Not published** on the site or in the FAQ. The best proxy is GPEK's own definition of innovation ([Helvetas PDF](https://www.helvetas.org/Publications-PDFs/Eastern-Europe-Caucasus/Kosovo/GPEK/Challenge-fund/English/What%20is%20Considered%20Innovation%20for%20The%20Challenge%20Fund.pdf)):

- Innovation does **not** have to be globally new. It must be **new or significantly improved for the Kosovo market**.
- It must show **measurable environmental impact**, **commercial viability** and **wider adoption / replication** potential.
- "High innovation" is described as *among the first of its kind in Kosovo, with strong potential for replication, scaling and systemic impact*.

**Action items for the team:**
1. Ask the organisers for the final jury rubric, pitch and demo length, and the screen/projector setup.
2. Ask whether the venue has reliable internet.
3. Ask whether we may bring hardware on stage.

---

## 2. Hosting: is React + Tailwind + PHP + MySQL on Vercel / Render / Aiven practical for free?

| Service | Free-tier facts (current docs) | Consequence for EnergyFlow | Mitigation |
|---|---|---|---|
| **Vercel Hobby** ([docs](https://vercel.com/docs/plans/hobby)) | Free. **Non-commercial, personal use only.** 100 GB fast data transfer, 1M CDN requests, 1M function invocations. No PHP runtime needed (we only serve a static React build). | Perfect for the React SPA. Can proxy `/api/*` to Render with an external rewrite, making the API **same-origin**. | Move to Pro or Cloudflare Pages before any commercial pilot. |
| **Render Free web service** ([docs](https://render.com/docs/free)) | **Spins down after 15 min without traffic, with ~1 min cold start.** 750 free instance-hours per workspace per month. **No background workers or cron jobs** on free. No persistent disk. Outbound SMTP ports 25/465/587 blocked. PHP is not a native runtime, so it **runs via Docker**. Small instance (512 MB / 0.1 CPU per pricing page, **VERIFY**). | A simulator that runs constantly on the server is **impossible**. Heavy analytics per request will be slow on 0.1 CPU. Email has to go through an HTTP email API, not SMTP. | **Stateless, deterministic simulator** plus *catch-up on request* and an external free cron that calls a job endpoint (also keeps the service warm). Precompute analytics in jobs. Details in [03-architecture.md](03-architecture.md). |
| **Aiven Free MySQL** ([docs](https://aiven.io/docs/platform/concepts/free-plan), [MySQL free tier](https://aiven.io/docs/products/mysql/concepts/mysql-free-tier)) | **1 CPU, 1 GB RAM, 1 GB disk.** No time limit, no card. May be **powered off after inactivity** (with notice). One free service per type. No static IP. No region choice guaranteed. | 1 GB disk rules out keeping 10-second raw readings forever. | Raw readings are kept for **72 h**, then rolled up to 15-minute buckets (≈ 40 MB/year for the demo company; see [03-architecture.md §5.3](03-architecture.md#53-retention-and-storage-fits-aivens-1-gb)). The cron keeps the database active. |
| **Groq Free** ([rate limits](https://console.groq.com/docs/rate-limits)) | `openai/gpt-oss-120b`: 30 req/min, **1,000 req/day, 8K tokens/min**, 200K tokens/day. `gpt-oss-20b` has the same figures. Limits are per organisation, per model. | A data-grounded assistant uses roughly 3–6K tokens per question, so **about 1–2 questions per minute** on the free tier. This is a real demo-day risk. | A cheap guard model screens questions first. Tool outputs are compact. A fallback model has its own separate limit. Deterministic fallback answers. Optionally Groq's paid Developer tier for demo day, at a few cents of usage. |

### Verdict
**The proposed stack is practical and free, with four architectural accommodations:**
1. No background processes. Use a deterministic simulator plus a job endpoint triggered by an external cron.
2. A data retention and rollup strategy to fit within 1 GB.
3. Same-origin API through Vercel rewrites. This avoids third-party-cookie problems between `*.vercel.app` and `*.onrender.com`.
4. A token-efficient assistant design.

None of these is exotic, and all of them would also be good architecture in production.

### Alternatives considered (and why we're *not* switching)
- **Python (FastAPI) backend instead of PHP.** Better ML ecosystem, but the prototype ML is statistical and explainable (see §3 of the architecture doc), which PHP handles fine. Model *training* will happen offline in Python regardless, and exported model parameters are loaded by PHP. Switching would only pay off if the team is more fluent in Python than PHP. **Team decision.**
- **Render free Postgres.** Rejected: it **expires 30 days after creation**.
- **A single always-free VPS (e.g. Oracle Cloud).** No spin-down and real cron, but more ops work and a card is required. Not worth it for a 5-week build.
- **Laravel.** Productive, but heavyweight on a 0.1-CPU instance and its conventions differ from the structure you described. **Slim 4** (micro-framework: routing, middleware, PSR-7) matches your `/controllers /services /middleware` layout exactly with almost no overhead.

### Local machine status (checked)
Node 24 and Python 3.14 are installed. **PHP, Composer, MySQL and Docker are not installed.** Before implementation, either install PHP 8.3 and Composer plus a local MariaDB/MySQL, or install Docker Desktop. Docker is preferred because the same Dockerfile then runs locally and on Render.

---

## 3. Groq: what it can and cannot do

- Groq is an **inference** provider for pre-trained open models (gpt-oss, Llama, Qwen). **You cannot train models on Groq.** LoRA fine-tunes are **Enterprise-only, by request, and inference-only**: adapters must be trained elsewhere ([Groq LoRA docs](https://console.groq.com/docs/lora)).
- Every hosted model supports tool / function calling. The **gpt-oss models do not support *parallel* tool calls**, so our tool loop runs sequentially ([tool use docs](https://console.groq.com/docs/tool-use)).
- JSON mode is supported on gpt-oss and recent Llama/Qwen models.
- Model IDs change and get deprecated. **They must live in environment variables, never in code.**

**What this means for "the AI models we will train":**

| Layer | What it is | Where it runs | Trained by us? |
|---|---|---|---|
| **ML models**: machine fingerprint classifier, anomaly detection, forecasting | Statistical / classical ML on time series | Our backend. Training happens offline in Python, and parameters are exported to JSON. | **Yes.** v0 is calibrated statistical methods. v1 is trained on public industrial datasets. v2 is retrained on pilot data. |
| **LLM**: assistant, explanations, report narrative | Pre-trained LLM, **grounded** in our data through tools | Groq API, called **only from the backend** | **No.** We *constrain* it (scope guard, system prompt, tools); we don't train it. |

**Restricting the assistant to EnergyFlow topics** (e.g. refusing "Did Messi win yesterday?") is done with a **scope guard before the main model**, a strict system prompt, and read-only data tools. Training is not involved. Full design in [03-architecture.md §9](03-architecture.md#9-llm-layer--energyflow-assistant).

---

## 4. Kosovo context: the data the product will rely on

### 4.1 Grid emission factor

Source: **Ember, *Yearly Electricity Data*, processed by Our World in Data** ([OWID chart](https://ourworldindata.org/grapher/carbon-intensity-electricity), dataset updated 30 Jun 2026). Indicator: *lifecycle carbon intensity of electricity generation*, in gCO₂e/kWh.

| Year | Kosovo | | 2025 comparison | gCO₂e/kWh |
|---|---|---|---|---|
| 2018 | 961.1 | | **European Union (27)** | **209.9** |
| 2019 | 960.6 | | Europe (Ember) | 282.7 |
| 2020 | 958.2 | | Serbia | 695.8 |
| 2021 | 945.0 | | Poland | 588.6 |
| 2022 | 945.0 | | Bosnia & Herzegovina | 570.6 |
| 2023 | 884.9 | | North Macedonia | 441.4 |
| 2024 | 924.7 | | Germany | 329.7 |
| **2025** | **900.9** | | Montenegro | 264.2 |

**Default in EnergyFlow: 0.901 kgCO₂e/kWh (Ember 2025, Kosovo, lifecycle, generation-based).** It is stored in the database with its source, year and methodology, and it is configurable per company.

**Pitch line (sourced):** in 2025, a kWh saved in Kosovo avoided about **4.3× more CO₂** than a kWh saved in the average EU-27 country (900.9 vs 209.9 g/kWh, Ember via OWID). Energy efficiency in Kosovo SMEs is therefore unusually high-leverage for climate.

**Methodology caveats, which the app must state openly in the Carbon Center's "Methodology" panel:**
1. *Lifecycle* vs *direct combustion*. Ember applies standard lifecycle factors per fuel type. GHG Protocol Scope 2 location-based reporting commonly uses combustion-only factors. The two differ, and Ember does **not** use plant-specific efficiency for Kosovo's old lignite units.
2. *Generation-based*. The factor describes electricity generated in Kosovo, so imports and exports are not reflected.
3. *Annual average*. The factor does not vary by hour. Because Kosovo's grid is dominated by lignite baseload, **moving load to night hours saves money (night tariff) but does not meaningfully reduce CO₂.** EnergyFlow must say this honestly, separating "cost-saving" from "carbon-saving" actions. Energy and climate experts on the jury will notice either way.
4. Alternative factors, such as IEA (licensed) or national data from the ministry or KOSTT, can be added as additional `emission_factors` rows.

The grid is predominantly lignite-based, according to Kosovo's own draft NECP 2025–2030 ([Energy Community](https://www.energy-community.org/dam/jcr:e6badfbe-313d-4ebc-a450-416dcdbd5499/20230714_Final%20Version_First%20Draft%20NECP%202025-2030%20of%20Kosovo.pdf)).

### 4.2 Electricity tariffs for SMEs

- **Who pays regulated tariffs:** the universal-service supplier (KESCO) serves households and **small businesses with fewer than 50 employees *and* turnover under €10M**. Larger businesses have had to **buy on the open market since 1 June 2025**, and business representatives reported expected prices far above regulated levels ([REL](https://www.evropaelire.org/a/liberalizimi-tregu-energjise-biznese-rritje-kosto/33344252.html), [Zëri / ZRRE](https://zeri.info/ekonomia/621834/zrre-ja-miraton-tarifat-per-kostt-in-keds-in-dhe-kesco-n-pa-ndryshime-ne-cmimin-e-energjise-per-vitin-2026/)).
- **2026–2027:** ZRRE (ERO) approved tariffs with **no price change** for 2026 ([Euronews Albania](https://euronews.al/kosova-shmang-rritjen-e-cmimit-te-energjise-per-vitin-2026-zrre-ul-kerkesat/), [Buletini Ekonomik, May 2026](https://buletiniekonomik.com/2026/05/zrre-aprovon-tarifat-e-reja-te-energjise-nuk-ka-shtrenjtim-te-rrymes/)).
- **Two-rate time-of-use structure** (applies to all end consumers) ([Telegrafi / KESCO](https://telegrafi.com/si-ta-kurseni-rrymen/)):
  - **1 Oct – 31 Mar:** high tariff **07:00–22:00**, low tariff **22:00–07:00**
  - **1 Apr – 30 Sep:** high tariff **08:00–23:00**, low tariff **23:00–08:00**
- **Consumption blocks:** 2025 coverage describes a block structure with an **800 kWh/month threshold** and a much higher rate above it. Commercial 0.4 kV figures were reported in the press (e.g. "7.79 c/kWh" in [REL](https://www.evropaelire.org/a/liberalizimi-tregu-energjise-biznese-rritje-kosto/33344252.html)). **VERIFY:** the exact current commercial 0.4 kV rates, fixed charges and VAT must be taken from the **official ERO tariff decision** at [ero-ks.org](https://www.ero-ks.org) and entered as data. We will **not** hard-code tariffs from news articles.

**Product implications:**
- The tariff engine must support **seasonal TOU windows**, **monthly blocks**, fixed charges, VAT, and an **open-market contract** mode for SMEs above the threshold.
- Every tariff record carries `valid_from` / `valid_to` and a source note, so historic costs never change when tariffs change.
- **Savings are valued at the marginal rate** (the block and period that would actually be avoided), not the average rate.
- The two-rate structure creates a genuinely Kosovo-specific recommendation: **"shift flexible loads (pumps, tank filling, pre-cooling, charging) into the low-tariff window."** It is real money, and honestly labelled as cost-only.

### 4.3 ESG reporting framework: VSME

- **EFRAG VSME** (*Voluntary Sustainability Reporting Standard for non-listed SMEs*) was delivered to the European Commission in December 2024 and taken up as a Commission recommendation in 2025. Its stated purpose is to help SMEs **answer sustainability data requests from banks, investors and larger customers** ([EFRAG](https://www.efrag.org/en/projects/voluntary-reporting-standard-for-smes-vsme/concluded), [Forvis Mazars](https://forvismazars.com/sk/en/insights/blog/standard-for-voluntary-esg-reporting-for-smes)).
- **Basic Module:** B1 Basis for preparation, B2 Practices and policies, **B3 Energy and GHG emissions**, B4 Pollution, B5 Biodiversity, B6 Water, B7 Resources and waste, B8–B10 Workforce, B11 Corruption convictions.
- **B3 datapoints** ([VSME template example](https://www.fiegenbaum.solutions/en/blog/vsme-report-template-efrag-standard-2026), [SME Climate Hub sample](https://smeclimatehub.org/wp-content/uploads/2026/03/Climate-Wise-VSME-GHG-Report-774450100644.pdf)):
  - total energy consumption (MWh), split into electricity from the grid, self-generated electricity and fuels, each as renewable or non-renewable
  - gross Scope 1 (tCO₂e)
  - gross Scope 2 location-based (tCO₂e), and market-based where applicable
  - GHG intensity per turnover
- **Why it matters for Kosovo SMEs:** VSME is voluntary. Kosovo SMEs meet it through **EU buyers, banks and investors** asking for data. EnergyFlow can fill most of **B3 automatically from metered data** and guide the user through the rest (fuels, turnover). **VERIFY** the B3 paragraph-level requirements against the official EFRAG text before claiming "VSME-aligned" in the pitch.

### 4.4 CBAM: context for exporters

The EU **Carbon Border Adjustment Mechanism** entered its **definitive phase on 1 Jan 2026**. Imports of electricity from Energy Community contracting parties, **including Kosovo**, are covered. The first annual CBAM declaration (for 2026) is due **30 Sep 2027** ([Energy Community CBAM](https://www.energy-community.org/topics/CBAM.html)).

For Kosovo SMEs exporting CBAM-covered goods, product-level electricity data becomes valuable. **Use this as roadmap and pitch context only.** Do not claim CBAM compliance features without verifying the exact embedded-emissions rules for indirect emissions.

### 4.5 Weather data (for realistic HVAC and fair comparisons)

[Open-Meteo](https://open-meteo.com/) offers free historical and forecast weather with no API key (free for non-commercial use). EnergyFlow uses real Pristina temperatures to:
1. drive the HVAC simulation
2. weather-normalise HVAC baselines (degree days) in before/after comparisons
3. improve the forecast.

### 4.6 Public datasets for training the ML models (candidates; check licences)
- **HIPE** (High-resolution Industrial Production Energy, KIT): machine-level power data from an electronics production site
- **IMDELD** (Industrial Machines Dataset for Electrical Load Disaggregation): machines in a Brazilian feed factory
- **WHITED**: start-up transients of household *and industrial* appliances

We will use these to train or validate the fingerprint classifier and to calibrate the simulator's machine profiles. That breaks the "simulator generates it, classifier recognises it" circularity (see [02-concept-review.md](02-concept-review.md)).

---

## Sources
- [GPEK – Digital Green Innovation Competition 2026](https://gpek.universum-fr.org/) · [Program](https://gpek.universum-fr.org/sq/program) · [FAQ](https://gpek.universum-fr.org/sq/faq)
- [Helvetas – What is considered innovation for the GPEK Challenge Fund (PDF)](https://www.helvetas.org/Publications-PDFs/Eastern-Europe-Caucasus/Kosovo/GPEK/Challenge-fund/English/What%20is%20Considered%20Innovation%20for%20The%20Challenge%20Fund.pdf)
- [Render free tier](https://render.com/docs/free) · [Aiven free plan](https://aiven.io/docs/platform/concepts/free-plan) · [Aiven MySQL free tier](https://aiven.io/docs/products/mysql/concepts/mysql-free-tier) · [Vercel Hobby](https://vercel.com/docs/plans/hobby)
- [Groq rate limits](https://console.groq.com/docs/rate-limits) · [Groq models](https://console.groq.com/docs/models) · [Groq tool use](https://console.groq.com/docs/tool-use) · [Groq LoRA](https://console.groq.com/docs/lora)
- [Ember via Our World in Data – carbon intensity of electricity](https://ourworldindata.org/grapher/carbon-intensity-electricity)
- [Kosovo draft NECP 2025–2030 (Energy Community)](https://www.energy-community.org/dam/jcr:e6badfbe-313d-4ebc-a450-416dcdbd5499/20230714_Final%20Version_First%20Draft%20NECP%202025-2030%20of%20Kosovo.pdf)
- [ZRRE 2026 tariffs – Zëri](https://zeri.info/ekonomia/621834/zrre-ja-miraton-tarifat-per-kostt-in-keds-in-dhe-kesco-n-pa-ndryshime-ne-cmimin-e-energjise-per-vitin-2026/) · [Euronews Albania](https://euronews.al/kosova-shmang-rritjen-e-cmimit-te-energjise-per-vitin-2026-zrre-ul-kerkesat/) · [Buletini Ekonomik](https://buletiniekonomik.com/2026/05/zrre-aprovon-tarifat-e-reja-te-energjise-nuk-ka-shtrenjtim-te-rrymes/) · [REL – market liberalisation](https://www.evropaelire.org/a/liberalizimi-tregu-energjise-biznese-rritje-kosto/33344252.html) · [Telegrafi – tariff hours](https://telegrafi.com/si-ta-kurseni-rrymen/) · [ERO](https://www.ero-ks.org)
- [EFRAG VSME](https://www.efrag.org/en/projects/voluntary-reporting-standard-for-smes-vsme/concluded) · [VSME template overview](https://www.fiegenbaum.solutions/en/blog/vsme-report-template-efrag-standard-2026)
- [Energy Community – CBAM](https://www.energy-community.org/topics/CBAM.html)
- [Open-Meteo](https://open-meteo.com/)
