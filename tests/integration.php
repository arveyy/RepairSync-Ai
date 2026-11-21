<?php
// INTEGRATION TESTS - the whole API end to end against an in-memory SQLite database. Run: php tests/integration.php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/lib.php';
date_default_timezone_set('UTC');

use RepairSync\{App, Db, Seeder};

$db = new Db(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$db->migrate();
Seeder::seedBase($db, true);
Seeder::ensurePlatformAdmin($db, true);
Client::$db = $db;
// Branding uploads go to a throwaway directory so tests never touch a real shop's files.
$uploadDir = sys_get_temp_dir() . '/repairsync-branding-' . getmypid();
@mkdir($uploadDir, 0775, true);
function deleteTree(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        is_dir($path) ? deleteTree($path) : @unlink($path);
    }
    @rmdir($dir);
}
register_shutdown_function(fn() => deleteTree($uploadDir));
Client::$app = new App($db, ['driver' => 'sqlite', 'upload_dir' => $uploadDir], 'test-secret', false, '/');

// Sessions are stateless signed cookies, so a demo login is reused (the real login limiter allows 10 per account per 15 min).
$sessions = [];
function as_(string $u): Client
{
    global $sessions;
    if (!isset($sessions[$u])) $sessions[$u] = (new Client())->login($u)->cookie;
    $c = new Client();
    $c->cookie = $sessions[$u];
    return $c;
}
function booking(array $o = []): array
{
    return $o + ['name' => 'Test Customer', 'phone' => '09175550123', 'email' => 'tc@example.com', 'branchId' => 1, 'deviceType' => 'smartphone', 'brand' => 'Samsung', 'model' => 'A12', 'symptoms' => 'Phone fell and the screen is cracked with lines', 'consent' => true];
}
function signup(array $o = []): array
{
    return $o + ['businessName' => 'Kuya Gadget Fix', 'ownerName' => 'Ken Owner', 'email' => 'ken@kuya.test', 'phone' => '09175551111', 'city' => 'Iloilo City', 'password' => 'Gadget#2026ok', 'agree' => true];
}
function status(array $r): int { return $r['status']; }
/** A platform administrator approves the shop that this (signed-in shop admin) client just registered. */
function approve(Client $shopAdmin): void
{
    $id = $shopAdmin->get('/api/auth/me')['data']['user']['shop']['id'];
    $r = as_('platform')->post("/api/platform/shops/$id/review", ['action' => 'approve']);
    T::eq($r['status'], 200, json_encode($r['data']));
}

echo "Integration tests\n";

T::test('public booking creates a ticket, a tracking token and an AI hint; consent is mandatory', function () {
    $anon = new Client();
    T::eq(status($anon->post('/api/public/bookings', booking(['consent' => false]))), 400);
    T::eq(status($anon->post('/api/public/bookings', booking(['symptoms' => 'bad']))), 400);
    $r = $anon->post('/api/public/bookings', booking());
    T::eq($r['status'], 200);
    T::match('/^RS-\d{4}-\d{4}$/', $r['data']['code']);
    T::ok(strlen($r['data']['token']) >= 20);
    T::ok(count($r['data']['hint']['possible_areas']) > 0);
    T::ok(!array_key_exists('est_cost_php', $r['data']['hint']), 'customers must not see cost/repair instructions');
});

T::test('public tracking requires token or phone digits and hides personal data', function () {
    $anon = new Client();
    $b = $anon->post('/api/public/bookings', booking(['name' => 'Priv Acy', 'phone' => '09176660001', 'email' => 'priv@example.com']));
    $code = $b['data']['code'];
    T::eq(status($anon->get("/api/public/track?code=$code")), 404);
    T::eq(status($anon->get("/api/public/track?code=$code&token=wrong")), 404);
    T::eq(status($anon->get("/api/public/track?code=$code&phone4=9999")), 404);
    $ok = $anon->get("/api/public/track?code=$code&token={$b['data']['token']}");
    T::eq($ok['status'], 200);
    T::eq($ok['data']['status'], 'booked');
    T::eq($ok['data']['customer_first_name'], 'Priv');
    T::ok(!str_contains($ok['raw'], '09176660001'));
    T::eq(status($anon->get("/api/public/track?code=$code&phone4=0001")), 200);
});

T::test('API rejects state-changing requests without the anti-CSRF header', function () {
    $res = Client::$app->handle('POST', '/api/public/bookings', [], json_encode(booking()), [], [], '127.0.0.1');
    T::eq($res['status'], 403);
});

T::test('authentication: wrong password, me and logout', function () {
    $c = new Client();
    T::eq(status($c->post('/api/auth/login', ['email' => 'admin@repairsync.test', 'password' => 'nope'])), 401);
    T::eq($c->get('/api/auth/me')['data']['user'], null);
    $c->login('admin');
    T::eq($c->get('/api/auth/me')['data']['user']['role'], 'admin');
    $c->post('/api/auth/logout');
    $c->cookie = '';
    T::eq(status($c->get('/api/repairs')), 401);
});

T::test('login is rate limited', function () {
    $c = new Client();
    $last = null;
    for ($i = 0; $i < 12; $i++) $last = $c->post('/api/auth/login', ['email' => 'ratelimit@repairsync.test', 'password' => 'wrongpass1']);
    T::eq($last['status'], 429);
});

T::test("RBAC: customers cannot see others' repairs or staff endpoints; technicians do not see PII", function () {
    $front = as_('frontdesk');
    $created = $front->post('/api/repairs', ['name' => 'Walk In', 'phone' => '09177770001', 'branchId' => 1, 'deviceType' => 'laptop', 'brand' => 'Dell', 'model' => 'Inspiron', 'symptoms' => 'Laptop gets very hot and the fan is loud', 'consent' => true]);
    T::eq($created['status'], 200);
    $id = $created['data']['id'];
    $cust = as_('customer');
    T::eq(status($cust->get("/api/repairs/$id")), 404);
    T::eq(array_values(array_filter($cust->get('/api/repairs')['data'], fn($r) => $r['id'] === $id)), []);
    T::eq(status($cust->get('/api/users')), 403);
    T::eq(status($cust->get('/api/audit')), 403);
    T::eq(status($cust->get('/api/reports/summary')), 403);
    $tech = as_('tech1');
    $view = $tech->get("/api/repairs/$id");
    T::eq($view['status'], 200);
    T::eq($view['data']['customer_name'], 'Walk');
    T::ok(!array_key_exists('customer_phone', $view['data']));
    T::eq(status($tech->post('/api/repairs', ['name' => 'x y', 'phone' => '0917777', 'branchId' => 1, 'deviceType' => 'laptop', 'symptoms' => 'long enough text', 'consent' => true])), 403);
    $owner = as_('owner');
    T::eq($owner->get("/api/repairs/$id")['data']['customer_phone'], '09177770001');
    T::eq(status($owner->post("/api/repairs/$id/transition", ['to' => 'diagnosing'])), 403);
});

T::test('full repair lifecycle with guards, assignment rules, payment, notifications and AI feedback', function () {
    $front = as_('frontdesk'); $tech = as_('tech1'); $tech2 = as_('tech2'); $admin = as_('admin'); $cust = as_('customer');

    $bk = $cust->post('/api/public/bookings', ['branchId' => 1, 'deviceType' => 'smartphone', 'brand' => 'Oppo', 'model' => 'A57', 'symptoms' => 'Nahulog ang phone, basag ang screen, may green lines', 'consent' => true, 'phone' => '09171112222']);
    T::eq($bk['status'], 200);
    $mine = null;
    foreach ($cust->get('/api/repairs')['data'] as $r) if ($r['code'] === $bk['data']['code']) $mine = $r;
    T::ok($mine);
    $id = $mine['id'];

    T::eq(status($front->post("/api/repairs/$id/transition", ['to' => 'repairing'])), 400);
    T::eq(status($tech->post("/api/repairs/$id/transition", ['to' => 'received'])), 403);
    T::eq(status($front->post("/api/repairs/$id/transition", ['to' => 'received'])), 200);

    T::eq(status($tech->post("/api/repairs/$id/transition", ['to' => 'diagnosing'])), 200);
    T::eq(status($tech2->patch("/api/repairs/$id", ['findings' => 'I should not be able to edit this'])), 403);

    T::eq(status($tech->post("/api/repairs/$id/transition", ['to' => 'awaiting_approval'])), 400);
    T::eq(status($tech->patch("/api/repairs/$id", ['findings' => 'Display panel cracked, touch intact.'])), 200);
    T::eq(status($tech->post("/api/repairs/$id/transition", ['to' => 'awaiting_approval'])), 400);
    T::eq(status($tech->post("/api/repairs/$id/items", ['kind' => 'part', 'description' => 'Display assembly', 'amount' => 3500])), 200);
    T::eq(status($tech->post("/api/repairs/$id/items", ['kind' => 'labor', 'description' => 'Installation', 'amount' => -5])), 400);
    T::eq(status($tech->post("/api/repairs/$id/items", ['kind' => 'labor', 'description' => 'Installation', 'amount' => 500])), 200);

    $run = $tech->post("/api/repairs/$id/diagnose", ['extra' => 'glass shattered, touch still works']);
    T::eq($run['status'], 200);
    T::eq($run['data']['result']['recommendations'][0]['diagnosis'], 'Cracked or damaged display assembly');
    $fb = $tech->post("/api/repairs/$id/ai/{$run['data']['id']}/feedback", ['diagnosis' => 'Cracked or damaged display assembly', 'accepted' => true]);
    T::eq($fb['data']['verified_diagnosis'], 'Cracked or damaged display assembly');
    T::eq(status($front->post("/api/repairs/$id/diagnose", [])), 200);
    T::eq(status($front->post("/api/repairs/$id/ai/{$run['data']['id']}/feedback", ['diagnosis' => 'x', 'accepted' => true])), 403);

    T::eq(status($tech->post("/api/repairs/$id/transition", ['to' => 'awaiting_approval'])), 200);
    $approved = $cust->post("/api/repairs/$id/approve", ['approve' => true]);
    T::eq($approved['data']['status'], 'repairing');
    T::eq($approved['data']['approval_state'], 'approved');

    T::eq(status($tech->post("/api/repairs/$id/transition", ['to' => 'quality_check'])), 200);
    T::eq(status($tech->post("/api/repairs/$id/transition", ['to' => 'completed'])), 400);
    $tech->patch("/api/repairs/$id", ['repair_action' => 'Replaced display assembly.']);
    T::eq(status($tech->post("/api/repairs/$id/transition", ['to' => 'completed'])), 200);
    T::eq(status($front->post("/api/repairs/$id/transition", ['to' => 'released'])), 400);
    T::eq(status($front->post("/api/repairs/$id/payment", ['amount' => 99999, 'method' => 'cash'])), 400);
    T::eq(status($front->post("/api/repairs/$id/payment", ['amount' => 1000, 'method' => 'cash'])), 200);
    T::eq(status($front->post("/api/repairs/$id/transition", ['to' => 'released', 'override' => true])), 403);
    T::eq(status($front->post("/api/repairs/$id/payment", ['amount' => 3000, 'method' => 'gcash'])), 200);
    $rel = $front->post("/api/repairs/$id/transition", ['to' => 'released']);
    T::eq($rel['status'], 200);
    T::ok($rel['data']['balance'] == 0);
    T::eq(status($front->post("/api/repairs/$id/transition", ['to' => 'cancelled', 'note' => 'late'])), 400);
    T::eq(status($tech->patch("/api/repairs/$id", ['findings' => 'edit after close'])), 400);

    $detail = $admin->get("/api/repairs/$id")['data'];
    T::eq(array_map(fn($e) => $e['to_status'], $detail['events']), ['booked', 'received', 'diagnosing', 'awaiting_approval', 'repairing', 'quality_check', 'completed', 'released']);
    T::ok(count($detail['notifications']) >= 6);
    foreach ($detail['notifications'] as $n) T::ok(str_contains($n['message'], $bk['data']['code']));
    $learned = null;
    foreach ($admin->get('/api/cases?status=pending')['data'] as $c) if ($c['repair_id'] === $id) $learned = $c;
    T::ok($learned, 'completed + verified repair becomes a pending KB case');
    T::eq(status($tech->patch("/api/cases/{$learned['id']}", ['status' => 'approved'])), 403);
    T::eq(status($admin->patch("/api/cases/{$learned['id']}", ['status' => 'approved'])), 200);
});

T::test('customer can decline a quote through the public tracking link', function () {
    $anon = new Client();
    $b = $anon->post('/api/public/bookings', booking(['phone' => '09178880001', 'email' => 'decline@example.com']));
    $front = as_('frontdesk'); $tech = as_('tech1');
    $id = $front->get("/api/repairs?q={$b['data']['code']}")['data'][0]['id'];
    $front->post("/api/repairs/$id/transition", ['to' => 'received']);
    $tech->post("/api/repairs/$id/transition", ['to' => 'diagnosing']);
    $tech->patch("/api/repairs/$id", ['findings' => 'Needs new battery and screen']);
    $tech->post("/api/repairs/$id/items", ['kind' => 'part', 'description' => 'Battery', 'amount' => 900]);
    $tech->post("/api/repairs/$id/transition", ['to' => 'awaiting_approval']);
    $t = $anon->post('/api/public/track/approve', ['code' => $b['data']['code'], 'token' => $b['data']['token'], 'approve' => false]);
    T::eq($t['status'], 200);
    T::eq($t['data']['status'], 'cancelled');
    T::eq(status($anon->post('/api/public/track/approve', ['code' => $b['data']['code'], 'token' => 'bad', 'approve' => true])), 404);
});

T::test('cancellation requires a reason; technician cannot cancel', function () {
    $front = as_('frontdesk'); $tech = as_('tech1');
    $r = $front->post('/api/repairs', ['name' => 'Cancel Me', 'phone' => '09179990001', 'branchId' => 1, 'deviceType' => 'tablet', 'symptoms' => 'Tablet will not turn on at all', 'consent' => true]);
    $id = $r['data']['id'];
    T::eq(status($front->post("/api/repairs/$id/transition", ['to' => 'cancelled'])), 400);
    T::eq(status($tech->post("/api/repairs/$id/transition", ['to' => 'cancelled', 'note' => 'x'])), 403);
    T::eq(status($front->post("/api/repairs/$id/transition", ['to' => 'cancelled', 'note' => 'Customer changed mind'])), 200);
});

T::test('knowledge base: technician submissions need approval; approved cases change AI results', function () {
    $tech = as_('tech1'); $admin = as_('admin'); $front = as_('frontdesk');
    $sub = $tech->post('/api/cases', ['device_type' => 'smartphone', 'brand' => 'Acme', 'symptoms' => 'zorblax coupling humming when idle', 'diagnosis' => 'Zorblax coupler fault']);
    T::eq($sub['data']['status'], 'pending');
    $mk = function () use ($front) { return $front->post('/api/repairs', ['name' => 'Kb Test', 'phone' => '09170001111', 'branchId' => 1, 'deviceType' => 'smartphone', 'brand' => 'Acme', 'symptoms' => 'zorblax coupling humming when idle', 'consent' => true])['data']['id']; };
    $a = $tech->get('/api/repairs/' . $mk())['data']['ai_runs'][0]['result'];
    T::ok($a['insufficient'] || $a['recommendations'][0]['diagnosis'] !== 'Zorblax coupler fault');
    $pending = $admin->get('/api/cases?status=pending&q=zorblax')['data'][0];
    $admin->patch("/api/cases/{$pending['id']}", ['status' => 'approved']);
    $b = $tech->get('/api/repairs/' . $mk())['data']['ai_runs'][0]['result'];
    T::eq($b['recommendations'][0]['diagnosis'], 'Zorblax coupler fault');
});

T::test('user management: admin only; cannot lock yourself out; weak passwords rejected', function () {
    $admin = as_('admin'); $front = as_('frontdesk');
    T::eq(status($front->post('/api/users', ['name' => 'X Y', 'email' => 'x@y.com', 'role' => 'technician', 'password' => 'Passw0rdOK'])), 403);
    T::eq(status($admin->post('/api/users', ['name' => 'New Tech', 'email' => 'newtech@repairsync.test', 'role' => 'technician', 'password' => 'short'])), 400);
    T::eq(status($admin->post('/api/users', ['name' => 'New Tech', 'email' => 'newtech@repairsync.test', 'role' => 'technician', 'password' => 'Passw0rdOK'])), 200);
    T::eq(status($admin->post('/api/users', ['name' => 'New Tech', 'email' => 'newtech@repairsync.test', 'role' => 'technician', 'password' => 'Passw0rdOK'])), 400);
    $me = $admin->get('/api/auth/me')['data']['user'];
    T::eq(status($admin->patch("/api/users/{$me['id']}", ['active' => false])), 400);
    $nt = new Client();
    T::eq(status($nt->post('/api/auth/login', ['email' => 'newtech@repairsync.test', 'password' => 'Passw0rdOK'])), 200);
    $id = null;
    foreach ($admin->get('/api/users')['data'] as $u) if ($u['email'] === 'newtech@repairsync.test') $id = $u['id'];
    $admin->patch("/api/users/$id", ['active' => false]);
    T::eq(status($nt->get('/api/repairs')), 401);
});

T::test('data privacy: erasure anonymises a customer only when no repairs are open; audit log records it', function () {
    $admin = as_('admin'); $front = as_('frontdesk');
    $r = $front->post('/api/repairs', ['name' => 'Erase Me', 'phone' => '09171234000', 'email' => 'erase@example.com', 'branchId' => 1, 'deviceType' => 'smartphone', 'symptoms' => 'Phone speaker has no sound at all', 'consent' => true]);
    $cid = $admin->get('/api/customers?q=Erase')['data'][0]['id'];
    T::eq(status($admin->post("/api/customers/$cid/anonymize")), 400);
    $front->post("/api/repairs/{$r['data']['id']}/transition", ['to' => 'cancelled', 'note' => 'test']);
    T::eq(status($admin->post("/api/customers/$cid/anonymize")), 200);
    $after = $admin->get("/api/repairs/{$r['data']['id']}")['data'];
    T::eq($after['customer_name'], 'Anonymized Customer');
    T::ok(empty($after['customer_phone']));
    $log = $admin->get('/api/audit?limit=50')['data'];
    T::ok(count(array_filter($log, fn($l) => $l['action'] === 'customer.erase')) > 0);
    T::ok(count(array_filter($log, fn($l) => $l['action'] === 'repair.transition')) > 0);
    T::eq(status($front->get('/api/audit')), 403);
});

T::test('reports summarise operations for owner and admin only', function () {
    $s = as_('owner')->get('/api/reports/summary');
    T::eq($s['status'], 200);
    T::ok($s['data']['totals']['repairs'] > 0);
    T::ok(array_key_exists('top1_accepted', $s['data']['ai']));
    T::eq(status(as_('tech1')->get('/api/reports/summary')), 403);
});

T::test('input hardening: oversized/invalid JSON, SQL-ish and script input, unknown routes', function () {
    $anon = new Client();
    T::eq($anon->req('POST', '/api/public/bookings', null, [], '{not json')['status'], 400);
    T::ok(in_array($anon->req('POST', '/api/public/bookings', null, [], json_encode(['x' => str_repeat('a', 200000)]))['status'], [413, 400], true));
    $inj = $anon->post('/api/public/bookings', booking(['name' => "Robert'); DROP TABLE repairs;--", 'symptoms' => '<script>alert(1)</script> phone broken screen']));
    T::eq($inj['status'], 200); // stored safely as data (parameterised SQL)
    T::eq(status((new Client())->get('/api/nope')), 404);
    T::ok(count(as_('frontdesk')->get('/api/repairs')['data']) > 0, 'tables still exist');
    T::match("/default-src 'self'/", (new Client())->get('/api/health')['headers']['Content-Security-Policy']);
});

T::test('business sign-up creates an isolated shop with its own branch and administrator', function () {
    $c = new Client();
    T::eq(status($c->post('/api/business/signup', signup(['agree' => false]))), 400);
    T::eq(status($c->post('/api/business/signup', signup(['password' => 'short']))), 400);
    T::eq(status($c->post('/api/business/signup', signup(['email' => 'admin@repairsync.test']))), 400, 'email already used');
    $ok = $c->post('/api/business/signup', signup());
    T::eq($ok['status'], 200, json_encode($ok['data']));
    T::eq($ok['data']['role'], 'admin');
    T::eq($ok['data']['shop']['name'], 'Kuya Gadget Fix');
    T::eq($ok['data']['shop']['slug'], 'kuya-gadget-fix');
    $branches = $c->get('/api/branches');
    T::eq(count($branches['data']), 1);
    T::match('/Kuya Gadget Fix/', $branches['data'][0]['name']);
    T::eq(count($c->get('/api/users')['data']), 1, 'new shop only sees its own staff');
    $c2 = new Client();
    $second = $c2->post('/api/business/signup', signup(['email' => 'ken2@kuya.test']));
    T::eq($second['data']['shop']['slug'], 'kuya-gadget-fix-2');
});

T::test("tenant isolation: a shop never sees, changes or reports on another shop's data", function () {
    $mine = new Client();
    $mine->post('/api/business/signup', signup(['businessName' => 'Isolation Test Shop', 'email' => 'iso@kuya.test']));
    approve($mine);
    $myBranch = $mine->get('/api/branches')['data'][0]['id'];
    $anon = new Client();
    $b = $anon->post('/api/public/bookings', booking(['branchId' => $myBranch, 'name' => 'Iso Customer', 'phone' => '09178880001', 'email' => 'isoc@example.com']));
    T::eq($b['status'], 200);
    $list = $mine->get('/api/repairs')['data'];
    T::eq(count($list), 1);
    $rid = $list[0]['id'];

    $other = as_('admin');
    T::ok(!count(array_filter($other->get('/api/repairs')['data'], fn($r) => $r['id'] === $rid)), 'repair of another shop is not listed');
    T::eq(status($other->get("/api/repairs/$rid")), 404);
    T::eq(status($other->post("/api/repairs/$rid/transition", ['to' => 'received'])), 404);
    T::eq(status($other->post("/api/repairs/$rid/assign", ['technicianId' => 5])), 404);
    T::eq(status($other->post("/api/repairs/$rid/diagnose", [])), 404);
    T::ok(!count(array_filter($other->get('/api/customers')['data'], fn($c) => $c['name'] === 'Iso Customer')));
    T::ok(!count(array_filter($other->get('/api/audit?limit=500')['data'], fn($a) => preg_match('/iso@kuya/', $a['actor_label'] ?? ''))));

    $theirUsers = $other->get('/api/users')['data'];
    T::eq(status($mine->patch("/api/users/{$theirUsers[0]['id']}", ['active' => false])), 404);
    T::eq(status($mine->post("/api/repairs/$rid/transition", ['to' => 'received'])), 200);
    $tech4 = null;
    foreach ($other->get('/api/users')['data'] as $u) if ($u['role'] === 'technician') { $tech4 = $u; break; }
    T::eq(status($mine->post("/api/repairs/$rid/assign", ['technicianId' => $tech4['id']])), 400, "cannot assign another shop's technician");
    T::eq(status($mine->post('/api/repairs', ['name' => 'X Y', 'phone' => '09170001111', 'branchId' => 1, 'deviceType' => 'laptop', 'symptoms' => 'laptop will not turn on at all', 'consent' => true])), 400, "cannot intake into another shop's branch");

    $rep = $mine->get('/api/reports/summary')['data'];
    T::eq($rep['totals']['repairs'], 1);
    T::ok($other->get('/api/reports/summary')['data']['totals']['repairs'] > 5);
});

T::test("knowledge base: shared base cases are read-only; a shop's own approved cases only improve its own AI", function () {
    $shopA = new Client();
    $shopA->post('/api/business/signup', signup(['businessName' => 'KB Shop A', 'email' => 'kba@kuya.test']));
    approve($shopA);
    $shopB = new Client();
    $shopB->post('/api/business/signup', signup(['businessName' => 'KB Shop B', 'email' => 'kbb@kuya.test']));
    approve($shopB);
    $baseCases = $shopA->get('/api/cases?status=approved')['data'];
    T::ok(count($baseCases) >= 100 && !count(array_filter($baseCases, fn($c) => !$c['shared'])), 'new shops start with the shared base knowledge');
    T::eq(status($shopA->patch("/api/cases/{$baseCases[0]['id']}", ['status' => 'rejected'])), 404, 'shared base cases cannot be edited by a shop');
    $add = $shopA->post('/api/cases', ['device_type' => 'smartphone', 'brand' => 'Zzyx', 'symptoms' => 'quantum flux capacitor buzzing zzyx whirl', 'diagnosis' => 'Flux capacitor misalignment', 'repair_action' => 'Realign']);
    T::eq($add['data']['status'], 'approved');
    $probe = ['deviceType' => 'smartphone', 'symptoms' => 'quantum flux capacitor buzzing zzyx whirl'];
    $a = (new Client())->post('/api/public/diagnose', $probe + ['shop' => 'kb-shop-a'])['data'];
    $bRes = (new Client())->post('/api/public/diagnose', $probe + ['shop' => 'kb-shop-b'])['data'];
    T::ok($a['found'] && $a['areas'][0]['name'] === 'Flux capacitor misalignment');
    T::ok(!$bRes['found'] || !count(array_filter($bRes['areas'], fn($x) => $x['name'] === 'Flux capacitor misalignment')), "shop B does not learn shop A's private cases");
    T::ok(!count(array_filter($shopB->get('/api/cases?status=approved')['data'], fn($c) => $c['diagnosis'] === 'Flux capacitor misalignment')));
});

T::test('public client side: shop directory and the free AI check', function () {
    $anon = new Client();
    $shops = $anon->get('/api/public/shops')['data'];
    T::ok(count(array_filter($shops, fn($s) => $s['slug'] === 'tim-solutions' && count($s['branches']) === 3)) === 1);
    T::ok(!count(array_filter($shops, fn($s) => array_key_exists('email', $s) || array_key_exists('phone', $s))), 'directory exposes no contact data of the business owner');
    T::eq(count($anon->get('/api/public/shops?q=tim')['data']), 1);
    T::eq(count($anon->get('/api/branches')['data']), 0, 'no shop chosen -> no branches');
    T::eq(count($anon->get('/api/branches?shop=tim-solutions')['data']), 3);
    T::eq(status($anon->post('/api/public/diagnose', ['deviceType' => 'toaster', 'symptoms' => 'phone will not turn on'])), 400);
    $swollen = $anon->post('/api/public/diagnose', ['deviceType' => 'smartphone', 'symptoms' => 'phone back cover is lifting, the battery looks swollen and bulging']);
    T::eq($swollen['status'], 200);
    T::ok($swollen['data']['found']);
    T::ok(count($swollen['data']['safety']) > 0, 'safety advice shown for a swollen battery');
    T::ok(!preg_match('/est_cost|cost_|PHP|₱/u', $swollen['raw']), 'no prices in the client-side check');
    $none = $anon->post('/api/public/diagnose', ['deviceType' => 'other', 'symptoms' => 'zzzz qqqq xxxx wwww vvvv']);
    T::eq($none['data']['found'], false);
});

T::test('a registered customer can use several shops and each shop only sees its own record', function () {
    $c = new Client();
    $reg = $c->post('/api/auth/register', ['name' => 'Multi Shopper', 'email' => 'multi@example.com', 'phone' => '09179990001', 'password' => 'Shopper#2026', 'consent' => true]);
    T::eq($reg['status'], 200);
    $owner = new Client();
    $owner->post('/api/business/signup', signup(['businessName' => 'Second Shop For Customer', 'email' => 'second@kuya.test']));
    approve($owner);
    $secondBranch = $owner->get('/api/branches')['data'][0]['id'];
    T::eq(status($c->post('/api/public/bookings', booking(['branchId' => 1, 'symptoms' => 'screen cracked after drop, touch still works']))), 200);
    T::eq(status($c->post('/api/public/bookings', booking(['branchId' => $secondBranch, 'symptoms' => 'phone will not charge, port feels loose']))), 200);
    T::eq(count($c->get('/api/repairs')['data']), 2, 'customer sees repairs from both shops in one place');
    T::eq(count($owner->get('/api/repairs')['data']), 1, 'shop sees only the repair made at its own branch');
    T::eq(count($owner->get('/api/customers')['data']), 1);
});

T::test("a new account never inherits another person's records by matching e-mail or phone", function () {
    $front = as_('frontdesk');
    $w = $front->post('/api/repairs', ['name' => 'Walk In Victim', 'phone' => '09173334444', 'email' => 'victim@example.com', 'branchId' => 1, 'deviceType' => 'laptop', 'symptoms' => 'laptop keyboard keys not working', 'consent' => true]);
    T::eq($w['status'], 200);
    $attacker = new Client();
    $attacker->post('/api/auth/register', ['name' => 'Someone Else', 'email' => 'victim@example.com', 'phone' => '09173334444', 'password' => 'Attacker#2026', 'consent' => true]);
    T::eq(count($attacker->get('/api/repairs')['data']), 0, 'unverified e-mail/phone must not reveal existing repairs');
    T::eq(status($attacker->get("/api/repairs/{$w['data']['id']}")), 404);
    $attacker->post('/api/public/bookings', booking(['branchId' => 1, 'symptoms' => 'screen flickers and shows lines']));
    $mine = $attacker->get('/api/repairs')['data'];
    T::eq(count($mine), 1);
    T::ok($mine[0]['customer_name'] !== 'Walk In Victim');
});

T::test('CSV import: admin-only, previews first, rejects bad rows, skips duplicates, reuses diagnosis names, stays private to the shop', function () {
    $csv = implode("\r\n", [
        'device_type,brand,model,symptoms,diagnosis,repair_action,cost_min,cost_max,hours',
        'phone,Zorbo,Z1,"zorbo widget keeps humming and glowing blue, sometimes ""freezes"" too",Zorbo widget fault,Reseat the widget,300,500,1',
        'laptop,Zorbo,L1,zorbo laptop widget hum with screen flicker,zorbo widget fault,Reseat widget,₱400,600,2',
        'smartphone,Zorbo,Z2,short,Zorbo widget fault,,,,',
        'smartphone,Zorbo,Z3,phone will not hold a charge and dies at thirty percent,Battery degradation / failing battery,Replace battery,abc,,',
        'phone,Zorbo,Z1,"zorbo widget keeps humming and glowing blue, sometimes ""freezes"" too",Zorbo widget fault,,,,',
    ]);
    $front = as_('frontdesk');
    T::eq(status($front->post('/api/cases/import', ['csv' => $csv])), 403);
    T::eq(status(as_('tech1')->post('/api/cases/import', ['csv' => $csv])), 403);

    $admin = as_('admin');
    T::eq(status($admin->post('/api/cases/import', ['csv' => "foo,bar\n1,2"])), 400, 'missing required columns');
    $before = count($admin->get('/api/cases?status=approved&q=zorbo')['data']);
    $prev = $admin->post('/api/cases/import', ['csv' => $csv]);
    T::eq($prev['status'], 200, json_encode($prev['data']));
    T::eq($prev['data']['dry_run'], true);
    T::eq($prev['data']['total_rows'], 5);
    T::eq($prev['data']['valid'], 2);
    T::eq($prev['data']['duplicates'], 1);
    T::eq($prev['data']['rejected'], 2);
    $rows = array_map(fn($e) => $e['row'], $prev['data']['errors']); sort($rows);
    T::eq($rows, [4, 5]);
    T::eq(count($admin->get('/api/cases?status=approved&q=zorbo')['data']), $before, 'preview saves nothing');

    $done = $admin->post('/api/cases/import', ['csv' => $csv, 'dryRun' => false]);
    T::eq($done['data']['imported'], 2);
    T::eq($done['data']['relabelled'], 1, '"zorbo widget fault" reused the spelling of the first row');
    $mine = $admin->get('/api/cases?status=approved&q=zorbo')['data'];
    T::eq(count($mine), $before + 2);
    T::ok(!count(array_filter($mine, fn($c) => $c['source'] !== 'import' || $c['diagnosis'] !== 'Zorbo widget fault')));
    T::eq($admin->post('/api/cases/import', ['csv' => $csv, 'dryRun' => false])['data']['imported'], 0, 're-importing the same file adds nothing');

    $probe = ['deviceType' => 'smartphone', 'symptoms' => 'zorbo widget humming glowing blue'];
    $own = (new Client())->post('/api/public/diagnose', $probe + ['shop' => 'tim-solutions'])['data'];
    T::ok($own['found'] && $own['areas'][0]['name'] === 'Zorbo widget fault');
    $otherShop = (new Client())->post('/api/public/diagnose', $probe + ['shop' => 'sample-repair-hub'])['data'];
    T::ok(!$otherShop['found'] || !count(array_filter($otherShop['areas'], fn($a) => $a['name'] === 'Zorbo widget fault')));
});

T::test('sales report: admin and owner only, shop-scoped, buckets add up to the totals, CSV export is audited', function () {
    $db = Client::$db;
    $fd = as_('frontdesk');
    foreach ([$fd, as_('tech1'), as_('customer')] as $c) T::eq(status($c->get('/api/reports/sales?range=month')), 403, 'only admin and owner see sales');
    T::eq(status((new Client())->get('/api/reports/sales')), 401);

    $repair = $db->get('SELECT id FROM repairs WHERE shop_id = 1 LIMIT 1');
    $hourBefore = (int)gmdate('G');
    $db->run("INSERT INTO payments (repair_id, amount, method, created_at) VALUES (?, 1234.56, 'gcash', ?)", [$repair['id'], $db->now()]);
    $hourAfter = (int)gmdate('G');

    $admin = as_('admin');
    foreach ([$admin, as_('owner')] as $c) {
        $t = $c->get('/api/reports/sales?range=today&tz=0')['data'];
        T::eq(count($t['buckets']), 24);
        T::ok($t['totals']['total'] >= 1234.56 && $t['totals']['count'] >= 1);
        T::ok($t['buckets'][$hourBefore]['total'] >= 1234.56 || $t['buckets'][$hourAfter]['total'] >= 1234.56, 'payment is in the current hour bucket');
        T::ok(count(array_filter($t['by_method'], fn($m) => $m['method'] === 'gcash')) > 0);
        $days = (int)gmdate('t');
        foreach (['today' => 24, 'month' => $days, 'year' => 12] as $range => $n) {
            $r = $c->get("/api/reports/sales?range=$range&tz=0")['data'];
            T::eq($r['range'], $range);
            T::eq(count($r['buckets']), $n);
            $sum = round(array_sum(array_map(fn($b) => $b['total'], $r['buckets'])), 2);
            T::ok(abs($sum - $r['totals']['total']) < 0.001, "$range: bucket sum equals total");
            T::eq(array_sum(array_map(fn($b) => $b['count'], $r['buckets'])), $r['totals']['count']);
            T::eq($r['next_at'], null, 'cannot page into the future');
            T::ok($r['prev_at']);
        }
    }

    $cur = $admin->get('/api/reports/sales?range=month&tz=0')['data'];
    $prev = $admin->get("/api/reports/sales?range=month&tz=0&at={$cur['prev_at']}")['data'];
    T::ok($prev['label'] !== $cur['label']);
    T::eq($cur['totals']['comparison'], 'same_point', 'an unfinished month is compared with the same point of last month');
    T::eq($prev['totals']['comparison'], 'full', 'a finished month is compared with the whole previous month');
    T::ok($cur['totals']['previous_total'] <= $prev['totals']['total'] + 0.001, 'same-point comparison never exceeds the full previous period');
    T::eq($admin->get('/api/reports/sales?range=bogus&tz=zzz&at=not-a-date')['data']['range'], 'month');
    T::eq($admin->get('/api/reports/sales?range=month&tz=99999')['data']['tz'], 480);

    $other = as_('admin2')->get('/api/reports/sales?range=year&tz=0')['data'];
    $mine = $admin->get('/api/reports/sales?range=year&tz=0')['data'];
    T::ok($other['totals']['total'] < $mine['totals']['total'], 'shop 2 total excludes shop 1 payments');
    T::ok(!count(array_filter($other['log'], fn($r) => !$r['code'])), 'rows belong to shop 2 repairs');
    $mineIds = array_map(fn($m) => $m['id'], $mine['log']);
    T::ok(!count(array_filter($other['log'], fn($r) => in_array($r['id'], $mineIds, true))), 'no shared payment rows');

    $res = $admin->req('GET', '/api/reports/sales.csv?range=year&tz=0');
    T::eq($res['status'], 200);
    T::match('#text/csv#', $res['headers']['Content-Type']);
    T::match('/sales-year-\d{4}-\d{2}-\d{2}\.csv/', $res['headers']['Content-Disposition']);
    $csv = $res['raw'];
    T::match('#Date/time \(local\),Repair code,Customer,Branch,Method,Amount \(PHP\),Received by#', $csv);
    T::match('/\r\nTotal,/', $csv);
    T::ok(str_contains($csv, '1234.56'));
    T::eq(status($fd->req('GET', '/api/reports/sales.csv')), 403);
    T::ok(count(array_filter($admin->get('/api/audit?limit=50')['data'], fn($l) => $l['action'] === 'report.export')) > 0, 'export recorded in the audit log');
});

T::test('new shops start pending: not listed, cannot take bookings until a platform administrator approves', function () {
    $shop = new Client();
    $r = $shop->post('/api/business/signup', signup(['businessName' => 'Pending Fix Hub', 'email' => 'pending@kuya.test']));
    T::eq($r['status'], 200);
    T::eq($r['data']['shop']['status'], 'pending');
    $slug = $r['data']['shop']['slug'];
    $branch = $shop->get('/api/branches')['data'][0]['id'];
    $anon = new Client();
    T::ok(!count(array_filter($anon->get('/api/public/shops')['data'], fn($s) => $s['slug'] === $slug)), 'pending shop is not in the public directory');
    T::eq(status($anon->get("/api/public/shop/$slug")), 404, 'its booking link does not work yet');
    T::eq(status($anon->post('/api/public/bookings', booking(['branchId' => $branch]))), 400, 'no bookings while pending');
    T::eq(count($anon->get("/api/branches?shop=$slug")['data']), 0);
    // the shop can still sign in and set itself up
    T::eq(status($shop->get('/api/repairs')), 200);
});

T::test('only the platform administrator can review shops; approve makes the personal booking link work', function () {
    $shop = new Client();
    $shop->post('/api/business/signup', signup(['businessName' => 'Review Me Repairs', 'email' => 'reviewme@kuya.test']));
    $me = $shop->get('/api/auth/me')['data']['user']['shop'];
    foreach ([as_('admin'), as_('owner'), as_('customer'), new Client(), $shop] as $who) {
        T::ok(in_array(status($who->get('/api/platform/shops')), [401, 403]), 'non-platform users cannot list shops for review');
        T::ok(in_array(status($who->post("/api/platform/shops/{$me['id']}/review", ['action' => 'approve'])), [401, 403]), 'non-platform users cannot approve');
    }
    T::eq(status(as_('admin')->post('/api/users', ['name' => 'Sneaky', 'email' => 'sneaky@repairsync.test', 'role' => 'platform', 'password' => 'Sneaky#2026ok'])), 400, 'a shop admin cannot create a platform account');
    $platform = as_('platform');
    $list = $platform->get('/api/platform/shops?status=pending');
    T::eq($list['status'], 200);
    $row = array_values(array_filter($list['data']['shops'], fn($s) => $s['slug'] === $me['slug']))[0] ?? null;
    T::ok($row !== null && $row['status'] === 'pending' && $row['admin_name'] === 'Ken Owner', 'pending shop appears with the registrant details');
    T::eq(status($platform->post("/api/platform/shops/{$me['id']}/review", ['action' => 'reject'])), 400, 'rejecting needs a reason');
    T::eq(status($platform->post("/api/platform/shops/{$me['id']}/review", ['action' => 'suspend', 'note' => 'x'])), 400, 'cannot suspend a shop that is not approved');
    $ok = $platform->post("/api/platform/shops/{$me['id']}/review", ['action' => 'approve']);
    T::eq($ok['data']['status'], 'active');
    T::eq(status($platform->post("/api/platform/shops/{$me['id']}/review", ['action' => 'approve'])), 400, 'already approved');
    $anon = new Client();
    $page = $anon->get("/api/public/shop/{$me['slug']}");
    T::eq($page['status'], 200);
    T::eq($page['data']['name'], 'Review Me Repairs');
    T::eq(count($page['data']['branches']), 1);
    T::ok(count(array_filter($anon->get('/api/public/shops')['data'], fn($s) => $s['slug'] === $me['slug'])) === 1, 'approved shop is now listed');
    $b = $anon->post('/api/public/bookings', booking(['branchId' => $page['data']['branches'][0]['id']]));
    T::eq($b['status'], 200, 'booking through the shop link works');
    T::ok(count(array_filter(as_('admin')->get('/api/audit?limit=300')['data'], fn($l) => $l['action'] === 'shop.approve')) === 0, 'review is logged on the reviewed shop, not on other shops');
    T::ok(count(array_filter($shop->get('/api/audit?limit=50')['data'], fn($l) => $l['action'] === 'shop.approve')) === 1, 'approval is in the shop audit log');
});

T::test('reject and suspend: the shop is blocked from signing in and from booking, with the reason shown', function () {
    $platform = as_('platform');
    $shop = new Client();
    $shop->post('/api/business/signup', signup(['businessName' => 'Rejected Repairs', 'email' => 'rej@kuya.test']));
    $id = $shop->get('/api/auth/me')['data']['user']['shop']['id'];
    $slug = $shop->get('/api/auth/me')['data']['user']['shop']['slug'];
    T::eq(status($platform->post("/api/platform/shops/$id/review", ['action' => 'reject', 'note' => 'Could not verify the business address'])), 200);
    T::eq(status($shop->get('/api/repairs')), 401, 'existing session of a rejected shop stops working');
    $login = (new Client())->post('/api/auth/login', ['email' => 'rej@kuya.test', 'password' => 'Gadget#2026ok']);
    T::eq($login['status'], 403);
    T::match('/Could not verify the business address/', $login['data']['error']);
    T::eq(status((new Client())->get("/api/public/shop/$slug")), 404);
    // a rejected shop can be approved after the problem is fixed, then suspended later
    T::eq(status($platform->post("/api/platform/shops/$id/review", ['action' => 'approve'])), 200);
    T::eq(status((new Client())->get("/api/public/shop/$slug")), 200);
    T::eq(status($platform->post("/api/platform/shops/$id/review", ['action' => 'suspend', 'note' => 'Complaints from clients'])), 200);
    T::eq(status((new Client())->get("/api/public/shop/$slug")), 404);
    T::eq(status((new Client())->post('/api/auth/login', ['email' => 'rej@kuya.test', 'password' => 'Gadget#2026ok'])), 403);
    T::ok(!count(array_filter((new Client())->get('/api/public/shops')['data'], fn($s) => $s['slug'] === $slug)));
    $counts = $platform->get('/api/platform/shops?status=all')['data']['counts'];
    T::ok($counts['suspended'] >= 1 && $counts['active'] >= 2);
});

T::test("TIM'SOLUTIONS defaults and shop settings: main-branch hours show on the shop page; only the administrator edits; shops are isolated", function () {
    $anon = new Client();
    $page = $anon->get('/api/public/shop/tim-solutions')['data'];
    T::eq($page['name'], "TIM'SOLUTIONS");
    $tisa = array_values(array_filter($page['branches'], fn($b) => str_contains($b['name'], 'Tisa')))[0];
    T::match('/Main Branch/', $tisa['name']);
    T::eq($tisa['hours'], '8:00 AM – 9:00 PM');
    $admin = as_('admin');
    $shop = $admin->get('/api/shop')['data'];
    T::eq($shop['slug'], 'tim-solutions');
    T::eq(count($shop['branches']), 3);
    foreach ([as_('owner'), as_('frontdesk'), as_('tech1'), as_('customer'), new Client()] as $who) {
        T::ok(in_array(status($who->patch('/api/shop', ['name' => 'Hacked'])), [401, 403]), 'only the administrator edits shop details');
        T::ok(in_array(status($who->patch("/api/branches/{$tisa['id']}", ['name' => 'x', 'address' => 'yy'])), [401, 403]));
    }
    T::eq(status($admin->patch("/api/branches/{$tisa['id']}", ['name' => $tisa['name'], 'address' => '127 F. Llamas St., Tisa, Cebu City', 'phone' => '0917 000 0000', 'hours' => '8:00 AM – 9:00 PM'])), 200);
    T::eq(status($admin->patch("/api/branches/{$tisa['id']}", ['name' => '', 'address' => 'yy'])), 400, 'name is required');
    $again = $anon->get('/api/public/shop/tim-solutions')['data'];
    T::eq(array_values(array_filter($again['branches'], fn($b) => $b['id'] === $tisa['id']))[0]['phone'], '0917 000 0000');
    $add = $admin->post('/api/branches', ['name' => 'TIM\'SOLUTIONS – Test Kiosk', 'address' => 'Somewhere, Cebu', 'hours' => '10:00 AM – 7:00 PM']);
    T::eq($add['status'], 200);
    T::eq(count($admin->get('/api/shop')['data']['branches']), 4);
    // another shop's administrator can neither see nor edit these branches
    $other = new Client();
    $other->post('/api/business/signup', signup(['businessName' => 'Other Settings Shop', 'email' => 'other-settings@kuya.test']));
    T::eq(status($other->patch("/api/branches/{$tisa['id']}", ['name' => 'Stolen', 'address' => 'zz'])), 404);
    T::eq(count($other->get('/api/shop')['data']['branches']), 1);
    T::eq(status($admin->patch('/api/shop', ['name' => "TIM'SOLUTIONS", 'city' => 'Cebu City', 'phone' => '0917 111 1111'])), 200);
});

T::test('past repairs import: preview first, privacy by design, dedupe, feeds the sales report and (optionally) the AI knowledge base', function () {
    $shop = new Client();
    $shop->post('/api/business/signup', signup(['businessName' => 'Import Test Repairs', 'email' => 'import@kuya.test']));
    approve($shop);
    $csv = "job_no,date_received,date_released,device_type,brand,model,problem,diagnosis,repair_done,status,parts_cost,labor_cost,total,amount_paid,payment_method,customer_name,customer_phone\n"
        . "JO-1,2025-03-02,2025-03-04,smartphone,Samsung,Galaxy A12,Nahulog sa tubig ayaw mag on,Liquid / water damage,Ultrasonic clean and replaced charging IC,released,1200,500,1700,1700,GCash,Juan Dela Cruz,09171234567\n"
        . "JO-2,3/5/2025,3/5/2025,laptop,Lenovo,IdeaPad 3,Laptop slow and clicking noise from drive,Failing storage drive,Replaced with SSD,released,1800,700,,2500,cash,Maria Santos,09179998888\n"
        . "JO-3,2025-03-06,2025-03-06,tablet,Apple,iPad 9,Cracked screen no touch response,Cracked or damaged display assembly,,released,,,3200,3200,cash,,\n"
        . "JO-4,2025-03-07,2025-03-07,smartphone,Oppo,A16,Hindi na nag cha-charge,,,cancelled,,,,,,,\n"
        . "JO-5,13/45/2025,2025-03-08,smartphone,x,y,Some problem here,,,released,,,500,,,,\n"
        . "JO-6,2025-03-08,2025-03-08,smartphone,x,y,Some problem here,,,released,900,300,500,,,,\n"
        . "JO-7,2999-01-01,2999-01-02,smartphone,x,y,Some problem here,,,released,,,500,,,,\n";
    $perm = ['permission' => true];
    T::eq(status(as_('frontdesk')->post('/api/repairs/import', ['csv' => $csv])), 403, 'administrators only');
    T::eq(status(as_('owner')->post('/api/repairs/import', ['csv' => $csv])), 403);
    T::eq(status((new Client())->post('/api/repairs/import', ['csv' => $csv])), 401);
    T::eq(status($shop->post('/api/repairs/import', ['csv' => "date_received,problem\n2025-01-01,Some problem here\n"])), 400, 'amount column required');
    $dry = $shop->post('/api/repairs/import', ['csv' => $csv]);
    T::eq($dry['status'], 200, json_encode($dry['data']));
    $d = $dry['data'];
    T::eq([$d['dry_run'], $d['valid'], $d['rejected'], $d['imported']], [true, 3 + 1, 3, 0]);
    T::eq($d['total_sales'], 7400.0);
    T::eq(count($shop->get('/api/repairs')['data']), 0, 'a preview stores nothing');
    T::eq(status($shop->post('/api/repairs/import', ['csv' => $csv, 'dryRun' => false])), 400, 'commit needs the permission confirmation');
    $done = $shop->post('/api/repairs/import', ['csv' => $csv, 'dryRun' => false, 'permission' => true, 'addToKb' => true]);
    T::eq($done['status'], 200, json_encode($done['data']));
    T::eq($done['data']['imported'], 4);
    T::eq($done['data']['kb_added'], 3);
    // privacy: names and numbers in the file are never stored
    $rows = $shop->get('/api/repairs')['data'];
    T::eq(count($rows), 4);
    $blob = json_encode($rows) . json_encode($shop->get('/api/customers')['data']);
    T::ok(!str_contains($blob, 'Juan') && !str_contains($blob, 'Maria') && !str_contains($blob, '09171234567') && !str_contains($blob, '09179998888'), 'customer names and numbers are not imported');
    T::ok(str_contains($blob, 'Past customer (imported, anonymized)'));
    // re-importing the same file adds nothing
    $again = $shop->post('/api/repairs/import', ['csv' => $csv, 'dryRun' => false] + $perm)['data'];
    T::eq([$again['imported'], $again['duplicates']], [0, 4]);
    // the sales report now shows the imported payments in March 2025
    $m = $shop->get('/api/reports/sales?range=month&at=2025-03-15&tz=480')['data'];
    T::eq($m['totals']['total'], 7400.0);
    T::eq($m['totals']['count'], 3);
    T::ok(count(array_filter($m['by_method'], fn($x) => $x['method'] === 'gcash')) === 1);
    $one = array_values(array_filter($rows, fn($r) => $r['status'] === 'cancelled'));
    T::eq(count($one), 1);
    // the verified diagnoses became approved cases for this shop only, and they help this shop's AI
    $cases = $shop->get('/api/cases?status=approved')['data'];
    T::ok(count(array_filter($cases, fn($c) => !$c['shared'] && $c['diagnosis'] === 'Failing storage drive')) === 1);
    $ai = (new Client())->post('/api/public/diagnose', ['deviceType' => 'laptop', 'symptoms' => 'Laptop slow and clicking noise from drive', 'shop' => 'import-test-repairs'])['data'];
    T::ok($ai['found']);
    // the audit log records the import
    T::ok(count(array_filter($shop->get('/api/audit?limit=50')['data'], fn($l) => $l['action'] === 'repairs.import')) === 1);
    // nothing leaks into another shop
    T::eq(count(as_('admin')->get('/api/repairs?q=JO-')['data']), 0);
});

T::test('per-shop branding: admins and owners save name, accent and logo; staff, customers and other shops cannot', function () use ($uploadDir) {
    $anon = new Client();
    $admin = as_('admin');
    $owner = as_('owner');
    $other = as_('admin2'); // shop 2
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $png = 'data:image/png;base64,' . base64_encode($pngBytes);

    // RBAC: every branding endpoint is Administrator/Owner only.
    foreach ([as_('frontdesk'), as_('tech1'), as_('customer'), new Client()] as $who) {
        T::ok(in_array(status($who->patch('/api/shop/name', ['name' => 'Hacked'])), [401, 403]), 'name edit is admin/owner only');
        T::ok(in_array(status($who->patch('/api/shop/theme', ['accent' => '#7FB2E5'])), [401, 403]), 'theme edit is admin/owner only');
        T::ok(in_array(status($who->put('/api/shop/logo', ['image' => $png])), [401, 403]), 'logo upload is admin/owner only');
    }

    // Name: validation, persistence and propagation into auth, settings and the public shop page.
    T::eq(status($admin->patch('/api/shop/name', ['name' => ''])), 400, 'empty shop name is rejected');
    T::eq(status($admin->patch('/api/shop/name', ['name' => str_repeat('x', 81)])), 400);
    $renamed = $owner->patch('/api/shop/name', ['name' => 'Branding Unit Shop']);
    T::eq($renamed['status'], 200, json_encode($renamed['data']));
    T::eq($renamed['data']['name'], 'Branding Unit Shop');
    T::eq($admin->get('/api/auth/me')['data']['user']['shop']['name'], 'Branding Unit Shop');
    T::eq($admin->get('/api/shop')['data']['name'], 'Branding Unit Shop');
    T::eq($anon->get('/api/public/shop/tim-solutions')['data']['name'], 'Branding Unit Shop');

    // Accent: only curated presets are accepted and the choice is returned on every login.
    foreach (['#fff', 'red', '', '#A7CF96 '] as $bad) T::eq(status($admin->patch('/api/shop/theme', ['accent' => $bad])), 400);
    $theme = $admin->patch('/api/shop/theme', ['accent' => '#7FB2E5']);
    T::eq($theme['status'], 200, json_encode($theme['data']));
    T::eq($theme['data']['accent'], '#7FB2E5');
    T::eq($owner->get('/api/auth/me')['data']['user']['shop']['accent_color'], '#7FB2E5');
    T::eq($owner->get('/api/shop')['data']['accent_color'], '#7FB2E5');

    // Logo: decoded data URL is validated, stored under this shop only, and exposed on its public page.
    T::eq(status($admin->put('/api/shop/logo', ['image' => 'data:text/plain;base64,aGk='])), 400);
    T::eq(status($admin->put('/api/shop/logo', ['image' => 'not a data URL'])), 400);
    $huge = 'data:image/png;base64,' . base64_encode(str_repeat('x', 2 * 1024 * 1024 + 1));
    T::eq(status($admin->put('/api/shop/logo', ['image' => $huge])), 400, 'decoded logo over 2 MB is rejected');
    $logo = $owner->put('/api/shop/logo', ['image' => $png]);
    T::eq($logo['status'], 200, json_encode($logo['data']));
    T::match('#^shops/1/[a-f0-9]{32}\.png$#', $logo['data']['logo_path']);
    T::eq($logo['data']['logo_url'], '/uploads/' . $logo['data']['logo_path']);
    T::ok(is_file($uploadDir . '/' . $logo['data']['logo_path']), 'the logo file exists in the shop-scoped upload directory');
    T::eq($admin->get('/api/auth/me')['data']['user']['shop']['logo_path'], $logo['data']['logo_path']);
    $public = $anon->get('/api/public/shop/tim-solutions')['data'];
    T::eq($public['logo_path'], $logo['data']['logo_path'], 'the public shop page uses the same logo');
    T::eq(array_values(array_filter($anon->get('/api/public/shops')['data'], fn($s) => $s['slug'] === 'tim-solutions'))[0]['logo_path'], $logo['data']['logo_path']);
    $second = $admin->put('/api/shop/logo', ['image' => $png]);
    T::eq($second['status'], 200);
    T::ok(!is_file($uploadDir . '/' . $logo['data']['logo_path']), 'the previous logo file is removed');
    T::ok(is_file($uploadDir . '/' . $second['data']['logo_path']));

    // A body shopId cannot redirect a write: the session's shop_id always wins.
    $before = $anon->get('/api/public/shop/tim-solutions')['data'];
    $foreign = $other->patch('/api/shop/name', ['name' => 'Shop Two Only', 'shopId' => 1]);
    T::eq($foreign['status'], 200);
    T::eq($anon->get('/api/public/shop/tim-solutions')['data']['name'], $before['name'], 'shop 2 cannot rename shop 1');
    T::eq($other->get('/api/shop')['data']['name'], 'Shop Two Only', 'shop 2 only changed its own name');
    $foreignLogo = $other->put('/api/shop/logo', ['image' => $png]);
    T::eq($foreignLogo['status'], 200);
    T::match('#^shops/2/[a-f0-9]{32}\.png$#', $foreignLogo['data']['logo_path']);
    T::eq($anon->get('/api/public/shop/tim-solutions')['data']['logo_path'], $second['data']['logo_path'], 'shop 1 logo is untouched');

    T::ok(count(array_filter($admin->get('/api/audit?limit=80')['data'], fn($l) => str_starts_with((string)$l['action'], 'shop.branding.'))) >= 4, 'branding changes are audited');
});

T::test('public map pin and weekly hours: owner/admin only, session-scoped, exposed to anonymous visitors', function () {
    $anon = new Client();
    $owner = as_('owner');
    $admin = as_('admin');
    $other = as_('admin2');
    $hours = ['mon' => [['08:00', '21:00']], 'sun' => [['10:00', '17:00']]];
    $pin = ['address' => '123 Map Lane, Cebu City', 'lat' => 10.3157123, 'lng' => 123.8854123, 'openingHours' => $hours];

    foreach ([as_('frontdesk'), as_('tech1'), as_('customer'), $anon] as $who) {
        T::ok(in_array(status($who->patch('/api/shop/location', $pin)), [401, 403]), 'location settings are admin/owner only');
    }
    T::eq(status($owner->patch('/api/shop/location', ['address' => 'A', 'lat' => 91, 'lng' => 0, 'openingHours' => null])), 400);
    T::eq(status($owner->patch('/api/shop/location', ['address' => 'A', 'lat' => 0, 'lng' => -181, 'openingHours' => null])), 400);
    T::eq(status($owner->patch('/api/shop/location', ['address' => '', 'lat' => 10, 'lng' => 123, 'openingHours' => null])), 400);
    T::eq(status($owner->patch('/api/shop/location', ['address' => 'A', 'lat' => 10, 'lng' => 123, 'openingHours' => ['mon' => [['25:00', '26:00']]]])), 400);
    T::eq(status($owner->patch('/api/shop/location', ['address' => 'A', 'lat' => 10, 'lng' => 123, 'openingHours' => ['mon' => [['18:00', '09:00']]]])), 400);

    $saved = $owner->patch('/api/shop/location', $pin);
    T::eq($saved['status'], 200, json_encode($saved['data']));
    T::eq($saved['data']['address'], $pin['address']);
    T::eq($saved['data']['lat'], 10.3157123);
    T::eq($saved['data']['lng'], 123.8854123);
    T::eq($saved['data']['opening_hours']['mon'], [['08:00', '21:00']]);
    T::eq($saved['data']['opening_hours']['sun'], [['10:00', '17:00']]);
    T::eq($saved['data']['opening_hours']['tue'], [], 'days with no ranges are stored as closed');

    $public = $anon->get('/api/public/shop/tim-solutions')['data'];
    T::eq($public['address'], $pin['address'], 'anonymous shop page sees the saved address');
    T::eq($public['lat'], 10.3157123, 'anonymous shop page sees the exact pin');
    T::eq($public['lng'], 123.8854123);
    T::eq($public['opening_hours']['mon'], [['08:00', '21:00']]);
    $listed = array_values(array_filter($anon->get('/api/public/shops')['data'], fn($s) => $s['slug'] === 'tim-solutions'));
    T::eq($listed[0]['lat'], 10.3157123, 'the public directory carries the same pin for the finder map');
    T::ok(array_key_exists('logo_url', $listed[0]), 'public shops expose a logo URL for circular markers');
    $meShop = $owner->get('/api/auth/me')['data']['user']['shop'];
    T::eq($meShop['address'], $pin['address']);
    T::eq($meShop['opening_hours']['mon'], [['08:00', '21:00']]);
    T::ok(is_array($owner->get('/api/shop')['data']['opening_hours']), 'settings receive decoded weekly hours');

    // A body-level shopId cannot redirect the write to another shop.
    $moved = $admin->patch('/api/shop/location', array_replace($pin, ['address' => '999 Other Shop', 'shopId' => 999]));
    T::eq($moved['status'], 200);
    T::eq($owner->get('/api/shop')['data']['address'], '999 Other Shop', 'shop 1 still writes only to shop 1');
    $other->patch('/api/shop/location', ['address' => 'Shop Two Address', 'lat' => 14.5995, 'lng' => 120.9842, 'openingHours' => null]);
    T::eq($owner->get('/api/shop')['data']['address'], '999 Other Shop', 'shop 2 cannot move shop 1');

    T::ok(count(array_filter($admin->get('/api/audit?limit=500')['data'], fn($l) => $l['action'] === 'shop.branding.location')) >= 2, 'map pin changes are audited');
});

exit(T::summary());
