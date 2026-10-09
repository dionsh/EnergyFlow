<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Scan;

use EnergyFlow\Core\Env;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\Assistant\GroqClient;

/**
 * Reads a photo into fields (Groq vision model, JSON out). It only transcribes:
 * every judgement — is the bill consistent, is the meter in line with EnergyFlow —
 * is made afterwards by plain code (ScanCheck), and the user sees and can correct
 * every field first. The photo is sent to Groq for reading and never stored.
 */
final class ScanReader
{
    public const KINDS = ['bill', 'meter', 'nameplate', 'fuel', 'label'];
    private const MAX_BYTES = 1_000_000; // the API's body limit; the browser compresses photos below it

    /** Field → type. 'date' = YYYY-MM-DD, 'number' = plain decimal. */
    public const FIELDS = [
        'bill' => [
            'supplier' => 'string', 'customer_number' => 'string', 'issue_date' => 'date', 'period_start' => 'date', 'period_end' => 'date',
            'due_date' => 'date', 'kwh_high' => 'number', 'kwh_low' => 'number', 'kwh_total' => 'number', 'meter_start' => 'number',
            'meter_end' => 'number', 'net_eur' => 'number', 'vat_eur' => 'number', 'total_eur' => 'number', 'previous_debt_eur' => 'number',
        ],
        'meter' => ['meter_serial' => 'string', 'reading_kwh' => 'number', 'register' => 'string', 'reading_t1' => 'number', 'reading_t2' => 'number'],
        'nameplate' => [
            'manufacturer' => 'string', 'model' => 'string', 'serial' => 'string', 'year' => 'number', 'rated_power_kw' => 'number',
            'rated_power_hp' => 'number', 'voltage_v' => 'number', 'current_a' => 'number', 'frequency_hz' => 'number', 'phases' => 'number',
            'power_factor' => 'number', 'efficiency_class' => 'string', 'efficiency_pct' => 'number', 'speed_rpm' => 'number',
        ],
        'fuel' => ['vendor' => 'string', 'date' => 'date', 'fuel' => 'string', 'litres' => 'number', 'unit_price_eur' => 'number', 'total_eur' => 'number', 'vat_eur' => 'number'],
        'label' => ['product_type' => 'string', 'brand' => 'string', 'model' => 'string', 'energy_class' => 'string', 'kwh_per_year' => 'number', 'consumption_unit' => 'string'],
    ];

    private const PROMPTS = [
        'bill' => 'This should be a photo of an electricity bill (likely from Kosovo, in Albanian, Serbian or English; KESCO is the main supplier). '
            . 'Fields: supplier = the company that issued the bill; customer_number = the customer/account number; issue_date; period_start and period_end = the billing period; due_date; '
            . 'kwh_high = consumption in the high/day tariff (tarifa e lartë, T1); kwh_low = low/night tariff (tarifa e ulët, T2); kwh_total = total consumption of the period; '
            . 'meter_start / meter_end = previous and current meter readings; net_eur = amount for this period before VAT (TVSH); vat_eur = the VAT amount; '
            . 'total_eur = amount for this period including VAT, WITHOUT any previous debt; previous_debt_eur = unpaid balance carried from earlier bills, if shown.',
        'meter' => 'This should be a photo of an electricity meter display. Fields: meter_serial = the meter number printed on it; reading_kwh = the active energy reading shown on the display in kWh; '
            . 'register = the register shown next to the reading (e.g. "1.8.0", "T1", "T2", "total"); reading_t1 / reading_t2 = tariff registers if both are visible.',
        'nameplate' => 'This should be a photo of a machine or electric motor nameplate (rating plate). Fields: manufacturer; model (type); serial; year; '
            . 'rated_power_kw = rated power in kW; rated_power_hp = rated power in HP if printed; voltage_v (if two, e.g. 230/400 V Δ/Y, give the higher); current_a (the current that goes with that voltage); '
            . 'frequency_hz; phases (1 or 3); power_factor = cos φ; efficiency_class = IE class such as IE2, IE3; efficiency_pct; speed_rpm.',
        'fuel' => 'This should be a photo of a fuel receipt. Fields: vendor = the station or seller; date; fuel = one of diesel, petrol, gas_oil, lpg, heating_oil, other; '
            . 'litres = quantity in litres; unit_price_eur = price per litre; total_eur = total paid; vat_eur = VAT amount if printed.',
        'label' => 'This should be a photo of an EU energy label (or a product sheet with its energy data). Fields: product_type (e.g. refrigerator, lamp, air conditioner, washing machine, display); '
            . 'brand; model; energy_class = the class letter A to G; kwh_per_year = the energy consumption figure printed on the label; consumption_unit = its unit as printed, e.g. "kWh/annum", "kWh/1000h", "kWh/100 cycles".',
    ];

    /**
     * @return array{recognised: bool, fields: array<string, mixed>, model: ?string, latency_ms: int}
     */
    public static function read(string $kind, string $dataUrl): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw HttpException::validation(['kind' => 'invalid_choice']);
        }
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m) || strlen($m[2]) > self::MAX_BYTES) {
            throw HttpException::validation(['image' => 'invalid_image']);
        }
        if (!GroqClient::configured()) {
            throw new HttpException(503, 'scan_unavailable', 'Reading photos needs the AI service, which is not configured on this server.');
        }

        $keys = array_keys(self::FIELDS[$kind]);
        $schema = '{"recognised": true or false, ' . implode(', ', array_map(static fn (string $k): string => "\"{$k}\": ...", $keys)) . '}';
        $prompt = self::PROMPTS[$kind] . "\n\nReply with one JSON object only: {$schema}. "
            . 'Set "recognised" to false if the photo is not this kind of document. Copy values exactly as printed; use null for anything not visible or unreadable — never guess. '
            . 'Numbers: plain decimals with a dot and no thousands separators or units (e.g. 1234.56). Dates: YYYY-MM-DD.';

        $result = GroqClient::chat(
            [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ]]],
            ['models' => [Env::get('GROQ_VISION_MODEL', 'qwen/qwen3.8-27b')], 'json' => true, 'temperature' => 0.0, 'max_tokens' => 900, 'timeout' => 40],
        );
        if (!$result['ok']) {
            throw new HttpException(str_starts_with((string) $result['error'], 'http_429') ? 429 : 502, 'scan_failed', 'The photo could not be read right now.');
        }
        $json = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($result['text'])) ?? '', true);
        if (!is_array($json)) {
            throw new HttpException(502, 'scan_failed', 'The photo could not be read right now.');
        }
        return [
            'recognised' => ($json['recognised'] ?? true) !== false,
            'fields' => self::normalise($kind, $json),
            'model' => $result['model'],
            'latency_ms' => $result['latency_ms'],
        ];
    }

    /** Types every field (the user's corrections go through this too). */
    public static function normalise(string $kind, array $input): array
    {
        $out = [];
        foreach (self::FIELDS[$kind] as $key => $type) {
            $value = $input[$key] ?? null;
            $out[$key] = match ($type) {
                'number' => self::number($value),
                'date' => self::date($value),
                default => is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, 120) : null,
            };
        }
        return $out;
    }

    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $clean = preg_replace('/[^\d.,\-]/', '', $value) ?? '';
        // "1.234,56" and "1,234.56": the last separator is the decimal one.
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = strrpos($clean, ',') > strrpos($clean, '.') ? str_replace(['.', ','], ['', '.'], $clean) : str_replace(',', '', $clean);
        } else {
            $clean = str_replace(',', '.', $clean);
        }
        return is_numeric($clean) ? (float) $clean : null;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y', 'd.m.y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }
        return null;
    }
}
