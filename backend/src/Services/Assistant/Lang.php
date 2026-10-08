<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Assistant;

/**
 * Which language a question is written in, so the answer mirrors it. Albanian
 * and English are told apart by letters (ë, ç) and very common words; anything
 * undecided keeps the interface language.
 */
final class Lang
{
    private const SQ = ['sa', 'cila', 'cili', 'çfarë', 'cfare', 'çka', 'cka', 'pse', 'si', 'ku', 'kur', 'sot', 'dje', 'muaj', 'muajin',
        'javë', 'jave', 'javën', 'vit', 'makineri', 'makineria', 'makinerin', 'energji', 'energjia', 'kemi', 'jemi', 'është', 'eshte',
        'për', 'per', 'në', 'më', 'dhe', 'apo', 'tani', 'hap', 'fik', 'shko', 'trego', 'kursim', 'kursyem', 'humbje',
        'humbëm', 'humbem', 'harxhon', 'konsumon', 'konsumuam', 'kushton', 'fatura', 'faturë', 'emetime', 'mirëdita', 'përshëndetje',
        'faleminderit', 'rekomandime', 'alarme', 'punon', 'ndezur', 'shumë', 'shume', 'këtë', 'kete', 'ka', 'janë', 'jane', 'çka'];
    private const EN = ['how', 'what', 'which', 'why', 'when', 'where', 'who', 'much', 'many', 'the', 'is', 'are', 'was', 'were', 'did',
        'do', 'does', 'our', 'we', 'us', 'my', 'today', 'yesterday', 'week', 'month', 'year', 'machine', 'machines', 'energy', 'cost',
        'costs', 'use', 'used', 'waste', 'save', 'saved', 'bill', 'open', 'show', 'turn', 'off', 'running', 'now', 'hello', 'hi',
        'thanks', 'should', 'can', 'could', 'most', 'this', 'last', 'and', 'or', 'of', 'in', 'on', 'me', 'write', 'tell',
        'give', 'please', 'you', 'your', 'it', 'about', 'for', 'with', 'from'];

    public static function detect(string $text, string $fallback): string
    {
        $lower = mb_strtolower($text);
        $sq = preg_match_all('/[ëç]/u', $lower) * 2;
        $en = 0;
        foreach (preg_split('/[^\p{L}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $sq += in_array($word, self::SQ, true) ? 1 : 0;
            $en += in_array($word, self::EN, true) ? 1 : 0;
        }
        if ($sq === $en) {
            return $fallback;
        }
        return $sq > $en ? 'sq' : 'en';
    }
}
