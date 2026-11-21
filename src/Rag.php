<?php
declare(strict_types=1);

namespace RepairSync;

/**
 * Retrieval-Augmented Generation (RAG) diagnostic core (port of server/rag.js).
 *  1. RETRIEVE  - BM25 ranking of verified past repair cases (word stems + concept tags, device/brand boosts)
 *  2. AGGREGATE - group retrieved cases by diagnosis and rank the diagnoses
 *  3. GENERATE  - compose ranked recommendations citing the supporting cases (template, or optional LLM)
 */
final class CaseIndex
{
    private const K1 = 1.4;
    private const B = 0.75;

    public array $docs = [];
    public int $N = 0;
    public float $avgdl = 0.0;
    private array $df = [];

    public function __construct(array $cases)
    {
        foreach ($cases as $c) {
            $text = "{$c['symptoms']} {$c['symptoms']} {$c['diagnosis']} " . ($c['root_cause'] ?? '');
            $tokens = Nlp::tokenize($text);
            $tf = [];
            foreach ($tokens as $t) { $k = (string)$t; $tf[$k] = ($tf[$k] ?? 0) + 1; }
            $this->docs[] = ['c' => $c, 'tf' => $tf, 'len' => count($tokens)];
        }
        $this->N = count($this->docs);
        $sum = 0;
        foreach ($this->docs as $d) $sum += $d['len'];
        $this->avgdl = $sum / max(1, $this->N);
        foreach ($this->docs as $d) foreach ($d['tf'] as $t => $_) { $k = (string)$t; $this->df[$k] = ($this->df[$k] ?? 0) + 1; }
    }

    public function idf(string $t): float
    {
        $n = $this->df[$t] ?? 0;
        return log(1 + ($this->N - $n + 0.5) / ($n + 0.5));
    }

    /** @return array{res: array, upper: float} */
    public function scoreAll(array $queryTokens): array
    {
        $q = [];
        foreach ($queryTokens as $t) { $k = (string)$t; $q[$k] = ($q[$k] ?? 0) + 1; }
        $qTerms = [];
        foreach ($q as $t => $n) $qTerms[] = [(string)$t, 1 + log($n)];
        $upper = 0.0;
        foreach ($qTerms as [$t, $w]) $upper += $w * $this->idf($t) * (self::K1 + 1);
        $res = [];
        foreach ($this->docs as $d) {
            $s = 0.0;
            foreach ($qTerms as [$t, $w]) {
                $f = $d['tf'][$t] ?? 0;
                if (!$f) continue;
                $s += $w * $this->idf($t) * (($f * (self::K1 + 1)) / ($f + self::K1 * (1 - self::B + (self::B * $d['len']) / $this->avgdl)));
            }
            $res[] = ['c' => $d['c'], 'score' => $s];
        }
        return ['res' => $res, 'upper' => $upper ?: 1.0];
    }
}

final class Rag
{
    public const DISCLAIMER = 'Preliminary AI-assisted suggestion based on past repair cases. A qualified technician must verify the diagnosis before any repair is performed.';

    /** Shared base cases (shop_id NULL) plus, when a shop is given, that shop's own approved cases. */
    public static function loadIndex(Db $db, ?int $shopId = null): CaseIndex
    {
        $cases = $shopId === null
            ? $db->all("SELECT * FROM cases WHERE status = 'approved' AND shop_id IS NULL ORDER BY id")
            : $db->all("SELECT * FROM cases WHERE status = 'approved' AND (shop_id IS NULL OR shop_id = ?) ORDER BY id", [$shopId]);
        return new CaseIndex($cases);
    }

    private static function norm(mixed $s): string { return trim(mb_strtolower((string)($s ?? ''), 'UTF-8')); }

    public static function confidenceLevel(float $relevance): string
    {
        if ($relevance >= 0.5) return 'high';
        if ($relevance >= 0.28) return 'medium';
        return 'low';
    }

    /** Retrieve + aggregate. Pure (no DB writes). */
    public static function diagnose(CaseIndex $index, array $q, int $topK = 8, int $topDiagnoses = 4): array
    {
        $deviceType = $q['deviceType'] ?? null;
        $brand = $q['brand'] ?? null;
        $queryText = trim(($q['symptoms'] ?? '') . ' ' . ($q['extra'] ?? ''));
        $qTokens = Nlp::tokenize($queryText);
        $concepts = Nlp::detectConcepts($queryText);
        ['res' => $res, 'upper' => $upper] = $index->scoreAll($qTokens);

        $scored = [];
        foreach ($res as $r) {
            $c = $r['c'];
            $s = $r['score'];
            $dt = self::norm($deviceType);
            $cdt = self::norm($c['device_type'] ?? null);
            if ($deviceType && $cdt === $dt) $s *= 1.25;
            elseif ($deviceType && in_array($dt, ['smartphone', 'tablet'], true) && in_array($cdt, ['smartphone', 'tablet'], true)) $s *= 1.1;
            elseif ($deviceType && $cdt !== $dt) $s *= 0.45;
            if ($brand && !empty($c['brand']) && self::norm($c['brand']) === self::norm($brand)) $s *= 1.08;
            if ($s > 0) $scored[] = ['c' => $c, 'score' => $s, 'relevance' => min(1.0, $s / $upper)];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, $topK);

        $groups = [];
        foreach ($scored as $x) {
            $d = $x['c']['diagnosis'];
            $groups[$d] ??= ['diagnosis' => $d, 'cases' => [], 'score' => 0.0];
            $groups[$d]['cases'][] = $x;
        }
        $ranked = [];
        foreach ($groups as $g) {
            usort($g['cases'], fn($a, $b) => $b['score'] <=> $a['score']);
            $sc = 0.0;
            foreach ($g['cases'] as $i => $x) $sc += $x['score'] * ($i === 0 ? 1 : 0.35);
            $g['score'] = $sc;
            $g['bestRelevance'] = $g['cases'][0]['relevance'];
            $ranked[] = $g;
        }
        usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
        $ranked = array_slice($ranked, 0, $topDiagnoses);

        $total = 0.0;
        foreach ($ranked as $g) $total += $g['score'];
        $total = $total ?: 1.0;

        $recs = [];
        foreach ($ranked as $i => $g) {
            $best = $g['cases'][0]['c'];
            $costs = array_values(array_filter(array_map(fn($x) => $x['c'], $g['cases']), fn($c) => ($c['cost_min'] ?? null) !== null));
            $checks = [];
            foreach ($g['cases'] as $x) {
                foreach (explode('|', (string)($x['c']['checks'] ?? '')) as $s) {
                    $s = trim($s);
                    if ($s !== '') $checks[$s] = true;
                }
            }
            $parts = [];
            foreach ($g['cases'] as $x) if (!empty($x['c']['parts'])) $parts[$x['c']['parts']] = true;
            $hoursSum = 0.0;
            foreach ($costs as $c) $hoursSum += (float)($c['hours'] ?? 0);
            $recs[] = [
                'rank' => $i + 1,
                'diagnosis' => $g['diagnosis'],
                'root_cause' => $best['root_cause'] ?? null,
                'confidence' => self::confidenceLevel($g['bestRelevance']),
                'match_score' => (int)round($g['bestRelevance'] * 100),
                'share' => (int)round($g['score'] / $total * 100),
                'suggested_checks' => array_slice(array_map('strval', array_keys($checks)), 0, 5),
                'likely_repair' => $best['repair_action'] ?? null,
                'parts' => array_slice(array_map('strval', array_keys($parts)), 0, 3),
                'est_cost_php' => $costs ? [self::num(min(array_map(fn($c) => (float)$c['cost_min'], $costs))), self::num(max(array_map(fn($c) => (float)$c['cost_max'], $costs)))] : null,
                'est_hours' => $costs ? round($hoursSum / count($costs), 1) : null,
                'supporting_cases' => array_map(fn($x) => [
                    'id' => (int)$x['c']['id'],
                    'similarity' => (int)round($x['relevance'] * 100),
                    'device_type' => $x['c']['device_type'],
                    'brand' => $x['c']['brand'] ?? null,
                    'symptoms' => $x['c']['symptoms'],
                ], $g['cases']),
            ];
        }
        $top = $recs[0] ?? null;
        $insufficient = !$top || $top['match_score'] < 12;
        return [
            'query' => $queryText,
            'concepts' => $concepts,
            'insufficient' => $insufficient,
            'cases_searched' => $index->N,
            'recommendations' => $insufficient ? [] : $recs,
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    /** Whole numbers stay ints in JSON (matches the Node output). */
    private static function num(float $v): int|float { return floor($v) == $v ? (int)$v : $v; }

    /** Deterministic generation step: turn retrieved evidence into a readable technician summary. */
    public static function composeSummary(array $result, array $ctx = []): string
    {
        if ($result['insufficient']) {
            return 'The knowledge base does not contain enough similar cases for this description. Please perform a manual inspection and record the verified diagnosis so it can be added to the knowledge base.';
        }
        $parts = array_filter([$ctx['brand'] ?? null, ($ctx['model'] ?? null) ?: ($ctx['deviceType'] ?? null)]);
        $dev = $parts ? implode(' ', $parts) : 'device';
        $a = $result['recommendations'][0];
        $b = $result['recommendations'][1] ?? null;
        $s = "For this $dev, the closest historical cases point most strongly to \"{$a['diagnosis']}\" ({$a['confidence']} confidence, best case match {$a['match_score']}%).";
        if (!empty($a['root_cause'])) $s .= " Typical cause: {$a['root_cause']}";
        if ($a['suggested_checks']) $s .= ' Start by checking: ' . implode('; ', array_slice($a['suggested_checks'], 0, 3)) . '.';
        if ($b) $s .= " Alternative to rule out: \"{$b['diagnosis']}\" ({$b['confidence']} confidence).";
        $s .= ' This is decision support only; confirm by inspection before quoting or repairing.';
        return $s;
    }

    /** Customer-facing hint: deliberately coarse (no costs, no repair instructions). */
    public static function customerHint(array $result): ?array
    {
        if ($result['insufficient'] || !$result['recommendations']) return null;
        return [
            'possible_areas' => array_map(fn($r) => $r['diagnosis'], array_slice($result['recommendations'], 0, 2)),
            'note' => 'This is only a preliminary guess from your description. Our technician will inspect your device and confirm the actual problem before any repair.',
        ];
    }

    private const SAFETY = [
        ['/swollen|bulging|puffed/i', 'Stop charging and using the device. Keep it away from heat and do not press or puncture it. A swollen battery can be a fire hazard.'],
        ['/liquid|water/i', 'Switch the device off now and do not charge it. Do not use rice or a hair dryer. Bring it to a technician as soon as possible.'],
        ['/overheat/i', 'Turn the device off and let it cool in a ventilated place. Avoid charging it while hot.'],
        ['/crack|damaged display/i', 'Avoid pressing on the glass and use a screen protector or tape to keep splinters in. Back up your data if the touch still works.'],
    ];

    /** Richer public result for the free AI check: confidence labels and plain-language areas only. */
    public static function publicCheck(array $result): array
    {
        if ($result['insufficient'] || !$result['recommendations']) {
            return [
                'found' => false,
                'message' => 'We could not match your description to enough similar repairs. Try adding more detail (what happened, what you see, when it started), or book an inspection and a technician will check it.',
                'disclaimer' => $result['disclaimer'],
            ];
        }
        $areas = array_map(fn($r) => ['name' => $r['diagnosis'], 'confidence' => $r['confidence'], 'why' => $r['root_cause'] ?: null], array_slice($result['recommendations'], 0, 3));
        $safety = [];
        foreach (array_slice($result['recommendations'], 0, 2) as $r) {
            foreach (self::SAFETY as [$re, $t]) if (preg_match($re, $r['diagnosis'])) $safety[$t] = true;
        }
        return [
            'found' => true,
            'areas' => $areas,
            'safety' => array_map('strval', array_keys($safety)),
            'next_step' => 'Book an inspection with a repair shop. A technician will confirm the actual problem and send you a quote before any repair starts.',
            'disclaimer' => $result['disclaimer'],
        ];
    }
}
