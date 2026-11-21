<?php
// Offline retrieval evaluation for the RAG core (PHP port). Usage: php tests/rag-eval.php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';
use RepairSync\{Db, Seeder, Rag};

$queries = json_decode(file_get_contents(__DIR__ . '/eval-queries.json'), true);
$db = new Db(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$db->migrate();
Seeder::seedBase($db, false);
$index = Rag::loadIndex($db);
$top1 = $top3 = $none = 0;
$misses = [];
foreach ($queries as $q) {
    $res = Rag::diagnose($index, ['symptoms' => $q['query'], 'deviceType' => $q['device_type'] ?? null, 'brand' => $q['brand'] ?? null]);
    $rank = 0;
    foreach ($res['recommendations'] as $i => $r) if ($r['diagnosis'] === $q['expected']) { $rank = $i + 1; break; }
    if ($res['insufficient']) $none++;
    if ($rank === 1) $top1++;
    if ($rank >= 1 && $rank <= 3) $top3++;
    if ($rank !== 1) $misses[] = [$q['query'], $q['expected'], array_map(fn($r) => $r['diagnosis'], array_slice($res['recommendations'], 0, 3)), $rank];
}
$n = count($queries);
printf("Queries: %d   Knowledge-base cases: %d\n", $n, $index->N);
printf("Top-1 accuracy: %d/%d = %.1f%%\n", $top1, $n, 100 * $top1 / $n);
printf("Top-3 accuracy: %d/%d = %.1f%%\n", $top3, $n, 100 * $top3 / $n);
printf("Insufficient-evidence responses: %d\n", $none);
foreach ($misses as [$qq, $e, $g, $r]) echo "\n- \"$qq\"\n  expected: $e\n  got: " . implode(' | ', $g) . " (rank " . ($r ?: 'none') . ")\n";
