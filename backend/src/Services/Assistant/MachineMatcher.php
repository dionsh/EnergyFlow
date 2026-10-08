<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Assistant;

use EnergyFlow\Core\Database;

/**
 * Finds the machine(s) a question is about: by code ("CMP-01", "cmp 1"), by the
 * words of its name, or by its type in English or Albanian ("the compressor",
 * "kompresori"). Two machines of one type ("the moulder") come back as two.
 */
final class MachineMatcher
{
    /** Type words, matched as word prefixes (so "kompresorin", "lights" match). */
    private const TYPE_WORDS = [
        'compressor' => ['compressor', 'kompresor', 'air compressor'],
        'injection_moulding' => ['moulder', 'molder', 'moulding', 'molding', 'injection', 'derdh', 'shtyp', 'presë', 'prese'],
        'chiller' => ['chiller', 'ftohës', 'ftohes', 'ftohje'],
        'hvac' => ['heat pump', 'hvac', 'heating', 'air conditioning', 'ngrohj', 'klim', 'pompa e nxehtësisë', 'pompë nxehtësie'],
        'pump' => ['pump', 'pomp'],
        'lighting' => ['light', 'lighting', 'lamp', 'drit', 'ndriçim', 'ndricim', 'llamb'],
        'office' => ['office', 'zyr'],
        'refrigeration' => ['fridge', 'freezer', 'refrigerat', 'frigorifer', 'ngrir'],
        'oven' => ['oven', 'furr'],
        'cnc' => ['cnc', 'lathe', 'torno'],
    ];

    /** @return list<array{id: int, code: string, name: string, type: string}> */
    public static function find(int $companyId, string $text): array
    {
        $machines = Database::all(
            "SELECT id, code, name, type_code FROM machines WHERE company_id = ? AND kind = 'machine' AND archived_at IS NULL ORDER BY code",
            [$companyId],
        );
        $lower = ' ' . mb_strtolower($text) . ' ';

        // 1. A code, written any way: CMP-01 · cmp 01 · cmp1 · CMP-1.
        $byCode = [];
        foreach ($machines as $m) {
            $pattern = preg_match('/^([a-z]+)[\-_ ]?0*(\d+)$/i', $m['code'], $parts)
                ? '/(?<![\p{L}\d])' . preg_quote(strtolower($parts[1]), '/') . '[\s\-_.]*0*' . $parts[2] . '(?!\d)/u'
                : '/(?<![\p{L}\d])' . preg_quote(mb_strtolower($m['code']), '/') . '(?![\p{L}\d])/u';
            if (preg_match($pattern, $lower)) {
                $byCode[] = $m;
            }
        }
        if ($byCode !== []) {
            return array_map(self::present(...), $byCode);
        }

        // 2. Its type in either language. Longer phrases are consumed first, so
        //    "heat pump" means the HVAC unit, not also the water pump.
        $types = [];
        $rest = $lower;
        foreach (self::TYPE_WORDS as $type => $words) {
            foreach ($words as $word) {
                $pattern = '/(?<![\p{L}])' . preg_quote($word, '/') . '\p{L}*/u';
                if (preg_match($pattern, $rest)) {
                    $types[$type] = true;
                    $rest = preg_replace($pattern, ' ', $rest) ?? $rest;
                }
            }
        }
        $found = [];
        foreach ($machines as $m) {
            if (isset($types[$m['type_code']])) {
                $found[$m['id']] = $m;
            }
        }
        // 3. A distinctive word of the machine's own name.
        if ($found === []) {
            foreach ($machines as $m) {
                foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($m['name']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    if (mb_strlen($word) >= 5 && preg_match('/(?<![\p{L}])' . preg_quote($word, '/') . '/u', $lower)) {
                        $found[$m['id']] = $m;
                    }
                }
            }
        }
        return array_map(self::present(...), array_values($found));
    }

    private static function present(array $m): array
    {
        return ['id' => (int) $m['id'], 'code' => $m['code'], 'name' => $m['name'], 'type' => $m['type_code']];
    }
}
