<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Assistant;

use EnergyFlow\Core\Database;
use EnergyFlow\Middleware\RequireRole;
use EnergyFlow\Services\Analytics\EnergyQuery;
use EnergyFlow\Services\Analytics\LiveService;
use EnergyFlow\Services\Analytics\OverviewService;
use EnergyFlow\Services\Analytics\Period;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Carbon\CarbonReport;
use EnergyFlow\Services\Carbon\EmissionFactors;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Detection\WasteReport;
use EnergyFlow\Services\Impact\ImpactService;
use EnergyFlow\Services\Optimization\Recommendations;
use EnergyFlow\Services\Tariff\TariffBook;

/**
 * The assistant's own answers, computed from the same services the pages use,
 * so a number in the chat is always the number on the screen. Each answer has
 * its sources (the page it came from) and, where useful, actions: open a page,
 * ask a follow-up, or a Turn Off the user must confirm.
 *
 * @phpstan-type Answer array{text: string, sources: list<array{label: string, to: string}>, actions: list<array<string, mixed>>}
 */
final class DataAnswers
{
    private readonly LocalTime $time;
    private readonly int $now;

    public function __construct(private readonly int $companyId, private readonly Say $say, private readonly array $user)
    {
        $this->time = LocalTime::forCompany($companyId);
        $this->now = Clock::now($companyId);
    }

    /** @return Answer|null null when this intent has nothing to say here (the LLM takes over) */
    public function answer(array $intent, string $question, array $context): ?array
    {
        $name = $intent['name'];
        if (str_starts_with($name, 'navigate:')) {
            return $this->navigate(substr($name, 9), $question);
        }
        return match ($name) {
            'off_topic' => $this->refusal(),
            'greeting' => $this->greeting(),
            'thanks' => $this->reply($this->say->t('You\'re welcome. Anything else about your energy?', 'S\'ka përse. Diçka tjetër për energjinë tuaj?'), $this->defaultFollowUps()),
            'help' => $this->help(),
            'turn_off' => $this->turnOff($question, $context),
            'running_now' => $this->runningNow(),
            'consumption' => $this->consumption($this->period($intent['period'] ?? 'mtd'), $this->oneMachine($question, $context)),
            'top_consumer' => $this->topConsumers($this->period($intent['period'] ?? 'mtd')),
            'waste' => $this->waste($this->period($intent['period'] ?? 'mtd'), $intent['type'] ?? null),
            'alerts' => $this->alerts(),
            'opportunities' => $this->opportunities(),
            'savings' => $this->savings(),
            'carbon' => $this->carbon($this->period($intent['period'] ?? 'mtd')),
            'forecast' => $this->forecast(),
            'score' => $this->score(),
            default => null,
        };
    }

    // ---------------------------------------------------------------- conversation

    /** The exact, localised refusal (docs/03 §9.3). No model call is spent on it. */
    public function refusal(): array
    {
        return $this->reply(
            $this->say->t(
                'I can only help with your company\'s energy, costs, carbon and sustainability data, and with using EnergyFlow.',
                'Mund të ndihmoj vetëm me energjinë, kostot, karbonin dhe të dhënat e qëndrueshmërisë së kompanisë suaj, si dhe me përdorimin e EnergyFlow.',
            ),
            $this->defaultFollowUps(),
        );
    }

    private function greeting(): array
    {
        return $this->reply(
            $this->say->t(
                "Hello! I'm EnergyFlow's assistant. I answer from your meters: consumption, costs, waste, carbon, savings, and what to change next.",
                'Përshëndetje! Jam asistenti i EnergyFlow. Përgjigjem nga matësit tuaj: konsumi, kostot, humbjet, karboni, kursimet dhe çfarë të ndryshoni më pas.',
            ),
            $this->defaultFollowUps(),
        );
    }

    private function help(): array
    {
        $text = $this->say->t(
            "Here's what I can do:\n- **Answer from your data**: consumption and cost for any period, the biggest consumers, waste, alerts, CO₂, verified savings and the month-end forecast.\n- **Explain**: why something changed, what an alert means, how EnergyFlow calculates savings or CO₂.\n- **Act, with your confirmation**: switch a machine off (\"turn off CMP-01\") or open a page (\"open Carbon & ESG\").\n\nNumbers come from your meters; I never estimate them.",
            "Ja çfarë mund të bëj:\n- **Përgjigje nga të dhënat tuaja**: konsumi dhe kostoja për çdo periudhë, konsumatorët më të mëdhenj, humbjet, alarmet, CO₂, kursimet e verifikuara dhe parashikimi për fund të muajit.\n- **Shpjegime**: pse ndryshoi diçka, çfarë do të thotë një alarm, si i llogarit EnergyFlow kursimet ose CO₂.\n- **Veprime, me konfirmimin tuaj**: fikja e një makinerie (\"fik CMP-01\") ose hapja e një faqeje (\"hap Karboni & ESG\").\n\nNumrat vijnë nga matësit tuaj; nuk i vlerësoj kurrë përafërsisht.",
        );
        return $this->reply($text, $this->defaultFollowUps());
    }

    /** @return list<array{type: string, text: string}> */
    public function defaultFollowUps(): array
    {
        return array_map(static fn (string $text): array => ['type' => 'ask', 'text' => $text], [
            $this->say->t('Which machine cost us the most this month?', 'Cila makineri na kushtoi më shumë këtë muaj?'),
            $this->say->t('How much did we waste after working hours this week?', 'Sa humbëm pas orarit të punës këtë javë?'),
            $this->say->t('What should we change tomorrow?', 'Çfarë duhet të ndryshojmë nesër?'),
        ]);
    }

    // ---------------------------------------------------------------- actions

    private function navigate(string $page, string $question): ?array
    {
        if ($page !== 'machine') {
            return [
                'text' => sprintf($this->say->t('Opening **%s**.', 'Po hap **%s**.'), $this->pageName($page)),
                'sources' => [],
                'actions' => [['type' => 'navigate', 'to' => $page, 'page' => self::pageKey($page), 'auto' => true]],
            ];
        }
        $machines = MachineMatcher::find($this->companyId, $question);
        if (count($machines) === 1) {
            $m = $machines[0];
            return [
                'text' => sprintf($this->say->t('Opening **%s (%s)**.', 'Po hap **%s (%s)**.'), $m['name'], $m['code']),
                'sources' => [],
                'actions' => [['type' => 'navigate', 'to' => '/machines/' . $m['id'], 'page' => 'machine', 'label' => $m['code'], 'auto' => true]],
            ];
        }
        if (count($machines) > 1) {
            return $this->reply(
                $this->say->t('Which one?', 'Cilën?'),
                array_map(fn (array $m): array => ['type' => 'navigate', 'to' => '/machines/' . $m['id'], 'page' => 'machine', 'label' => $m['code']], $machines),
            );
        }
        return null;
    }

    /**
     * "Turn off the compressor" → a confirmation card, never an immediate action.
     * The click on Yes goes through POST /machines/{id}/commands like the Live
     * button: same role check, same safety rules, verified by the meter.
     */
    private function turnOff(string $question, array $context): array
    {
        $live = LiveService::snapshot($this->companyId);
        $byId = array_column($live['machines'], null, 'id');
        $matched = MachineMatcher::find($this->companyId, $question);
        if ($matched === [] && isset($context['machine_id'], $byId[(int) $context['machine_id']])) {
            $matched = [['id' => (int) $context['machine_id']]];
        }
        if ($matched === []) {
            // "Turn it off": the one machine running after hours, if there is exactly one.
            $candidates = array_values(array_filter($live['machines'], static fn (array $m): bool => $m['after_hours'] !== null && $m['control']['can_turn_off']));
            if (count($candidates) === 1) {
                $matched = [['id' => $candidates[0]['id']]];
            } elseif ($candidates !== []) {
                return $this->reply(
                    $this->say->t('Several machines are running after hours. Which one should I switch off?', 'Disa makineri po punojnë pas orarit. Cilën ta fik?'),
                    array_map(fn (array $m): array => ['type' => 'ask', 'text' => $this->say->t('Turn off ', 'Fik ') . $m['code']], $candidates),
                );
            } else {
                return $this->reply(
                    $this->say->t('Which machine should I switch off? Nothing is running after hours right now.', 'Cilën makineri ta fik? Asnjë makineri nuk po punon pas orarit tani.'),
                    [['type' => 'navigate', 'to' => '/live', 'page' => 'live']],
                );
            }
        }
        if (count($matched) > 1) {
            return $this->reply(
                $this->say->t('Which one?', 'Cilën?'),
                array_map(fn (array $m): array => ['type' => 'ask', 'text' => $this->say->t('Turn off ', 'Fik ') . $m['code']], $matched),
            );
        }

        $m = $byId[$matched[0]['id']] ?? null;
        if ($m === null) {
            return $this->reply($this->say->t('I couldn\'t find that machine.', 'Nuk e gjeta atë makineri.'), []);
        }
        $label = "**{$m['name']} ({$m['code']})**";
        $page = [['type' => 'navigate', 'to' => '/machines/' . $m['id'], 'page' => 'machine', 'label' => $m['code']]];
        $source = [['label' => $this->say->t('Live readings', 'Leximet live') . ' · ' . $live['local_time'], 'to' => '/live']];

        $reason = $m['control']['reason'];
        if ($reason !== null) {
            $why = match ($reason) {
                'machine_critical' => $this->say->t("{$label} is marked critical, so EnergyFlow never switches it off.", "{$label} është shënuar kritike, prandaj EnergyFlow nuk e fik kurrë."),
                'control_monitor_only' => $this->say->t("{$label} is set to monitor only: EnergyFlow measures it but doesn't control it. You can change this in the machine's settings.", "{$label} është vetëm për monitorim: EnergyFlow e mat, por nuk e kontrollon. Këtë mund ta ndryshoni te cilësimet e makinerisë."),
                'no_relay' => $this->say->t("{$label} has no EnergyFlow relay connected, so it can only be switched off by hand.", "{$label} nuk ka rele të EnergyFlow të lidhur, prandaj mund të fiket vetëm me dorë."),
                'command_pending' => $this->say->t("A Turn Off for {$label} is already in progress.", "Një komandë fikjeje për {$label} është tashmë në proces."),
                'already_off' => $this->say->t("{$label} is already off.", "{$label} është tashmë e fikur."),
                'no_live_data' => $this->say->t("There's no live reading from {$label} in the last 2 minutes, so EnergyFlow won't send a command blind.", "Nuk ka lexim live nga {$label} në 2 minutat e fundit, prandaj EnergyFlow nuk dërgon komandë pa e parë gjendjen."),
                default => $this->say->t("{$label} can't be switched off from EnergyFlow.", "{$label} nuk mund të fiket nga EnergyFlow."),
            };
            return ['text' => $why, 'sources' => $source, 'actions' => $page];
        }
        if (!RequireRole::atLeast((string) $this->user['role'], 'manager')) {
            return [
                'text' => $this->say->t(
                    "{$label} is on now (" . $this->say->kw($m['power_kw']) . '), but only managers and admins can switch machines off.',
                    "{$label} është ndezur tani (" . $this->say->kw($m['power_kw']) . '), por vetëm menaxherët dhe administratorët mund t\'i fikin makineritë.',
                ),
                'sources' => $source,
                'actions' => $page,
            ];
        }

        $lines = [$this->say->t("Turn off {$label} now? It is drawing " . $this->say->kw($m['power_kw']) . '.', "Ta fik {$label} tani? Po tërheq " . $this->say->kw($m['power_kw']) . '.')];
        if ($m['after_hours'] !== null) {
            $a = $m['after_hours'];
            $lines[] = $this->say->t(
                'It has run ' . $this->say->duration($a['duration_s']) . ' after hours: ' . $this->say->kwh($a['kwh']) . ', ' . $this->say->eur($a['eur']) . ', ' . $this->say->co2($a['co2_kg']) . ' so far.',
                'Ka punuar ' . $this->say->duration($a['duration_s']) . ' pas orarit: ' . $this->say->kwh($a['kwh']) . ', ' . $this->say->eur($a['eur']) . ', ' . $this->say->co2($a['co2_kg']) . ' deri tani.',
            );
        }
        if ($m['control']['needs_confirm'] === 'machine_scheduled') {
            $lines[] = $this->say->t('**Note:** it is scheduled to run now, so production may be using it.', '**Kujdes:** sipas orarit duhet të punojë tani, ndaj prodhimi mund ta jetë duke e përdorur.');
        }
        $lines[] = $this->say->t(
            'EnergyFlow sends the command to its relay and confirms it only when the meter shows the machine is off.',
            'EnergyFlow ia dërgon komandën releut dhe e konfirmon vetëm kur matësi tregon se makineria është fikur.',
        );
        if (($m['device']['simulated'] ?? false) === true) {
            $lines[] = $this->say->t('_This machine is simulated (demo)._', '_Kjo makineri është e simuluar (demo)._');
        }
        return [
            'text' => implode("\n\n", $lines),
            'sources' => $source,
            'actions' => [[
                'type' => 'turn_off', 'state' => 'pending',
                'machine' => ['id' => $m['id'], 'code' => $m['code'], 'name' => $m['name']],
                'needs_confirm' => $m['control']['needs_confirm'],
            ]],
        ];
    }

    // ---------------------------------------------------------------- data

    private function runningNow(): array
    {
        $live = LiveService::snapshot($this->companyId);
        $on = array_values(array_filter($live['machines'], static fn (array $m): bool => in_array($m['state'], ['running', 'idle'], true)));
        usort($on, static fn (array $a, array $b): int => ($b['power_kw'] ?? 0) <=> ($a['power_kw'] ?? 0));
        $states = ['running' => $this->say->t('running', 'në punë'), 'idle' => $this->say->t('idle', 'në pritje')];

        $text = sprintf(
            $this->say->t('Right now (%s) the site draws **%s**. %d of %d machines are on:', 'Tani (%s) objekti tërheq **%s**. %d nga %d makineri janë ndezur:'),
            $live['local_time'], $this->say->kw($live['site_kw']), count($on), count($live['machines']),
        );
        foreach (array_slice($on, 0, 6) as $m) {
            $text .= "\n- **{$m['code']}** {$m['name']} · " . $this->say->kw($m['power_kw']) . ' · ' . $states[$m['state']];
        }
        $actions = [];
        $after = array_values(array_filter($on, static fn (array $m): bool => $m['after_hours'] !== null));
        foreach ($after as $m) {
            $a = $m['after_hours'];
            $text .= "\n\n" . sprintf(
                $this->say->t('**%s** is running after hours (%s): %s, %s, %s so far.', '**%s** po punon pas orarit (%s): %s, %s, %s deri tani.'),
                $m['code'], $this->say->duration($a['duration_s']), $this->say->kwh($a['kwh']), $this->say->eur($a['eur']), $this->say->co2($a['co2_kg']),
            );
            if ($m['control']['can_turn_off']) {
                $actions[] = ['type' => 'ask', 'text' => $this->say->t('Turn off ', 'Fik ') . $m['code']];
            }
        }
        if ($after === []) {
            $text .= "\n\n" . $this->say->t('Nothing is running outside its schedule.', 'Asnjë makineri nuk po punon jashtë orarit.');
        }
        $actions[] = ['type' => 'navigate', 'to' => '/live', 'page' => 'live'];
        return ['text' => $text, 'sources' => [['label' => $this->say->t('Live readings', 'Leximet live') . ' · ' . $live['local_time'], 'to' => '/live']], 'actions' => $actions];
    }

    /** @param array{id: int, code: string, name: string}|null $machine */
    private function consumption(Period $period, ?array $machine): array
    {
        $tariff = TariffBook::forCompany($this->companyId);
        $factor = EmissionFactors::gridFactor($this->companyId)['value'];
        $incomer = OverviewService::incomerId($this->companyId);
        $by = EnergyQuery::byMachine($this->companyId, $period->from, $period->to, null, $tariff);
        $site = EnergyQuery::site($by, $incomer);
        $previous = EnergyQuery::byMachine($this->companyId, $period->previousFrom, $period->previousTo, null, $tariff);
        $label = $this->periodLabel($period);

        if ($machine !== null) {
            $e = $by[$machine['id']] ?? ['kwh' => 0.0, 'kwh_high' => 0.0, 'kwh_low' => 0.0];
            $p = $previous[$machine['id']]['kwh'] ?? 0.0;
            $text = sprintf(
                $this->say->t('**%s (%s)** · %s: **%s**, %s, %s, %s of the site.', '**%s (%s)** · %s: **%s**, %s, %s, %s e objektit.'),
                $machine['name'], $machine['code'], $label, $this->say->kwh($e['kwh']), $this->say->eur(EnergyQuery::energyCost($e, $tariff)),
                $this->say->co2($e['kwh'] * $factor), $this->say->pct($site['kwh'] > 0 ? $e['kwh'] / $site['kwh'] : null),
            );
            if ($p > 0) {
                $text .= "\n\n" . sprintf($this->say->t('Previous period: %s (%s).', 'Periudha e mëparshme: %s (%s).'), $this->say->kwh($p), $this->say->change($e['kwh'] / $p - 1));
            }
            return [
                'text' => $text,
                'sources' => [$this->source($this->say->t('Energy by machine', 'Energjia sipas makinerisë'), $period, '/machines/' . $machine['id'])],
                'actions' => [['type' => 'ask', 'text' => sprintf($this->say->t('Explain the consumption of %s this month', 'Shpjego konsumin e %s këtë muaj'), $machine['code'])]],
            ];
        }

        $prev = EnergyQuery::site($previous, $incomer);
        $text = sprintf(
            $this->say->t('**%s**: **%s**, %s in energy charges (excl. VAT), %s.', '**%s**: **%s**, %s kosto energjie (pa TVSH), %s.'),
            $label, $this->say->kwh($site['kwh']), $this->say->eur(EnergyQuery::energyCost($site, $tariff)), $this->say->co2($site['kwh'] * $factor),
        );
        if ($prev['kwh'] > 0) {
            $text .= ' ' . sprintf($this->say->t('Previous period: %s (%s).', 'Periudha e mëparshme: %s (%s).'), $this->say->kwh($prev['kwh']), $this->say->change($site['kwh'] / $prev['kwh'] - 1));
        }
        $top = $this->machineRows($by, $site['kwh'], $tariff, 1)[0] ?? null;
        if ($top !== null && $top['kwh'] > 0) {
            $text .= "\n\n" . sprintf(
                $this->say->t('Largest consumer: **%s** %s, %s (%s).', 'Konsumatori më i madh: **%s** %s, %s (%s).'),
                $top['code'], $top['name'], $this->say->kwh($top['kwh']), $this->say->pct($top['share']),
            );
        }
        return [
            'text' => $text,
            'sources' => [$this->source($this->say->t('Site energy', 'Energjia e objektit'), $period, '/')],
            'actions' => [
                ['type' => 'ask', 'text' => $this->say->t('Which machine cost us the most this month?', 'Cila makineri na kushtoi më shumë këtë muaj?')],
                ['type' => 'ask', 'text' => $this->say->t('What is the month-end forecast?', 'Cili është parashikimi për fund të muajit?')],
            ],
        ];
    }

    private function topConsumers(Period $period): array
    {
        $tariff = TariffBook::forCompany($this->companyId);
        $by = EnergyQuery::byMachine($this->companyId, $period->from, $period->to, null, $tariff);
        $site = EnergyQuery::site($by, OverviewService::incomerId($this->companyId));
        $rows = $this->machineRows($by, $site['kwh'], $tariff, 3);
        if ($rows === [] || $rows[0]['kwh'] <= 0) {
            return $this->noData($period);
        }
        $text = sprintf($this->say->t('**%s**, the largest consumers:', '**%s**, konsumatorët më të mëdhenj:'), $this->periodLabel($period));
        foreach ($rows as $i => $r) {
            $text .= "\n" . ($i + 1) . ". **{$r['code']}** {$r['name']}: " . $this->say->kwh($r['kwh']) . ', ' . $this->say->eur($r['eur']) . ' (' . $this->say->pct($r['share']) . ')';
        }
        $text .= "\n\n" . sprintf(
            $this->say->t('Together they are %s of the site\'s %s.', 'Së bashku janë %s nga %s e objektit.'),
            $this->say->pct(array_sum(array_column($rows, 'share'))), $this->say->kwh($site['kwh']),
        );
        return [
            'text' => $text,
            'sources' => [$this->source($this->say->t('Energy by machine', 'Energjia sipas makinerisë'), $period, '/machines')],
            'actions' => [
                ['type' => 'ask', 'text' => sprintf($this->say->t('How much did %s waste after hours?', 'Sa humbi %s pas orarit?'), $rows[0]['code'])],
                ['type' => 'navigate', 'to' => '/machines/' . $rows[0]['id'], 'page' => 'machine', 'label' => $rows[0]['code']],
            ],
        ];
    }

    private function waste(Period $period, ?string $type): array
    {
        if ($type !== null) {
            return $this->wasteOfType($period, $type);
        }
        $w = WasteReport::summary($this->companyId, $period);
        $t = $w['totals'];
        $label = $this->periodLabel($period);
        if ($t['events'] === 0) {
            return [
                'text' => sprintf($this->say->t('**%s**: no waste detected. Machines ran only when scheduled or needed.', '**%s**: nuk u gjet humbje. Makineritë punuan vetëm sipas orarit ose kur duheshin.'), $label),
                'sources' => [$this->source($this->say->t('Waste', 'Humbjet'), $period, '/waste')],
                'actions' => [],
            ];
        }
        $text = sprintf(
            $this->say->t('**%s**: EnergyFlow found **%s** of waste (%s of consumption), worth **%s** and %s, in %d events.', '**%s**: EnergyFlow gjeti **%s** humbje (%s e konsumit), me vlerë **%s** dhe %s, në %d raste.'),
            $label, $this->say->kwh($t['kwh']), $this->say->pct($t['share_of_consumption']), $this->say->eur($t['eur']), $this->say->co2($t['co2_kg']), $t['events'],
        );
        foreach ($w['by_type'] as $row) {
            $text .= "\n- " . $this->wasteType($row['type']) . ': ' . $this->say->kwh($row['kwh']) . ', ' . $this->say->eur($row['eur']) . ' (' . $row['events'] . ')';
        }
        $top = $w['by_machine'][0] ?? null;
        $actions = [];
        if ($top !== null) {
            $text .= "\n\n" . sprintf($this->say->t('Biggest source: **%s** %s, %s (%s).', 'Burimi më i madh: **%s** %s, %s (%s).'), $top['machine']['code'], $top['machine']['name'], $this->say->kwh($top['kwh']), $this->say->eur($top['eur']));
            $actions[] = ['type' => 'ask', 'text' => sprintf($this->say->t('How do we stop %s wasting energy?', 'Si ta ndalim humbjen te %s?'), $top['machine']['code'])];
        }
        $actions[] = ['type' => 'navigate', 'to' => '/waste?period=' . rawurlencode($this->pagePeriod($period)), 'page' => 'waste'];
        return ['text' => $text, 'sources' => [$this->source($this->say->t('Waste', 'Humbjet'), $period, '/waste')], 'actions' => $actions];
    }

    /** "How much did we waste after hours?": that kind of waste only, by machine. */
    private function wasteOfType(Period $period, string $type): array
    {
        $events = WasteReport::list($this->companyId, $period, ['type' => $type]);
        $label = $this->periodLabel($period);
        $source = [$this->source($this->say->t('Waste', 'Humbjet'), $period, '/waste')];
        $link = ['type' => 'navigate', 'to' => '/waste?period=' . rawurlencode($this->pagePeriod($period)), 'page' => 'waste'];
        if ($events === []) {
            return [
                'text' => sprintf($this->say->t('**%s**, %s: none. No machine ran outside its schedule.', '**%s**, %s: asnjë. Asnjë makineri nuk punoi jashtë orarit.'), $label, mb_strtolower($this->wasteType($type))),
                'sources' => $source,
                'actions' => [$link],
            ];
        }
        $byMachine = [];
        foreach ($events as $e) {
            $code = $e['machine']['code'];
            $byMachine[$code] ??= ['code' => $code, 'name' => $e['machine']['name'], 'kwh' => 0.0, 'eur' => 0.0, 'co2_kg' => 0.0, 'events' => 0];
            $byMachine[$code]['kwh'] += $e['kwh'];
            $byMachine[$code]['eur'] += $e['eur'];
            $byMachine[$code]['co2_kg'] += $e['co2_kg'];
            $byMachine[$code]['events']++;
        }
        usort($byMachine, static fn (array $a, array $b): int => $b['kwh'] <=> $a['kwh']);
        $kwh = array_sum(array_column($byMachine, 'kwh'));
        $text = sprintf(
            $this->say->t('**%s**, %s: **%s**, **%s** and %s in %d events.', '**%s**, %s: **%s**, **%s** dhe %s në %d raste.'),
            $label, mb_strtolower($this->wasteType($type)), $this->say->kwh($kwh), $this->say->eur(array_sum(array_column($byMachine, 'eur'))),
            $this->say->co2(array_sum(array_column($byMachine, 'co2_kg'))), count($events),
        );
        foreach (array_slice($byMachine, 0, 4) as $m) {
            $text .= "
- **{$m['code']}** {$m['name']}: " . $this->say->kwh($m['kwh']) . ', ' . $this->say->eur($m['eur']) . " ({$m['events']})";
        }
        $actions = [];
        if ($type === 'after_hours') {
            $actions[] = ['type' => 'ask', 'text' => sprintf($this->say->t('How do we stop %s running after hours?', 'Si ta ndalim %s të punojë pas orarit?'), $byMachine[0]['code'])];
        }
        $actions[] = $link;
        return ['text' => $text, 'sources' => $source, 'actions' => $actions];
    }

    private function alerts(): array
    {
        $rows = Database::all(
            "SELECT a.id, a.severity, a.title_key, a.params, a.opened_at, m.code AS machine_code, d.serial AS device_serial
               FROM alerts a LEFT JOIN machines m ON m.id = a.machine_id LEFT JOIN devices d ON d.id = a.device_id
              WHERE a.company_id = ? AND a.status <> 'resolved'
              ORDER BY FIELD(a.severity, 'critical', 'warning', 'info'), a.opened_at DESC LIMIT 6",
            [$this->companyId],
        );
        $total = (int) Database::value("SELECT COUNT(*) FROM alerts WHERE company_id = ? AND status <> 'resolved'", [$this->companyId]);
        if ($rows === []) {
            return [
                'text' => $this->say->t('No open alerts. Everything EnergyFlow watches is within its normal range.', 'Nuk ka alarme të hapura. Gjithçka që EnergyFlow mbikëqyr është brenda kufijve normalë.'),
                'sources' => [['label' => $this->say->t('Alerts', 'Alarmet'), 'to' => '/waste?tab=alerts']],
                'actions' => [],
            ];
        }
        $severity = ['critical' => $this->say->t('Critical', 'Kritike'), 'warning' => $this->say->t('Warning', 'Paralajmërim'), 'info' => $this->say->t('Info', 'Info')];
        $text = $total === 1
            ? $this->say->t('1 open alert:', '1 alarm i hapur:')
            : sprintf($this->say->t('%d open alerts:', '%d alarme të hapura:'), $total);
        foreach ($rows as $r) {
            $p = json_decode((string) $r['params'], true) ?: [];
            $text .= "\n- **{$severity[$r['severity']]}** · " . $this->alertText($r['title_key'], $p, $r['machine_code'], $r['device_serial']);
        }
        return [
            'text' => $text,
            'sources' => [['label' => $this->say->t('Alerts', 'Alarmet'), 'to' => '/waste?tab=alerts']],
            'actions' => [['type' => 'navigate', 'to' => '/waste?tab=alerts', 'page' => 'waste']],
        ];
    }

    private function opportunities(): array
    {
        $recs = Recommendations::list($this->companyId, 'open');
        if ($recs === []) {
            return [
                'text' => $this->say->t('There are no open opportunities right now: everything EnergyFlow found has been acted on or dismissed.', 'Nuk ka mundësi të hapura tani: gjithçka që gjeti EnergyFlow është zbatuar ose është hedhur poshtë.'),
                'sources' => [['label' => $this->say->t('Opportunities', 'Mundësitë'), 'to' => '/opportunities']],
                'actions' => [],
            ];
        }
        $text = $this->say->t('The most valuable changes, ranked by € and CO₂ per effort:', 'Ndryshimet me më shumë vlerë, të renditura sipas € dhe CO₂ për mundin:');
        foreach (array_slice($recs, 0, 3) as $i => $r) {
            $tag = $r['impact_tag'] === 'eur' ? $this->say->t(' · € only, no CO₂ change', ' · vetëm €, pa ndryshim CO₂') : '';
            $text .= "\n" . ($i + 1) . '. **' . $this->recTitle($r) . '**: ' . $this->say->eur($r['per_month']['eur']) . $this->say->t(' / month', ' / muaj')
                . ', ' . $this->say->kwh($r['per_month']['kwh']) . ', ' . $this->say->co2($r['per_month']['co2_kg']) . $tag;
        }
        $sum = array_sum(array_map(static fn (array $r): float => $r['per_month']['eur'], $recs));
        $text .= "\n\n" . sprintf($this->say->t('All %d open opportunities together: about %s per month.', 'Të %d mundësitë e hapura së bashku: rreth %s në muaj.'), count($recs), $this->say->eur($sum));
        if (array_filter($recs, static fn (array $r): bool => $r['impact_tag'] === 'eur') !== []) {
            $text .= ' ' . $this->say->t(
                'Moving load to the night tariff saves money but not CO₂, because Kosovo\'s grid factor is an annual average.',
                'Zhvendosja e ngarkesës në tarifën e natës kursen para, por jo CO₂, sepse faktori i rrjetit të Kosovës është mesatare vjetore.',
            );
        }
        $first = $recs[0];
        return [
            'text' => $text,
            'sources' => [['label' => $this->say->t('Opportunities · replayed on your own data', 'Mundësitë · të simuluara mbi të dhënat tuaja'), 'to' => '/opportunities']],
            'actions' => [
                ['type' => 'navigate', 'to' => '/opportunities', 'page' => 'opportunities'],
                ['type' => 'ask', 'text' => sprintf($this->say->t('Why is "%s" first?', 'Pse është e para "%s"?'), $this->recTitle($first))],
            ],
        ];
    }

    private function savings(): array
    {
        $impact = ImpactService::summary($this->companyId);
        $v = $impact['verified'];
        $source = [['label' => $this->say->t('Impact · adjusted baseline, 90% confidence', 'Ndikimi · bazë e rregulluar, besueshmëri 90%'), 'to' => '/impact']];
        if ($impact['interventions'] === []) {
            return [
                'text' => $this->say->t('No action has been measured long enough yet. Once an automation or repair runs for 7 days, EnergyFlow verifies its savings against an adjusted baseline.', 'Asnjë veprim nuk është matur ende mjaftueshëm. Kur një automatizim ose riparim punon 7 ditë, EnergyFlow i verifikon kursimet kundrejt një baze të rregulluar.'),
                'sources' => $source,
                'actions' => [['type' => 'navigate', 'to' => '/opportunities', 'page' => 'opportunities']],
            ];
        }
        $text = sprintf(
            $this->say->t('Verified savings so far: **%s**, **%s** and **%s** avoided.', 'Kursimet e verifikuara deri tani: **%s**, **%s** dhe **%s** të shmangura.'),
            $this->say->kwh($v['kwh']), $this->say->eur($v['eur']), $this->say->co2($v['co2_kg']),
        );
        foreach ($impact['interventions'] as $iv) {
            $status = $iv['verified'] ? $this->say->t('verified', 'e verifikuar') : ($iv['collecting'] ? $this->say->t('collecting data', 'po mblidhen të dhëna') : $this->say->t('not yet significant', 'ende jo domethënëse'));
            $text .= "\n- **{$iv['machine']['code']}** " . $this->interventionLabel($iv) . ': ' . $this->say->kwh($iv['savings']['kwh'])
                . ' ± ' . $this->say->number($iv['savings']['ci90_kwh']) . ' kWh, ' . $this->say->eur($iv['savings']['eur']) . " ({$status})";
        }
        $text .= "\n\n" . $this->say->t(
            'Each saving compares what the meters measured with what the machine would have used on the same kind of days without the action.',
            'Çdo kursim krahason atë që matën matësit me atë që makineria do të kishte konsumuar në të njëjtat lloje ditësh pa veprimin.',
        );
        return ['text' => $text, 'sources' => $source, 'actions' => [['type' => 'navigate', 'to' => '/impact', 'page' => 'impact']]];
    }

    private function carbon(Period $period): array
    {
        $c = CarbonReport::summary($this->companyId, $period);
        $f = $c['factor'];
        $text = sprintf(
            $this->say->t('**%s**: **%s** from electricity (Scope 2, location-based), from %s at %s kg CO₂e/kWh.', '**%s**: **%s** nga energjia elektrike (Scope 2, sipas vendndodhjes), nga %s me %s kg CO₂e/kWh.'),
            $this->periodLabel($period), $this->say->co2($c['scope2_location_kg']), $this->say->kwh($c['electricity_kwh']), $this->say->number($f['value'], 3),
        );
        if ($c['previous']['change_ratio'] !== null) {
            $text .= ' ' . sprintf($this->say->t('Previous period: %s (%s).', 'Periudha e mëparshme: %s (%s).'), $this->say->co2($c['previous']['scope2_location_kg']), $this->say->change($c['previous']['change_ratio']));
        }
        if ($c['avoided_verified_kg'] > 0) {
            $text .= "\n\n" . sprintf($this->say->t('Verified actions have avoided %s so far.', 'Veprimet e verifikuara kanë shmangur %s deri tani.'), $this->say->co2($c['avoided_verified_kg']));
        }
        $text .= "\n\n" . match ($c['scope1']['status']) {
            'missing' => $this->say->t('Scope 1 (fuels burned on site) is not reported yet: declare it in Carbon & ESG → Readiness.', 'Scope 1 (karburantet e djegura në vend) ende nuk është raportuar: deklarojeni te Karboni & ESG → Gatishmëria.'),
            'declared_none' => $this->say->t('Scope 1: declared none (no fuel burned on site).', 'Scope 1: deklaruar zero (pa djegie karburanti në vend).'),
            default => sprintf($this->say->t('Scope 1 (declared fuels): %s.', 'Scope 1 (karburantet e deklaruara): %s.'), $this->say->co2($c['scope1']['kg'])),
        };
        $text .= ' ' . sprintf($this->say->t('Factor: %s (%s).', 'Faktori: %s (%s).'), $f['source_name'], $f['methodology']);
        return [
            'text' => $text,
            'sources' => [$this->source($this->say->t('Carbon & ESG', 'Karboni & ESG'), $period, '/carbon')],
            'actions' => [
                ['type' => 'ask', 'text' => $this->say->t('How is our CO₂ calculated?', 'Si llogaritet CO₂ jonë?')],
                ['type' => 'navigate', 'to' => '/carbon', 'page' => 'carbon'],
            ],
        ];
    }

    private function forecast(): array
    {
        $o = OverviewService::summary($this->companyId);
        if (!($o['has_data'] ?? false)) {
            return $this->noData(null);
        }
        $p = $o['projection'];
        $month = $this->say->month($this->time->format($this->now, 'Y-m'));
        $text = sprintf(
            $this->say->t('At the current pace, %s should end at about **%s** and %s.', 'Me ritmin aktual, %s pritet të mbyllet me rreth **%s** dhe %s.'),
            $month, $this->say->kwh($p['kwh']), $this->say->co2($p['co2_kg']),
        );
        if ($p['bill'] !== null) {
            $text .= ' ' . sprintf(
                $this->say->t('Expected bill: **%s** excl. VAT (%s with VAT).', 'Fatura e pritshme: **%s** pa TVSH (%s me TVSH).'),
                $this->say->eur($p['bill']['subtotal']), $this->say->eur($p['bill']['total']),
            );
        }
        if (($p['range'] ?? null) !== null && $p['range']['bill_p10'] !== null) {
            $text .= ' ' . sprintf(
                $this->say->t('Likely range: %s – %s (%s – %s).', 'Intervali i mundshëm: %s – %s (%s – %s).'),
                $this->say->eur($p['range']['bill_p10']), $this->say->eur($p['range']['bill_p90']), $this->say->kwh($p['range']['kwh_p10']), $this->say->kwh($p['range']['kwh_p90']),
            );
        }
        $text .= "\n\n" . sprintf(
            $this->say->t('So far: %s, %s month-to-date vs the same days last month.', 'Deri tani: %s, %s krahasuar me të njëjtat ditë të muajit të kaluar.'),
            $this->say->kwh($o['month']['kwh']), $this->say->change($o['month']['change_ratio']),
        );
        $text .= ' ' . $this->say->t(
            'Method: actual consumption so far plus, for each remaining day, the average of the same kind of day over the last 28 days.',
            'Metoda: konsumi aktual deri tani plus, për çdo ditë të mbetur, mesatarja e të njëjtit lloj dite në 28 ditët e fundit.',
        );
        if (($p['backtest']['wape'] ?? null) !== null) {
            $text .= ' ' . sprintf(
                $this->say->t('Tested on the last %d days: typical error %s.', 'Testuar në %d ditët e fundit: gabimi tipik %s.'),
                $p['backtest']['days'], $this->say->pct($p['backtest']['wape']),
            );
        }
        return [
            'text' => $text,
            'sources' => [['label' => $this->say->t('Month-end projection · Overview', 'Parashikimi për fund të muajit · Përmbledhje'), 'to' => '/']],
            'actions' => [['type' => 'ask', 'text' => $this->say->t('What should we change tomorrow?', 'Çfarë duhet të ndryshojmë nesër?')]],
        ];
    }

    private function score(): array
    {
        $s = \EnergyFlow\Services\Analytics\ScoreService::summary($this->companyId);
        if ($s['score'] === null) {
            return $this->noData(null);
        }
        $names = [
            'waste' => ['Waste', 'Humbjet'], 'schedule' => ['Schedule discipline', 'Disiplina e orarit'], 'health' => ['Equipment health', 'Gjendja e pajisjeve'],
            'peak' => ['Peak management', 'Menaxhimi i pikut'], 'follow_through' => ['Follow-through', 'Veprimi i ndërmarrë'], 'coverage' => ['Data coverage', 'Mbulimi me të dhëna'],
        ];
        $text = sprintf($this->say->t('Your **EnergyFlow Score** is **%s / 100** (last 7 days)', '**Pikët EnergyFlow** janë **%s / 100** (7 ditët e fundit)'), $this->say->number($s['score']));
        if ($s['change'] !== null) {
            $text .= sprintf($this->say->t(', %s points vs the week before.', ', %s pikë krahasuar me javën më parë.'), ($s['change'] >= 0 ? '+' : '−') . $this->say->number(abs($s['change']), 1));
        } else {
            $text .= '.';
        }
        foreach ($s['parts'] as $key => $part) {
            [$en, $sq] = $names[$key];
            $text .= "
- " . $this->say->t($en, $sq) . ': ' . ($part['value'] === null ? '—' : $this->say->number($part['value'])) . ' (' . $this->say->pct($part['weight'], 0) . ')';
        }
        $actions = [];
        if ($s['next'] !== null) {
            [$en, $sq] = $names[$s['next']['key']];
            $text .= "

" . sprintf($this->say->t('Biggest gain next: **%s**, up to %s points.', 'Fitimi më i madh më pas: **%s**, deri në %s pikë.'), $this->say->t($en, $sq), $this->say->number($s['next']['points']));
            $actions[] = ['type' => 'ask', 'text' => $s['next']['key'] === 'waste' ? $this->say->t('How much did we waste this week?', 'Sa humbëm këtë javë?') : $this->say->t('What should we change tomorrow?', 'Çfarë duhet të ndryshojmë nesër?')];
        }
        $actions[] = ['type' => 'navigate', 'to' => '/', 'page' => 'overview'];
        return ['text' => $text, 'sources' => [['label' => $this->say->t('EnergyFlow Score · Overview', 'Pikët EnergyFlow · Përmbledhje'), 'to' => '/']], 'actions' => $actions];
    }

    // ---------------------------------------------------------------- helpers

    private function reply(string $text, array $actions): array
    {
        return ['text' => $text, 'sources' => [], 'actions' => $actions];
    }

    private function noData(?Period $period): array
    {
        return $this->reply(
            $period === null
                ? $this->say->t('There are no measurements to answer that yet.', 'Ende nuk ka matje për t\'iu përgjigjur kësaj.')
                : sprintf($this->say->t('There are no measurements for %s.', 'Nuk ka matje për %s.'), mb_strtolower($this->periodLabel($period))),
            [['type' => 'navigate', 'to' => '/devices', 'page' => 'devices']],
        );
    }

    private function period(string $key): Period
    {
        try {
            return Period::parse($key, $this->now, $this->time);
        } catch (\Throwable) {
            return Period::parse('mtd', $this->now, $this->time);
        }
    }

    private function oneMachine(string $question, array $context): ?array
    {
        $found = MachineMatcher::find($this->companyId, $question);
        if (count($found) === 1) {
            return $found[0];
        }
        return null;
    }

    /** @return list<array{id: int, code: string, name: string, kwh: float, eur: float, share: float}> */
    private function machineRows(array $by, float $siteKwh, ?TariffBook $tariff, int $limit): array
    {
        $rows = [];
        foreach (Database::all("SELECT id, code, name FROM machines WHERE company_id = ? AND kind = 'machine' AND archived_at IS NULL", [$this->companyId]) as $m) {
            $e = $by[(int) $m['id']] ?? null;
            if ($e === null) {
                continue;
            }
            $rows[] = ['id' => (int) $m['id'], 'code' => $m['code'], 'name' => $m['name'], 'kwh' => $e['kwh'], 'eur' => EnergyQuery::energyCost($e, $tariff),
                'share' => $siteKwh > 0 ? $e['kwh'] / $siteKwh : 0.0];
        }
        usort($rows, static fn (array $a, array $b): int => $b['kwh'] <=> $a['kwh']);
        return array_slice($rows, 0, $limit);
    }

    public function periodLabel(Period $period): string
    {
        if (str_starts_with($period->key, 'month:')) {
            $month = $this->say->month(substr($period->key, 6));
            return mb_strtoupper(mb_substr($month, 0, 1)) . mb_substr($month, 1);
        }
        return match ($period->key) {
            'today' => $this->say->t('Today so far', 'Sot deri tani'),
            'yesterday' => $this->say->t('Yesterday', 'Dje'),
            '7d' => $this->say->t('Last 7 days', '7 ditët e fundit'),
            '30d' => $this->say->t('Last 30 days', '30 ditët e fundit'),
            'ytd' => $this->say->t('This year so far', 'Këtë vit deri tani'),
            default => $this->say->t('This month so far', 'Këtë muaj deri tani'),
        };
    }

    /** The waste page's own period choices. */
    private function pagePeriod(Period $period): string
    {
        return in_array($period->key, ['today', '7d', '30d', 'mtd', 'ytd'], true) || str_starts_with($period->key, 'month:') ? $period->key : 'mtd';
    }

    private function source(string $what, Period $period, string $to): array
    {
        $from = $this->say->day($this->time->date($period->from));
        $last = $this->say->day($this->time->date(max($period->from, $period->to - 1)));
        return ['label' => $what . ' · ' . ($from === $last ? $from : "{$from} – {$last}"), 'to' => $to];
    }

    private function wasteType(string $type): string
    {
        return match ($type) {
            'after_hours' => $this->say->t('After hours', 'Pas orarit'),
            'idle' => $this->say->t('Idle in production', 'Në pritje gjatë prodhimit'),
            'excess_vs_baseline' => $this->say->t('Efficiency drift', 'Rënie e efikasitetit'),
            default => $type,
        };
    }

    private function alertText(string $key, array $p, ?string $code, ?string $serial): string
    {
        $machine = $code ?? ($p['code'] ?? '');
        $money = isset($p['kwh'], $p['eur']) ? ' (' . $this->say->kwh((float) $p['kwh']) . ', ' . $this->say->eur((float) $p['eur']) . ')' : '';
        return match ($key) {
            'after_hours' => sprintf($this->say->t('%s ran after hours', '%s punoi pas orarit'), $machine) . (($p['leak'] ?? false) ? $this->say->t(', air-leak signature', ', shenjë rrjedhjeje ajri') : '') . $money,
            'idle' => sprintf($this->say->t('%s idled during production', '%s qëndroi në pritje gjatë prodhimit'), $machine) . $money,
            'drift' => sprintf($this->say->t('%s: running power %s above its reference', '%s: fuqia gjatë punës %s mbi referencën'), $machine, $this->say->number((float) ($p['deviation_pct'] ?? 0), 1) . '%') . $money,
            'device_offline' => sprintf($this->say->t('%s stopped reporting', '%s ndaloi raportimin'), $serial ?? ($p['serial'] ?? '')),
            'spike' => sprintf($this->say->t('%s: power spike to %s kW (normal peak %s kW)', '%s: kulm fuqie deri në %s kW (kulmi normal %s kW)'), $machine,
                $this->say->number((float) ($p['peak_kw'] ?? 0), 1), $this->say->number((float) ($p['normal_kw'] ?? 0), 1))
                . (($p['overload'] ?? false) ? sprintf($this->say->t(', %s%% of its rating', ', %s%% e fuqisë nominale'), $this->say->number((float) $p['rated_pct'], 0)) : ''),
            'command_failed' => sprintf($this->say->t('Turn Off not confirmed for %s', 'Fikja nuk u konfirmua për %s'), $machine),
            default => $key,
        };
    }

    public function recTitle(array $r): string
    {
        $p = $r['params'] ?? [];
        $code = $p['code'] ?? ($r['machine']['code'] ?? '');
        return match ($r['title_key']) {
            'after_hours_schedule' => sprintf($this->say->t('Switch %s off automatically after the shift', 'Fik %s automatikisht pas ndërrimit'), $code),
            'compressed_air_leak' => sprintf($this->say->t('Find and fix compressed-air leaks on %s', 'Gjej dhe riparo rrjedhjet e ajrit në %s'), $code),
            'efficiency_drift' => sprintf($this->say->t('Service %s: it needs %s%% more power', 'Servisim për %s: kërkon %s%% më shumë fuqi'), $code, $this->say->number((float) ($p['pct'] ?? 0), 1)),
            'tou_shift' => sprintf($this->say->t('Run %s in the night tariff', 'Puno me %s në tarifën e natës'), $code),
            default => $r['title_key'],
        };
    }

    private function interventionLabel(array $iv): string
    {
        if ($iv['kind'] === 'policy') {
            $grace = $iv['policy_params']['grace_min'] ?? null;
            return $grace === null
                ? $this->say->t('automatic switch-off after the schedule', 'fikje automatike pas orarit')
                : sprintf($this->say->t('auto-off %d min after the schedule', 'fikje automatike %d min pas orarit'), (int) $grace);
        }
        return $iv['recommendation'] !== null ? $this->recTitle($iv['recommendation'] + ['machine' => $iv['machine']]) : (string) $iv['label'];
    }

    /** The page's name as the navigation shows it. */
    private function pageName(string $route): string
    {
        $names = [
            'overview' => ['Overview', 'Përmbledhje'], 'live' => ['Live', 'Live'], 'machines' => ['Machines', 'Makineritë'],
            'devices' => ['Devices', 'Pajisjet'], 'scan' => ['Scan', 'Skano'], 'waste' => ['Waste & Alerts', 'Humbjet & alarmet'], 'opportunities' => ['Opportunities', 'Mundësitë'],
            'automations' => ['Automations', 'Automatizimet'], 'impact' => ['Impact', 'Ndikimi'], 'carbon' => ['Carbon & ESG', 'Karboni & ESG'],
            'reports' => ['Reports', 'Raportet'], 'settings' => ['Settings', 'Cilësimet'],
        ];
        [$en, $sq] = $names[self::pageKey($route)] ?? [$route, $route];
        return $this->say->t($en, $sq);
    }

    public static function pageKey(string $route): string
    {
        return match (true) {
            $route === '/' => 'overview',
            default => ltrim(explode('?', $route)[0], '/'),
        };
    }
}
