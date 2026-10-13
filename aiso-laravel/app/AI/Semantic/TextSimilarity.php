<?php

namespace App\AI\Semantic;

/**
 * Fuzzy semantic matching for Persian and English text, with no external service: text is normalized (Arabic and Persian
 * letter forms, digits, half-spaces, punctuation), cut into words and character trigrams, weighted by TF-IDF over the
 * candidates and compared by cosine. It finds "list of users" for «فهرست کاربران» and «لیست کارمندان», copes with typos
 * and prefixes, and ranks rows by name. Synonyms come from the keyword lists in the config, not from a model.
 */
final class TextSimilarity
{
    private const STOP = [
        'را', 'از', 'به', 'در', 'که', 'و', 'با', 'این', 'آن', 'برای', 'تا', 'هم', 'یا', 'چی', 'چه', 'چند', 'بده', 'بگو', 'کن',
        'کنید', 'من', 'ما', 'ها', 'های', 'ای', 'هایی', 'داریم', 'دارید', 'دارم', 'رو', 'میخوام', 'میخواهم', 'بهم', 'لطفا', 'است', 'هست', 'شد', 'the', 'a', 'an', 'of', 'to', 'is', 'me',
        'show', 'give', 'please', 'and', 'or', 'for', 'in', 'on',
    ];

    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, [
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ؤ' => 'و', 'ـ' => '',
            "\u{200c}" => ' ', "\u{200d}" => '',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    /** @return string[] the words that carry meaning */
    public static function words(string $s): array
    {
        $words = array_values(array_filter(explode(' ', self::normalize($s)), fn (string $w) => $w !== '' && !in_array($w, self::STOP, true)));

        return $words;
    }

    /** @return array<string, float> features of a text: words and character trigrams of each word */
    public static function features(string $s): array
    {
        $f = [];
        foreach (self::words($s) as $w) {
            $f["w:{$w}"] = ($f["w:{$w}"] ?? 0) + 2.0;
            $padded = " {$w} ";
            $len = mb_strlen($padded);
            for ($i = 0; $i + 3 <= $len; $i++) {
                $g = 'g:' . mb_substr($padded, $i, 3);
                $f[$g] = ($f[$g] ?? 0) + 1.0;
            }
        }

        return $f;
    }

    /**
     * Ranks candidate texts against a query. Returns the best first, each as [key, score between 0 and 1].
     *
     * @param array<int|string, string> $docs key => text
     * @return array<int, array{0: int|string, 1: float}>
     */
    public static function rank(string $query, array $docs, int $top = 10): array
    {
        $q = self::features($query);
        if ($q === [] || $docs === []) {
            return [];
        }
        $vectors = [];
        $df = [];
        foreach ($docs as $key => $text) {
            $vectors[$key] = self::features($text);
            foreach (array_keys($vectors[$key]) as $feature) {
                $df[$feature] = ($df[$feature] ?? 0) + 1;
            }
        }
        $n = count($docs);
        $idf = static fn (string $f): float => log(1 + $n / ($df[$f] ?? 0.5));

        $qv = [];
        foreach ($q as $f => $w) {
            $qv[$f] = $w * $idf($f);
        }
        $out = [];
        foreach ($vectors as $key => $v) {
            $dv = [];
            foreach ($v as $f => $w) {
                $dv[$f] = $w * $idf($f);
            }
            $out[] = [$key, self::cosine($qv, $dv)];
        }
        usort($out, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return array_slice($out, 0, $top);
    }

    /** @param array<string, float> $a @param array<string, float> $b */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $f => $w) {
            if (isset($b[$f])) {
                $dot += $w * $b[$f];
            }
        }
        $na = sqrt(array_sum(array_map(fn (float $x) => $x * $x, $a)));
        $nb = sqrt(array_sum(array_map(fn (float $x) => $x * $x, $b)));

        return $na == 0.0 || $nb == 0.0 ? 0.0 : $dot / ($na * $nb);
    }
}
