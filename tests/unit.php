<?php
// UNIT TESTS - NLP, retrieval, workflow rules, auth primitives. Run: php tests/unit.php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/lib.php';

use RepairSync\{Nlp, CaseIndex, Rag, Workflow, Auth, Signer, Db, Seeder};

echo "Unit tests\n";

T::test('NLP: normalize handles contractions, case and punctuation', function () {
    T::eq(Nlp::normalize("Phone WON'T turn on!!"), 'phone wont turn on');
    T::eq(Nlp::normalize('  Wi-Fi   keeps…dropping '), 'wi fi keeps dropping');
});

T::test('NLP: concept detection works for English and Filipino/Cebuano phrasing', function () {
    T::ok(in_array('c_charging', Nlp::detectConcepts('The phone is not charging'), true));
    T::ok(in_array('c_charging', Nlp::detectConcepts('ayaw mag-charge ang cellphone'), true));
    T::ok(in_array('c_water', Nlp::detectConcepts('nabasa ng ulan'), true));
    T::ok(in_array('c_screen_crack', Nlp::detectConcepts('basag ang screen'), true));
    T::eq(count(Nlp::detectConcepts('hello there')), 0);
});

T::test('NLP: tokenizer drops stop-words and up-weights concepts', function () {
    $t = Nlp::tokenize('the screen is cracked');
    T::ok(!in_array('the', $t, true));
    T::ok(count(array_filter($t, fn($x) => $x === 'c_screen_crack')) >= 2);
});

T::test('RAG: ranks the matching diagnosis first and cites supporting cases', function () {
    $idx = new CaseIndex([
        ['id' => 1, 'device_type' => 'smartphone', 'brand' => 'A', 'symptoms' => 'screen cracked after drop', 'diagnosis' => 'Cracked display', 'cost_min' => 1000, 'cost_max' => 2000, 'hours' => 1],
        ['id' => 2, 'device_type' => 'smartphone', 'brand' => 'A', 'symptoms' => 'battery drains fast', 'diagnosis' => 'Battery', 'cost_min' => 500, 'cost_max' => 900, 'hours' => 1],
        ['id' => 3, 'device_type' => 'laptop', 'brand' => 'B', 'symptoms' => 'fan loud and hot', 'diagnosis' => 'Overheating', 'cost_min' => 500, 'cost_max' => 900, 'hours' => 1],
    ]);
    $r = Rag::diagnose($idx, ['symptoms' => 'I dropped it and the screen is cracked', 'deviceType' => 'smartphone']);
    T::eq($r['recommendations'][0]['diagnosis'], 'Cracked display');
    T::eq($r['recommendations'][0]['supporting_cases'][0]['id'], 1);
    T::ok($r['recommendations'][0]['match_score'] > 0);
});

T::test('RAG: returns "insufficient" instead of guessing for unrelated text', function () {
    $idx = new CaseIndex([['id' => 1, 'device_type' => 'smartphone', 'brand' => 'A', 'symptoms' => 'screen cracked after drop', 'diagnosis' => 'Cracked display']]);
    $r = Rag::diagnose($idx, ['symptoms' => 'zzz qqq www', 'deviceType' => 'smartphone']);
    T::eq($r['insufficient'], true);
    T::eq(count($r['recommendations']), 0);
});

function seeded(): Db { $db = new Db(['driver' => 'sqlite', 'sqlite_path' => ':memory:']); $db->migrate(); Seeder::seedBase($db, false); return $db; }

T::test('RAG: device type influences ranking', function () {
    $idx = Rag::loadIndex(seeded());
    $phone = Rag::diagnose($idx, ['symptoms' => 'it gets very hot and shuts down', 'deviceType' => 'smartphone']);
    $laptop = Rag::diagnose($idx, ['symptoms' => 'it gets very hot and shuts down', 'deviceType' => 'laptop']);
    T::match('/Phone overheating|battery/i', $phone['recommendations'][0]['diagnosis']);
    T::match('/Overheating \(blocked cooling/', $laptop['recommendations'][0]['diagnosis']);
});

T::test('RAG: confidence bands', function () {
    T::eq(Rag::confidenceLevel(0.7), 'high');
    T::eq(Rag::confidenceLevel(0.3), 'medium');
    T::eq(Rag::confidenceLevel(0.1), 'low');
});

T::test('RAG: seeded knowledge base evaluation stays above regression floor', function () {
    $queries = json_decode(file_get_contents(__DIR__ . '/eval-queries.json'), true);
    $idx = Rag::loadIndex(seeded());
    $top1 = $top3 = 0;
    foreach ($queries as $q) {
        $res = Rag::diagnose($idx, ['symptoms' => $q['query'], 'deviceType' => $q['device_type'] ?? null, 'brand' => $q['brand'] ?? null]);
        foreach ($res['recommendations'] as $i => $r) if ($r['diagnosis'] === $q['expected']) { if ($i === 0) $top1++; if ($i < 3) $top3++; break; }
    }
    $n = count($queries);
    T::ok(100 * $top1 / $n >= 80, "top-1 $top1/$n");
    T::ok(100 * $top3 / $n >= 92, "top-3 $top3/$n");
    T::eq([$top1, $top3, $n], [43, 47, 48], 'same results as the Node version');
});

T::test('Workflow: every status has a transition entry and terminal states are closed', function () {
    foreach (Workflow::STATUSES as $s) T::ok(array_key_exists($s, Workflow::TRANSITIONS));
    T::eq(Workflow::TRANSITIONS['released'], []);
    T::eq(Workflow::TRANSITIONS['cancelled'], []);
});

T::test('Workflow: guards enforce business rules', function () {
    T::match('/findings/i', Workflow::guard(['findings' => ''], 'awaiting_approval', ['itemsTotal' => 100]));
    T::match('/quote item/i', Workflow::guard(['findings' => 'cracked panel'], 'awaiting_approval', ['itemsTotal' => 0]));
    T::eq(Workflow::guard(['findings' => 'cracked panel'], 'awaiting_approval', ['itemsTotal' => 100]), null);
    T::match('/verified diagnosis/i', Workflow::guard([], 'completed', []));
    T::match('/repair action/i', Workflow::guard(['verified_diagnosis' => 'x'], 'completed', []));
    T::match('/balance/i', Workflow::guard([], 'released', ['itemsTotal' => 1000, 'paid' => 400]));
    T::eq(Workflow::guard([], 'released', ['itemsTotal' => 1000, 'paid' => 1000]), null);
    T::eq(Workflow::guard([], 'released', ['itemsTotal' => 1000, 'paid' => 0, 'override' => true]), null);
});

T::test('Workflow: notification templates include the repair code', function () {
    $r = ['code' => 'RS-2610-0001', 'brand' => 'Apple', 'model' => 'iPhone 11', 'device_type' => 'smartphone'];
    foreach (Workflow::STATUSES as $s) T::match('/RS-2610-0001/', Workflow::customerMessage($r, $s, ['total' => 100]));
});

T::test('Auth: password hashing verifies correct passwords only', function () {
    $h = Auth::hashPassword('Secret123');
    T::ok(Auth::verifyPassword('Secret123', $h));
    T::ok(!Auth::verifyPassword('secret123', $h));
    T::ok(!Auth::verifyPassword('x', 'garbage'));
    T::ok(Auth::hashPassword('Secret123') !== $h, 'salted');
});

T::test('Auth: password policy', function () {
    T::ok(Auth::passwordProblem('short1') !== null);
    T::ok(Auth::passwordProblem('onlyletters') !== null);
    T::eq(Auth::passwordProblem('Letters123'), null);
});

T::test('Auth: signed tokens reject tampering and expiry', function () {
    $s = new Signer('k');
    $tok = $s->issue(7);
    T::eq($s->verify($tok)['uid'], 7);
    T::eq($s->verify(substr($tok, 0, -2) . 'xx'), null);
    T::eq((new Signer('other'))->verify($tok), null);
    T::eq($s->verify($s->issue(7, -10)), null);
});

T::test('RBAC: permission matrix', function () {
    T::ok(Auth::can(['role' => 'technician'], 'ai.feedback'));
    T::ok(!Auth::can(['role' => 'technician'], 'repair.pay'));
    T::ok(!Auth::can(['role' => 'frontdesk'], 'repair.diagnose'));
    T::ok(Auth::can(['role' => 'admin'], 'user.manage'));
    T::ok(!Auth::can(['role' => 'owner'], 'user.manage'));
    T::ok(!Auth::can(['role' => 'customer'], 'repair.view_all'));
    T::ok(!Auth::can(null, 'repair.view_all'));
    foreach (Auth::PERMISSIONS as $perms) T::eq(count(array_unique($perms)), count($perms));
});

T::test('Rate limiter blocks after the limit (stored in the database)', function () {
    $db = seeded();
    $l = new RepairSync\Limiter($db);
    T::ok($l->hit('a', 60, 2)); T::ok($l->hit('a', 60, 2)); T::ok(!$l->hit('a', 60, 2)); T::ok($l->hit('b', 60, 2));
});

exit(T::summary());
