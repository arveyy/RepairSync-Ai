<?php
declare(strict_types=1);

namespace RepairSync;

/** Seeds branches, demo users, the baseline knowledge base, sample repairs and synthetic sales history. */
final class Seeder
{
    public const DEMO_PASSWORD = 'RepairSync#2026';

    public const SHOPS = [
        ["TIM'SOLUTIONS", 'tim-solutions', 'Cebu City'],
        ['Sample Repair Hub (demo)', 'sample-repair-hub', 'Davao City'],
    ];
    public const BRANCHES = [
        [1, "TIM'SOLUTIONS – Tisa (Main Branch)", '127 F. Llamas St., Tisa, Cebu City'],
        [1, "TIM'SOLUTIONS – SM JMall Cyberzone", 'SM JMall 3rd Floor Cyberzone, Mandaue City'],
        [1, "TIM'SOLUTIONS – Lapulapu (Gun-ob)", '2nd Floor Antiguas Place, Gun-ob, Lapu-Lapu City'],
        [2, 'Sample Repair Hub – Main', 'Sample address, Davao City'],
    ];
    // name, email, phone, role, branch id, shop id (customers have no shop; they own a global account)
    public const DEMO_USERS = [
        ['Tim Owner', 'owner@repairsync.test', '09170000001', 'owner', null, 1],
        ['Alber Admin', 'admin@repairsync.test', '09170000002', 'admin', 1, 1],
        ['Faye Frontdesk', 'frontdesk@repairsync.test', '09170000003', 'frontdesk', 1, 1],
        ['Gina Frontdesk', 'frontdesk2@repairsync.test', '09170000004', 'frontdesk', 2, 1],
        ['Tony Technician', 'tech1@repairsync.test', '09170000005', 'technician', 1, 1],
        ['Tess Technician', 'tech2@repairsync.test', '09170000006', 'technician', 2, 1],
        ['Teddy Technician', 'tech3@repairsync.test', '09170000007', 'technician', 3, 1],
        ['Carla Customer', 'customer@repairsync.test', '09171112222', 'customer', null, null],
        ['Dina Admin (shop 2)', 'admin2@repairsync.test', '09170000008', 'admin', 4, 2],
        ['Dan Technician (shop 2)', 'tech4@repairsync.test', '09170000009', 'technician', 4, 2],
    ];
    private const FLOW = ['booked', 'received', 'diagnosing', 'awaiting_approval', 'repairing', 'quality_check', 'completed', 'released'];

    private static function deviceOf(array $d, string $model, string $symptoms): string
    {
        $types = $d['types'];
        if (count($types) === 1) return $types[0];
        if (in_array('laptop', $types, true) && preg_match('/\blaptop\b|trackpad|touchpad|keyboard/i', $symptoms)) return 'laptop';
        if (in_array('tablet', $types, true) && preg_match('/ipad|\btab\b|matepad|tablet/i', "$model $symptoms")) return 'tablet';
        return in_array('smartphone', $types, true) ? 'smartphone' : $types[0];
    }

    /** Creates the platform administrator (reviews shop registrations) if none exists. Safe to call repeatedly. */
    public static function ensurePlatformAdmin(Db $db, bool $demo, ?array $custom = null): void
    {
        $email = $name = $pass = null;
        if ($custom && !empty($custom['email']) && !empty($custom['password'])) { $email = strtolower(trim((string)$custom['email'])); $pass = (string)$custom['password']; $name = (string)($custom['name'] ?? 'Platform Administrator'); }
        elseif ($demo) { $email = 'platform@repairsync.test'; $pass = self::DEMO_PASSWORD; $name = 'Pat Platform Admin'; }
        if ($email === null || $db->get('SELECT id FROM users WHERE email=?', [$email])) return;
        $db->run("INSERT INTO users (shop_id,name,email,phone,role,password_hash) VALUES (NULL,?,?,NULL,'platform',?)", [$name, $email, Auth::hashPassword($pass)]);
    }

    /** Brings an earlier demo database in line with the TIM'SOLUTIONS naming and the Tisa main-branch hours (safe to repeat). */
    public static function applyTimSolutions(Db $db): void
    {
        $shop = $db->get("SELECT id, name FROM shops WHERE slug='tim-solutions'");
        if (!$shop) return;
        if ($shop['name'] === "TIM'SOLUTIONS (demo)") $db->run('UPDATE shops SET name=? WHERE id=?', ["TIM'SOLUTIONS", $shop['id']]);
        foreach ([["TIM'SOLUTIONS – Tisa", "TIM'SOLUTIONS – Tisa (Main Branch)"], ["TIM'SOLUTIONS – Gun-ob", "TIM'SOLUTIONS – Lapulapu (Gun-ob)"]] as [$old, $new]) {
            $db->run('UPDATE branches SET name=? WHERE shop_id=? AND name=?', [$new, $shop['id'], $old]);
        }
        $db->run("UPDATE branches SET hours=? WHERE shop_id=? AND name LIKE ? AND (hours IS NULL OR hours='')", ['8:00 AM – 9:00 PM', $shop['id'], '%Tisa%']);
    }

    public static function seedBase(Db $db, bool $withDemoUsers = true): void
    {
        $db->tx(function () use ($db, $withDemoUsers) {
            if ($withDemoUsers && !$db->get('SELECT id FROM shops LIMIT 1')) {
                foreach (self::SHOPS as [$n, $slug, $city]) $db->run('INSERT INTO shops (name, slug, city) VALUES (?,?,?)', [$n, $slug, $city]);
                foreach (self::BRANCHES as [$shop, $n, $a]) $db->run('INSERT INTO branches (shop_id, name, address) VALUES (?,?,?)', [$shop, $n, $a]);
            }
            if ($withDemoUsers && !$db->get('SELECT id FROM users LIMIT 1')) {
                $h = Auth::hashPassword(self::DEMO_PASSWORD);
                foreach (self::DEMO_USERS as [$name, $email, $phone, $role, $branch, $shop]) {
                    $id = $db->run('INSERT INTO users (shop_id,name,email,phone,role,password_hash,branch_id) VALUES (?,?,?,?,?,?,?)', [$shop, $name, $email, $phone, $role, $h, $branch]);
                    if ($role === 'customer') $db->run('INSERT INTO customers (shop_id,user_id,name,phone,email,consent_at) VALUES (1,?,?,?,?,?)', [$id, $name, $phone, $email, $db->now()]);
                }
            }
            if (!$db->get('SELECT id FROM cases LIMIT 1')) {
                $diagnoses = json_decode(file_get_contents(dirname(__DIR__) . '/database/seed-cases.json'), true);
                foreach ($diagnoses as $d) {
                    foreach ($d['symptoms'] as [$brand, $model, $symptoms]) {
                        $db->run("INSERT INTO cases (device_type, brand, model_hint, symptoms, diagnosis, root_cause, checks, repair_action, parts, cost_min, cost_max, hours, status, source)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'approved','seed')",
                            [self::deviceOf($d, $model, $symptoms), $brand, $model, $symptoms, $d['diagnosis'], $d['root_cause'], $d['checks'], $d['repair_action'], $d['parts'], $d['cost'][0], $d['cost'][1], $d['hours']]);
                    }
                }
            }
        });
        self::applyTimSolutions($db);
        self::ensurePlatformAdmin($db, $withDemoUsers);
    }

    private static function ago(float $d, float $h = 0): string
    {
        return gmdate('Y-m-d H:i:s', (int)floor(time() - $d * 86400 - $h * 3600));
    }

    public static function seedSampleRepairs(Db $db): void
    {
        $data = json_decode(file_get_contents(dirname(__DIR__) . '/database/seed-samples.json'), true);
        $index = Rag::loadIndex($db);
        $techs = $db->all("SELECT id, branch_id FROM users WHERE role='technician' ORDER BY id");
        $front = $db->get("SELECT id FROM users WHERE role='frontdesk' ORDER BY id LIMIT 1");
        $tech = fn($i) => $i === null ? null : (int)($techs[$i - 5]['id'] ?? $techs[0]['id']);
        $seq = 0;
        $db->tx(function () use ($db, $data, $index, $front, $tech, &$seq) {
            foreach ($data['samples'] as [$name, $phone, $branch, $type, $brand, $model, $symptoms, $status, $techIdx, $items, $diag, $action, $days, $priority]) {
                $seq++;
                $shop = (int)$db->val('SELECT shop_id FROM branches WHERE id=?', [$branch]);
                $c = $db->run('INSERT INTO customers (shop_id, name, phone, consent_at, created_at) VALUES (?,?,?,?,?)', [$shop, $name, $phone, self::ago($days + 0.1), self::ago($days + 0.1)]);
                $created = self::ago($days + 0.1);
                $code = sprintf('RS-%s%s-%04d', gmdate('y'), gmdate('m'), $seq);
                $targetIdx = $status === 'cancelled' ? 2 : array_search($status, self::FLOW, true);
                $stepDays = $days / max(1, count(self::FLOW) - 1);
                $findings = $targetIdx >= 3 ? 'Inspection confirms: ' . ($diag ?: 'fault verified on bench') . '.' : null;
                $approval = $status === 'awaiting_approval' ? 'pending' : ($targetIdx >= 4 ? 'approved' : ($status === 'cancelled' ? 'declined' : 'none'));
                $est = in_array($status, ['repairing', 'quality_check'], true) ? gmdate('Y-m-d', time() + 2 * 86400)
                    : ($status === 'diagnosing' && $seq === 9 ? gmdate('Y-m-d', time() - 86400) : null);
                $rid = $db->run('INSERT INTO repairs (shop_id, code, track_token, customer_id, branch_id, device_type, brand, model, reported_symptoms, status, priority, assigned_to, findings, verified_diagnosis, repair_action, approval_state, qc_passed, source, created_by, created_at, updated_at, completed_at, released_at, est_ready_date)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$shop, $code, sprintf('demo%02d', $seq) . str_repeat('x', 20), $c, $branch, $type, $brand, $model, $symptoms, $status, $priority, $tech($techIdx), $findings,
                     $targetIdx >= 6 ? $diag : null, $targetIdx >= 6 ? $action : null, $approval, $targetIdx >= 6 ? 1 : null, $seq % 3 === 0 ? 'portal' : 'walk_in', $front['id'], $created, self::ago(0.05 * $seq),
                     $targetIdx >= 6 ? self::ago(max(0, $days - $stepDays * 6)) : null, $status === 'released' ? self::ago(max(0, $days - $stepDays * 7)) : null, $est]);
                $rep = $db->get('SELECT * FROM repairs WHERE id=?', [$rid]);
                $total = 0;
                foreach ($items as $it) $total += $it[2];
                $path = $status === 'cancelled' ? ['booked', 'received', 'diagnosing', 'awaiting_approval', 'cancelled'] : array_slice(self::FLOW, 0, $targetIdx + 1);
                foreach ($path as $i => $st) {
                    $at = self::ago(max(0, $days - $stepDays * $i));
                    $db->run('INSERT INTO repair_events (repair_id, from_status, to_status, actor_id, actor_label, note, visible_to_customer, created_at) VALUES (?,?,?,?,?,?,1,?)',
                        [$rid, $i ? $path[$i - 1] : null, $st, $front['id'], 'Demo data', $st === 'cancelled' ? 'Quote declined by customer' : null, $at]);
                    $db->run('INSERT INTO notifications (repair_id, channel, to_addr, message, status, created_at) VALUES (?,?,?,?,?,?)',
                        [$rid, 'sms', $phone, Workflow::customerMessage($rep, $st, ['total' => $total]), 'simulated', $at]);
                }
                foreach ($items as [$kind, $desc, $amt]) $db->run('INSERT INTO repair_items (repair_id, kind, description, amount) VALUES (?,?,?,?)', [$rid, $kind, $desc, $amt]);
                if ($status === 'released') $db->run('INSERT INTO payments (repair_id, amount, method, received_by, created_at) VALUES (?,?,?,?,?)', [$rid, $total, $seq % 2 ? 'cash' : 'gcash', $front['id'], self::ago(max(0, $days - $stepDays * 7))]);
                if (in_array($status, ['repairing', 'quality_check', 'completed'], true) && $total) $db->run('INSERT INTO payments (repair_id, amount, method, received_by, created_at) VALUES (?,?,?,?,?)', [$rid, round($total * 0.5), 'cash', $front['id'], self::ago($days / 2)]);
                $result = Rag::diagnose($index, ['symptoms' => $symptoms, 'deviceType' => $type, 'brand' => $brand, 'model' => $model]);
                $result['summary'] = Rag::composeSummary($result, $rep + ['deviceType' => $type]);
                $ai = $db->run('INSERT INTO ai_runs (repair_id, query_text, result_json, mode, created_by, created_at) VALUES (?,?,?,?,?,?)', [$rid, $result['query'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'local', null, $created]);
                if ($targetIdx >= 6 && $diag) {
                    $idx = -1;
                    foreach ($result['recommendations'] as $k => $x) if ($x['diagnosis'] === $diag) { $idx = $k; break; }
                    $db->run('UPDATE ai_runs SET feedback_diagnosis=?, feedback_rank=?, feedback_accepted=?, feedback_by=?, feedback_at=? WHERE id=?',
                        [$diag, $idx >= 0 ? $idx + 1 : null, $idx >= 0 ? 1 : 0, $tech($techIdx), self::ago(max(0, $days - $stepDays * 5)), $ai]);
                }
            }
        });
        self::seedSalesHistory($db, (int)$front['id'], $techs, $data);
    }

    /** About 25 months of finished, paid SYNTHETIC repairs for shop 1 so the Sales graphs show something. */
    private static function seedSalesHistory(Db $db, int $frontId, array $techs, array $data): void
    {
        $jobs = $data['jobs'];
        $names = $data['names'];
        $shop = (int)$db->val('SELECT shop_id FROM branches WHERE id=1');
        $branches = array_map(fn($b) => (int)$b['id'], $db->all('SELECT id FROM branches WHERE shop_id=? ORDER BY id', [$shop]));
        $seed = 20260101;
        $rnd = function () use (&$seed) { $seed = ($seed * 1664525 + 1013904223) % 4294967296; return $seed / 4294967296; };
        $pick = fn(array $a) => $a[(int)floor($rnd() * count($a))];
        $round50 = fn($n) => (int)(round($n / 50) * 50);
        $stamp = fn($t) => gmdate('Y-m-d H:i:s', (int)floor($t));
        $now = time();
        $n = 0;
        $db->tx(function () use ($db, $frontId, $techs, $jobs, $names, $shop, $branches, $rnd, $pick, $round50, $stamp, $now, &$n) {
            for ($d = 760; $d >= 0; $d--) {
                $t = $now - $d * 86400;
                $dow = (int)gmdate('w', $t);
                $base = 0.5 + 0.45 * ((760 - $d) / 760);
                $factor = $dow === 0 ? 0.5 : ($dow === 6 ? 1.3 : 1);
                $count = (int)floor($base * $factor * 1.3 + $rnd());
                for ($k = 0; $k < $count; $k++) {
                    $n++;
                    $dayStart = gmmktime(0, 0, 0, (int)gmdate('n', $t), (int)gmdate('j', $t), (int)gmdate('Y', $t));
                    $paidAt = $dayStart + (1 + $rnd() * 9) * 3600;
                    if ($paidAt > $now) $paidAt = $now - (10 + $rnd() * 200) * 60;
                    $days = 1 + (int)floor($rnd() * 3);
                    $created = $paidAt - $days * 86400;
                    [$type, $brand, $model, $desc, $pMin, $pMax, $diag] = $pick($jobs);
                    $part = $pMax ? $round50($pMin + $rnd() * ($pMax - $pMin)) : 0;
                    $labor = $round50(350 + $rnd() * 550);
                    $total = $part + $labor;
                    $branch = $pick($branches);
                    $tech = $techs[0]['id'];
                    foreach ($techs as $tt) if ((int)$tt['branch_id'] === $branch) { $tech = $tt['id']; break; }
                    $cid = $db->run('INSERT INTO customers (shop_id, name, phone, consent_at, created_at) VALUES (?,?,?,?,?)', [$shop, $pick($names) . ' (sample)', sprintf('0917555%04d', $n), $stamp($created), $stamp($created)]);
                    $code = sprintf('RS-H%s%s-%04d', gmdate('y', (int)$paidAt), gmdate('m', (int)$paidAt), $n);
                    $rid = $db->run('INSERT INTO repairs (shop_id, code, track_token, customer_id, branch_id, device_type, brand, model, reported_symptoms, status, priority, assigned_to, findings, verified_diagnosis, repair_action, approval_state, qc_passed, source, created_by, created_at, updated_at, completed_at, released_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$shop, $code, sprintf('hist%04d', $n) . str_repeat('x', 20), $cid, $branch, $type, $brand, $model, "Sample history: $desc", 'released', 'normal', $tech,
                         "Inspection confirms: $diag.", $diag, $desc, 'approved', 1, $n % 3 === 0 ? 'portal' : 'walk_in', $frontId, $stamp($created), $stamp($paidAt), $stamp($paidAt - 3600), $stamp($paidAt)]);
                    if ($part) $db->run('INSERT INTO repair_items (repair_id, kind, description, amount) VALUES (?,?,?,?)', [$rid, 'part', explode(' ', $desc)[0] . ' part', $part]);
                    $db->run('INSERT INTO repair_items (repair_id, kind, description, amount) VALUES (?,?,?,?)', [$rid, 'labor', $desc, $labor]);
                    $db->run('INSERT INTO repair_events (repair_id, from_status, to_status, actor_id, actor_label, note, visible_to_customer, created_at) VALUES (?,?,?,?,?,?,1,?)', [$rid, null, 'booked', $frontId, 'Sample history', null, $stamp($created)]);
                    $db->run('INSERT INTO repair_events (repair_id, from_status, to_status, actor_id, actor_label, note, visible_to_customer, created_at) VALUES (?,?,?,?,?,?,1,?)', [$rid, 'completed', 'released', $frontId, 'Sample history', null, $stamp($paidAt)]);
                    $method = function () use ($rnd) { $x = $rnd(); return $x < 0.55 ? 'cash' : ($x < 0.9 ? 'gcash' : ($x < 0.95 ? 'card' : 'bank')); };
                    if ($rnd() < 0.3 && $total >= 1000) {
                        $dep = $round50($total * 0.5);
                        $db->run('INSERT INTO payments (repair_id, amount, method, received_by, created_at) VALUES (?,?,?,?,?)', [$rid, $dep, $method(), $frontId, $stamp($created + 3600)]);
                        $db->run('INSERT INTO payments (repair_id, amount, method, received_by, created_at) VALUES (?,?,?,?,?)', [$rid, $total - $dep, $method(), $frontId, $stamp($paidAt)]);
                    } else {
                        $db->run('INSERT INTO payments (repair_id, amount, method, received_by, created_at) VALUES (?,?,?,?,?)', [$rid, $total, $method(), $frontId, $stamp($paidAt)]);
                    }
                }
            }
        });
    }
}
