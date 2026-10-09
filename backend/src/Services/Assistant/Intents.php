<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Assistant;

use EnergyFlow\Services\Calendar\LocalTime;

/**
 * Deterministic understanding of the questions people ask most (docs/03 §9.1),
 * in English and Albanian. These are answered straight from MySQL: no model
 * call, no cost, never a wrong number. Everything else ("why…?", "explain…")
 * goes to the LLM with a data snapshot.
 *
 * Kept deliberately narrow: a question that only *looks* like one of these is
 * better answered by the LLM than mis-answered by a keyword.
 */
final class Intents
{
    /** Pages people can ask to open, and the words that name them. */
    public const PAGES = [
        '/opportunities' => ['opportunit', 'recommendation', 'what-if', 'what if', 'simulator', 'mundësi', 'mundesi', 'rekomandim'],
        '/automations' => ['automation', 'automatizim', 'polic', 'command log', 'regjistri i komandave'],
        '/waste' => ['waste', 'alert', 'alarm', 'humbje', 'humbjet'],
        '/impact' => ['impact', 'ndikim', 'before and after', 'before/after', 'para dhe pas'],
        '/carbon' => ['carbon', 'esg', 'vsme', 'karbon', 'emission', 'emetim'],
        '/reports' => ['report', 'raport'],
        '/scan' => ['scan', 'skano', 'skanim', 'camera', 'kamer'],
        '/devices' => ['device', 'pajisje', 'sensor', 'hardware', 'meter', 'matës', 'mates'],
        '/machines' => ['machines', 'makineritë', 'makinerite', 'machine list', 'lista e makinerive'],
        '/live' => ['live', 'real time', 'real-time', 'monitor'],
        '/settings' => ['setting', 'cilësim', 'cilesim', 'konfigurim'],
        '/' => ['overview', 'dashboard', 'home', 'përmbledhje', 'permbledhje', 'ballin', 'kreu'],
    ];

    /** Clearly not about energy: refused before any model call (the "Messi" rule). */
    private const OFF_TOPIC = ['messi', 'ronaldo', 'football', 'soccer', 'futboll', 'basketball', 'basketboll', 'nba', 'champions league',
        'world cup', 'kampionat', 'ndeshj', 'tennis', 'tenis', 'election', 'zgjedhje', 'politic', 'politik', 'celebrit', 'movie', 'netflix',
        'song', 'lyrics', 'këngë', 'kenge', 'music', 'muzik', 'recipe', 'recetë', 'recete', 'gatim', 'horoscope', 'horoskop', 'joke',
        'lottery', 'lotari', 'bitcoin price', 'crypto', 'stock price', 'girlfriend', 'boyfriend', 'dating', 'homework', 'poem', 'poezi',
        'capital of', 'kryeqyteti', 'who won', 'kush fitoi', 'fitoi dje', 'win yesterday', 'won yesterday', 'actor', 'aktor', 'singer', 'këngëtar',
        'python', 'javascript', 'write code', 'write a program', 'shkruaj kod', 'essay', 'ese ', 'love', 'dashuri'];

    /** Words that keep a question in scope even if an off-topic word appears ("energy use during the match"). */
    private const ENERGY = ['energ', 'kwh', 'kw ', 'power', 'electric', 'rrym', 'machine', 'makiner', 'co2', 'co₂', 'carbon', 'karbon',
        'emission', 'emetim', 'bill', 'fatur', 'tariff', 'tarif', 'waste', 'humb', 'cost', 'kosto', 'saving', 'kursim', 'compressor',
        'kompresor', 'esg', 'vsme', 'consum', 'konsum', 'efficien', 'efikas'];

    /**
     * @return array{name: string, period: ?string, type?: ?string}|null
     */
    public static function detect(string $text, int $now, LocalTime $time): ?array
    {
        $m = ' ' . mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text)) . ' ';
        $has = static fn (array $needles): bool => self::contains($m, $needles);
        $period = self::period($m, $now, $time);
        $intent = static fn (string $name, ?string $default = null): array => ['name' => $name, 'period' => $period ?? $default];

        if ($has(self::OFF_TOPIC) && !$has(self::ENERGY)) {
            return $intent('off_topic');
        }
        $words = count(preg_split('/\s+/u', trim($m)) ?: []);
        if ($words <= 4 && $has([' hi ', ' hello ', ' hey ', 'good morning', 'good evening', 'përshëndetje', 'pershendetje', 'mirëdita', 'miredita', 'mirëmbrëma', 'tung', 'ç\'kemi', 'ckemi', 'si je'])) {
            return $intent('greeting');
        }
        if ($words <= 6 && $has(['thank', 'thanks', 'faleminderit', 'flm', 'rrofsh'])) {
            return $intent('thanks');
        }
        if ($has(['what can you do', 'what do you do', 'how can you help', 'what can i ask', 'help me use', 'çka mund të bësh', 'cka mund te besh', 'çfarë mund të bësh', 'cfare mund te besh', 'si mund të më ndihmosh', 'si mund te me ndihmosh', 'çka di me bo', 'çfarë di të bësh'])
            || trim($m) === 'help' || trim($m) === 'ndihmë' || trim($m) === 'ndihme') {
            return $intent('help');
        }

        $question = (bool) preg_match('/^ (why|when|how|what|did|does|do|was|were|is|has|have|pse|kur|si|çfarë|cfare|a u|a ka|a është|a eshte) /u', $m)
            && !$has(['can you', 'could you', 'please', 'a mund', 'mund ta', 'mund t\'']);

        // Actions: switch a machine off · open a page.
        if (!$question && $has(['turn off', 'switch off', 'shut off', 'shut down', 'power off', 'turn it off', 'switch it off', ' fik', 'fike ', 'fikni', 'fikeni', 'shuaj', 'ndal ', 'ndale ', 'ndalo '])) {
            return $intent('turn_off');
        }
        if ($has(['open alert', 'alarmet e hapura', 'alarme të hapura', 'alarme te hapura'])) {
            return $intent('alerts');
        }
        if (!$question && $has([' open ', 'go to ', 'goto ', 'take me', 'navigate', 'bring me', ' hap ', ' hape ', 'shko ', 'më dërgo', 'me dergo', 'më çoj', 'me coj', 'trego faqen'])) {
            foreach (self::PAGES as $page => $needles) {
                if ($has($needles)) {
                    return $intent('navigate:' . $page);
                }
            }
            return $intent('navigate:machine');
        }

        // Reasons and explanations are the LLM's: "why…?", "explain…", and definitions
        // ("What is Scope 2?") that aren't about this company's own numbers.
        if ($has([' why ', ' pse ', 'explain', 'shpjego', 'arsye', 'reason', 'how does', 'how do ', 'si funksionon', 'si llogarit', 'difference', 'dallim', 'mean', 'do të thotë', 'do te thote'])) {
            return null;
        }
        if (preg_match('/^ (what is|what\'s|what are|çfarë është|cfare eshte|çka është|cka eshte|çfarë janë|cfare jane) /u', $m)
            && !$has([' our ', ' we ', ' us ', ' my ', 'this ', 'today', 'month', 'week', 'year', ' now', 'forecast', 'bill', 'total', 'tonë', 'jonë', 'tanë', 'sot', 'muaj', 'javë', 'tani', 'fatur'])) {
            return null;
        }

        if ($has([' now ', 'right now', 'currently', 'at the moment', ' tani ', 'aktualisht', 'për momentin', 'per momentin'])
            && $has(['running', ' on ', 'working', 'consum', 'using', 'draw', 'ndezur', 'ndezura', 'punon', 'punojnë', 'punojne', 'harxh', 'konsum', 'po punon'])) {
            return $intent('running_now');
        }
        if ($has(['how much did we save', 'how much have we saved', 'how much we saved', 'verified saving', 'savings so far', 'saved so far', 'sa kemi kursyer', 'sa kursyem', 'sa kursejmë deri', 'kursimet e verifikuara', 'kursime të verifikuara', 'kursime te verifikuara'])) {
            return $intent('savings');
        }
        if ($has(['what should we', 'how can we save', 'how can we reduce', 'how could we save', 'ways to save', 'where can we save', 'how do we save', 'recommend', 'opportunit', 'what to change', 'what can we improve', 'çfarë duhet', 'cfare duhet', 'çka duhet', 'cka duhet', 'si mund të kursejmë', 'si mund te kursejme', 'ku mund të kursejmë', 'ku mund te kursejme', 'rekomand', 'mundësi', 'mundesi', 'çfarë të ndryshojmë', 'cfare te ndryshojme'])) {
            return $intent('opportunities');
        }
        if ($has(['waste', 'wasted', 'after hours', 'after-hours', 'outside working hours', 'humb', 'pas orarit', 'jashtë orarit', 'jashte orarit'])) {
            $afterHours = $has(['after hours', 'after-hours', 'after working hours', 'outside working hours', 'pas orarit', 'jashtë orarit', 'jashte orarit']);
            return $intent('waste', 'mtd') + ['type' => $afterHours ? 'after_hours' : null];
        }
        if ($has(['alert', 'alarm', 'problem', 'issue', 'anything wrong', 'gabim', 'paralajmërim', 'paralajmerim', 'spike', 'overload', 'mbingarkes', 'kulm fuqie'])) {
            return $intent('alerts');
        }
        if ($has(['energyflow score', 'our score', 'the score', 'my score', 'score?', 'pikët', 'piket', 'sa pikë', 'sa pike'])) {
            return $intent('score');
        }
        if ($has(['forecast', 'projection', 'end of the month', 'end of month', 'expected bill', 'bill estimate', 'estimated bill', 'parashikim', 'fund të muajit', 'fund te muajit', 'fatura e pritshme', 'fatura e parashikuar'])) {
            return $intent('forecast');
        }
        if ($has(['co2', 'co₂', 'carbon', 'emission', 'ghg', 'scope 2', 'karbon', 'emetim', 'gazra'])) {
            return $intent('carbon', 'mtd');
        }
        if ($has(['which machine', 'what machine', 'biggest consumer', 'largest consumer', 'top consumer', 'uses the most', 'costs the most', 'consumes the most', 'cila makineri', 'cilat makineri', 'konsumatori më i madh', 'konsumatori me i madh', 'harxhon më shumë', 'harxhon me shume', 'konsumon më shumë', 'konsumon me shume', 'kushton më shumë', 'kushton me shume'])) {
            return $intent('top_consumer', 'mtd');
        }
        if ($has(['how much', ' sa ', 'total', 'gjithsej']) && $has(['energy', 'electric', 'kwh', 'consum', ' use', ' used', 'spend', 'spent', 'cost', 'pay', 'bill', 'energji', 'rrym', 'konsum', 'harxh', 'kushtoi', 'kushton', 'shpenz', 'paguar', 'fatur'])) {
            return $intent('consumption', 'mtd');
        }
        return null;
    }

    /** The period a question names, as a Period key; null when it names none. */
    public static function period(string $m, int $now, LocalTime $time): ?string
    {
        $has = static fn (array $needles): bool => self::contains($m, $needles);
        if ($has(['yesterday', ' dje ', ' dje?', 'dje.'])) {
            return 'yesterday';
        }
        if ($has(['today', ' sot', 'this morning', 'sonte', 'tonight', 'sot?'])) {
            return 'today';
        }
        if ($has(['last month', 'previous month', 'muajin e kaluar', 'muaji i kaluar', 'muajit të kaluar', 'muajit te kaluar'])) {
            return 'month:' . $time->format($time->startOfMonth($time->startOfMonth($now) - 86400), 'Y-m');
        }
        foreach ([Say::MONTHS['en'], Say::MONTHS['sq']] as $names) {
            foreach ($names as $index => $name) {
                if ($name === 'May') {
                    continue; // the English modal verb
                }
                if (preg_match('/(?<![\p{L}])' . preg_quote(mb_strtolower($name), '/') . '(?![\p{L}])/u', $m)) {
                    $year = (int) $time->format($now, 'Y');
                    $month = $index + 1;
                    if ($month > (int) $time->format($now, 'n')) {
                        $year--; // "in November" asked in October means last year's
                    }
                    return sprintf('month:%04d-%02d', $year, $month);
                }
            }
        }
        if ($has(['this week', 'last week', 'past week', 'last 7 days', '7 days', 'këtë javë', 'kete jave', 'javën e kaluar', 'javen e kaluar', 'javë', 'jave', '7 ditë', '7 dite'])) {
            return '7d';
        }
        if ($has(['30 days', '30 ditë', '30 dite'])) {
            return '30d';
        }
        if ($has(['this year', 'year to date', 'këtë vit', 'kete vit', 'sivjet'])) {
            return 'ytd';
        }
        if ($has(['this month', 'month so far', 'këtë muaj', 'kete muaj', 'muajin', 'muaj'])) {
            return 'mtd';
        }
        return null;
    }

    private static function contains(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
