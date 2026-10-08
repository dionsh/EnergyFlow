<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Assistant;

/**
 * Numbers and short phrases for the assistant's own (non-LLM) answers, written
 * the way the interface writes them (frontend/src/lib/format.js and
 * i18n/languages.js): 1,234 kWh · €12.50 in English, 1 234 kWh · 12,50 € in Albanian.
 */
final class Say
{
    public const MONTHS = [
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'sq' => ['janar', 'shkurt', 'mars', 'prill', 'maj', 'qershor', 'korrik', 'gusht', 'shtator', 'tetor', 'nëntor', 'dhjetor'],
    ];

    public function __construct(public readonly string $lang)
    {
    }

    /** Picks the phrase for the current language: $say->t('Hello', 'Përshëndetje'). */
    public function t(string $en, string $sq): string
    {
        return $this->lang === 'sq' ? $sq : $en;
    }

    /** Up to $decimals decimals ("24%", "26.8%"), like Intl's maximumFractionDigits; $fixed keeps them all (money). */
    public function number(?float $value, int $decimals = 0, bool $fixed = false): string
    {
        if ($value === null) {
            return '—';
        }
        $decimal = $this->lang === 'sq' ? ',' : '.';
        $text = number_format($value, $decimals, $decimal, $this->lang === 'sq' ? ' ' : ',');
        if (!$fixed && $decimals > 0) {
            $text = rtrim(rtrim($text, '0'), $decimal);
        }
        return $text;
    }

    public function kwh(?float $kwh): string
    {
        return $kwh === null ? '—' : $this->number($kwh, abs($kwh) >= 100 ? 0 : 1) . ' kWh';
    }

    public function kw(?float $kw): string
    {
        return $kw === null ? '—' : $this->number($kw, abs($kw) < 1 ? 2 : 1, true) . ' kW';
    }

    public function eur(?float $eur): string
    {
        if ($eur === null) {
            return '—';
        }
        $amount = $this->number(abs($eur), abs($eur) >= 1000 ? 0 : 2, true);
        $sign = $eur < 0 ? '−' : '';
        return $this->lang === 'sq' ? "{$sign}{$amount} €" : "{$sign}€{$amount}";
    }

    public function co2(?float $kg): string
    {
        if ($kg === null) {
            return '—';
        }
        return abs($kg) >= 1000
            ? $this->number($kg / 1000, 1, true) . ' t CO₂e'
            : $this->number($kg, $kg < 10 ? 1 : 0) . ' kg CO₂e';
    }

    /** 0.123 → "12.3%" */
    public function pct(?float $ratio, int $decimals = 1): string
    {
        return $ratio === null ? '—' : $this->number($ratio * 100, $decimals) . '%';
    }

    /** "+5.8%" / "−3.1%" */
    public function change(?float $ratio): string
    {
        if ($ratio === null) {
            return '—';
        }
        return ($ratio >= 0 ? '+' : '−') . $this->pct(abs($ratio));
    }

    /** "2 h 37 min" / "45 min" */
    public function duration(int $seconds): string
    {
        $minutes = max(0, (int) round($seconds / 60));
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        return $hours > 0 ? "{$hours} h {$rest} min" : "{$rest} min";
    }

    /** "7 Oct" / "7 tetor" from Y-m-d. */
    public function day(string $date): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', substr($date, 0, 10)));
        $month = self::MONTHS[$this->lang][$m - 1];
        return $this->lang === 'sq' ? "{$d} {$month}" : $d . ' ' . substr($month, 0, 3);
    }

    /** "September 2026" / "shtator 2026" from Y-m. */
    public function month(string $ym): string
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        return self::MONTHS[$this->lang][$m - 1] . " {$y}";
    }
}
