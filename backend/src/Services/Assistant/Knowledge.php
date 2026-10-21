<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Assistant;

/**
 * What the assistant knows besides the company's data: how EnergyFlow works and
 * a few Kosovo energy facts, each with its source (docs/01-research.md). The
 * model may quote figures from here and from the data snapshot; its general
 * expertise covers concepts, not Kosovo figures.
 *
 * The product overview always goes with a question; the other sections only
 * when the question touches them, which keeps every request well inside Groq's
 * free-tier tokens per minute.
 */
final class Knowledge
{
    private const PRODUCT = <<<'KB'
ENERGYFLOW (this product)
EnergyFlow measures each machine of a Kosovo SME with clamp meters (EF-N3 nodes, still a prototype design, or supported Shelly Gen2 metering smart relays through the hardware bridge), finds wasted energy, proposes and automates fixes, proves the savings and reports carbon. The public demo company is fictional and its machines are simulated.
Pages:
- Overview: today, month to date vs the same days last month, month-end projection with a likely range, CO₂e, EnergyFlow Score, energy-flow diagram.
- Live: power of every machine every few seconds, what runs after hours, Turn Off buttons.
- Machines / Devices: per-machine consumption, operating hours; the meters and their connection status.
- Waste & Alerts: waste events (after hours, idle during production, efficiency drift, compressed-air leak signature) quantified in kWh, € and CO₂e, plus alerts (power spike, device offline, Turn Off not confirmed, low power factor, peak coincidence).
- Opportunities: recommendations ranked by (€ and CO₂ saved) × confidence ÷ effort, each quantified by replaying the machine's own recent data; a What-if simulator. EnergyFlow generates exactly four kinds today: switch a machine off automatically after its schedule, find and fix compressed-air leaks, service a machine whose efficiency drifted, run a flexible machine in the night tariff. It does not recommend equipment upgrades (variable-speed drives, LED lighting, new machines) yet.
- Automations: policies such as "switch off 15 min after the schedule ends", and the log of every command.
- Impact: before/after proof. Savings = adjusted baseline − measured, with a 90% confidence interval; verified after at least 7 reporting days when the lower bound is above zero. IPMVP-inspired (Option B), not a certified M&V.
- Carbon & ESG: Scope 2 location-based CO₂e from metered electricity, Scope 1 from declared fuels, VSME B3 datapoints, readiness checklist, methodology.
- Reports: daily, weekly and monthly energy and sustainability reports, frozen figures, downloadable as PDF.
- Scan: the phone camera reads an electricity bill, a utility meter, a machine nameplate, a fuel receipt or an EU energy label; the user checks every value; the photo is not stored.
- Ask EnergyFlow (this assistant): data questions answered straight from the database; open questions by a language model (Groq) with the company's data, numbers checked against it.
KB;

    /** Section → [words that make it relevant, text]. */
    private const SECTIONS = [
        'detection' => [
            ['waste', 'alert', 'alarm', 'detect', 'spike', 'overload', 'drift', 'leak', 'idle', 'after hours', 'after-hours', 'power factor', 'cos', 'reactive', 'kvar',
                'capacitor', 'peak', 'maximeter', 'demand charge', 'engaged power', 'z-score', 'cusum', 'anomal', 'humb', 'rrjedh', 'pritje', 'pas orarit', 'kulm',
                'mbingarkes', 'faktor', 'reaktiv', 'kondensator', 'fuqi e angazhuar'],
            <<<'KB'
HOW DETECTION WORKS
after-hours = running while the machine's schedule says closed (critical always-on loads excluded); idle = drawing power without producing; efficiency drift = a CUSUM test on daily running power against the first 28 days; compressed-air leak = load/unload cycling pattern while the plant is closed; power spike = a robust z-score (median and MAD of the machine's own 15-minute peaks for that day type and hour, last 28 days) above 6 for at least 2 consecutive minutes, critical when a reading exceeds 110 % of the nameplate rating (motor overload).
Low power factor = the site's cos φ over the billing month: ERO Decision V_2703_2025 charges reactive energy above the allowance that corresponds to cos φ 0.95 (0.87 €c/kVArh for 0.4 kV Category I), so EnergyFlow shows the excess kVArh, the € so far and at this pace, the machines drawing it, and the capacitor bank size (kvar) that would bring the site to 0.95 at its usual working load.
Peak coincidence = engaged power is billed per kW per month (3.87 €/kW for 0.4 kV Category I) on the month's highest 15-minute average (the maximeter); EnergyFlow finds that quarter-hour and how much lower it would have been if the machines marked as flexible loads had run at another time.
KB,
        ],
        'control' => [
            ['turn off', 'switch off', 'shut', 'relay', 'command', 'automat', 'policy', 'policies', 'schedule', 'fik', 'ndal', 'komand', 'polic', 'orar'],
            <<<'KB'
HOW TURN OFF WORKS
The user (or an accepted automation) asks; the command goes to the relay; EnergyFlow marks it verified only when the meter shows the machine below its off threshold within 60 seconds, otherwise it fails after 120 seconds and raises an alert. EnergyFlow never switches a machine on, and never switches off a machine marked critical or monitor-only. Demo machines are simulated and labelled as such.
KB,
        ],
        'scan' => [
            ['scan', 'bill', 'invoice', 'receipt', 'fake', 'genuine', 'label', 'nameplate', 'fuel', 'diesel', 'petrol', 'gas', 'scope 1', 'supplier', 'vat', 'camera', 'photo',
                'skan', 'fatur', 'kupon', 'falsifik', 'etiket', 'karburant', 'naft', 'benzin', 'furnizues', 'tvsh', 'kamer', 'foto'],
            <<<'KB'
SCAN DETAILS
Scan uses a Groq vision model. Bills are checked by rules: supplier against ERO's licence register, dates, day + night = total, meter readings, amount + VAT = total, VAT at 8 %, price against the official tariff, kWh against EnergyFlow's meters; several failed integrity rules give "likely fake". A photo cannot prove a document genuine; the supplier can. Fuel receipts become Scope 1 with UK DESNZ 2025 factors (100 % mineral fuels, e.g. diesel 2.66155 kg CO2e per litre).
KB,
        ],
        'engineering' => [
            ['compressor', 'compressed air', 'pump', 'fan', 'vsd', 'variable speed', 'variable-speed', 'drive', 'inverter', 'motor', 'speed', 'drift', 'leak', 'wear',
                'kompresor', 'ajër', 'ajer', 'pomp', 'ventilator', 'shpejtësi', 'shpejtesi', 'inverter', 'konsum'],
            <<<'KB'
GENERAL ENGINEERING (qualitative)
- Affinity laws: for centrifugal fans and pumps, power rises roughly with the cube of speed, so slowing them with a variable-speed drive saves a lot. Screw and piston compressors are positive-displacement machines: their power falls roughly in proportion to speed, and a VSD saves mainly by avoiding unloaded running and pressure swings at part load.
- An unloaded screw compressor still draws a substantial share of its full-load power; compressed-air leaks make a compressor cycle even when nothing uses air.
- Running power that creeps up over weeks at the same work usually points to wear, fouling, leaks or changed settings; it is a reason to inspect, not a diagnosis.
KB,
        ],
        'kosovo' => [
            ['kosov', 'grid', 'factor', 'co2', 'co₂', 'carbon', 'emission', 'footprint', 'ghg', 'greenhouse', 'climate', 'tariff', 'night', 'price', 'kesco', 'ero', 'zrre',
                'esg', 'vsme', 'csrd', 'cbam', 'scope', 'regulat', 'complian', 'law', 'market', 'europe', ' eu', 'report', 'sustainab', 'lignite', 'coal',
                'karbon', 'emetim', 'gjurm', 'klim', 'tarif', 'natë', 'nate', 'çmim', 'cmim', 'rregull', 'ligj', 'raport', 'qëndrueshm', 'qendrueshm', 'linjit', 'qymyr'],
            <<<'KB'
KOSOVO ENERGY FACTS (with sources)
- Grid emission factor: 0.901 kg CO₂e/kWh (900.9 g/kWh), Kosovo 2025, lifecycle, generation-based. Source: Ember Yearly Electricity Data via Our World in Data. EU-27 in 2025: 209.9 g/kWh, so a kWh saved in Kosovo avoids about 4.3× more CO₂ than in the average EU country.
- The factor is an annual average: moving load to night hours saves money (night tariff) but does not meaningfully reduce CO₂. Kosovo's grid is predominantly lignite-based (Kosovo draft NECP 2025–2030, Energy Community).
- Time-of-use tariff windows (KESCO): 1 Oct – 31 Mar high tariff 07:00–22:00, low 22:00–07:00; 1 Apr – 30 Sep high tariff 08:00–23:00, low 23:00–08:00.
- Regulated supply (KESCO universal service) covers small businesses with fewer than 50 employees and turnover under €10M; larger businesses buy on the open market since 1 June 2025 (REL; ZRRE/ERO). ERO approved no electricity price change for 2026.
- EFRAG VSME: voluntary sustainability reporting standard for non-listed SMEs (delivered December 2024, Commission recommendation 2025), used to answer data requests from banks, investors and EU customers. B3 covers energy (MWh, grid/self-generated/fuels) and GHG (Scope 1, Scope 2 location- and market-based, intensity per turnover).
- Scope 2 market-based: not available for Kosovo (no published residual-mix factor).
- EU CBAM: definitive phase since 1 Jan 2026; electricity imports from Energy Community parties including Kosovo are covered; the first annual declaration is due 30 Sep 2027 (Energy Community).
KB,
        ],
    ];

    /** The product overview plus the sections the question touches; everything when no question is given. */
    public static function text(?string $question = null): string
    {
        $q = $question === null ? null : ' ' . mb_strtolower($question) . ' ';
        $parts = [self::PRODUCT];
        foreach (self::SECTIONS as [$words, $text]) {
            if ($q === null || self::mentions($q, $words)) {
                $parts[] = $text;
            }
        }
        return implode("\n\n", $parts);
    }

    private static function mentions(string $q, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($q, $word)) {
                return true;
            }
        }
        return false;
    }
}
