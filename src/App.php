<?php
declare(strict_types=1);

namespace RepairSync;

use PDOException;

/**
 * The REST API: tiny router, security headers and all endpoints. handle() is a pure request->response function,
 * so the same code serves Apache/XAMPP (public/api/index.php) and the automated tests.
 */
final class App
{
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: https://tile.openstreetmap.org; connect-src 'self' https://router.project-osrm.org https://nominatim.openstreetmap.org; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
    ];
    /** Curated accents: light enough for a dark sidebar, darkenableable for buttons in both themes. */
    private const ACCENTS = ['#A7CF96', '#7FB2E5', '#B49BE8', '#E69BB8', '#E8A96A', '#E08080', '#6FC7B8', '#DCC46B'];
    private const MAX_LOGO_BYTES = 2_097_152; // 2 MB after decoding
    private const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    private const TIME_RE = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    private const DEVICES = ['smartphone', 'laptop', 'tablet', 'other'];

    private Signer $signer;
    private Limiter $limiter;
    private Llm $llm;
    private array $routes = [];
    private array $indexes = [];
    private string $basePath;

    public function __construct(private Db $db, private array $cfg, string $secret, private bool $demo = false, string $basePath = '/')
    {
        $this->signer = new Signer($secret);
        $this->limiter = new Limiter($db);
        $this->llm = new Llm($cfg);
        $this->basePath = $basePath;
        $this->registerRoutes();
    }

    // ------------------------------------------------------------------ request handling
    public function handle(string $method, string $path, array $query, string $rawBody, array $cookies, array $headers, string $ip): array
    {
        try {
            $path = '/' . trim($path, '/');
            $match = null;
            $pathMatched = false;
            foreach ($this->routes as $r) {
                if (preg_match($r['re'], $path, $m)) {
                    $pathMatched = true;
                    if ($r['method'] === $method) { $match = [$r, $m]; break; }
                }
            }
            if (!$match) throw $pathMatched ? new HttpError(405, 'Method not allowed.') : new HttpError(404, 'Unknown API endpoint.');
            [$route, $m] = $match;
            if ($method !== 'GET' && ($headers['x-requested-with'] ?? '') !== 'RepairSync') throw new HttpError(403, 'Missing request header.');

            $ctx = new Ctx();
            $ctx->ip = $ip;
            $ctx->query = $query;
            foreach ($route['keys'] as $i => $k) $ctx->params[$k] = rawurldecode($m[$i + 1]);
            $sess = $this->signer->verify($cookies['rs_session'] ?? null);
            $ctx->user = $sess ? $this->db->get('SELECT * FROM users WHERE id=? AND active=1', [$sess['uid']]) : null;
            if ($ctx->user && $ctx->user['shop_id'] && $ctx->user['role'] !== 'customer' && in_array($this->shopStatus((int)$ctx->user['shop_id']), ['suspended', 'rejected'], true)) $ctx->user = null;
            if ($method !== 'GET') {
                $limit = $route['maxBody'] ?? 100_000;
                if (strlen($rawBody) > $limit) throw new HttpError(413, 'Request too large.');
                if ($rawBody !== '') {
                    $v = json_decode($rawBody, true);
                    if (json_last_error() !== JSON_ERROR_NONE) throw V::bad('Invalid JSON.');
                    $ctx->body = is_array($v) ? $v : [];
                }
            }
            if (random_int(1, 100) === 1) $this->limiter->sweep();

            $out = ($route['handler'])($ctx);
            if (is_array($out) && isset($out['__raw'])) {
                $raw = $out['__raw'];
                return ['status' => 200, 'body' => $raw['body'], 'headers' => ['Content-Type' => $raw['type'], 'Content-Disposition' => 'attachment; filename="' . $raw['filename'] . '"', 'Cache-Control' => 'no-store'] + self::SECURITY_HEADERS];
            }
            return $this->json(200, $out, $ctx->setCookie ? ['Set-Cookie' => $ctx->setCookie] : []);
        } catch (HttpError $e) {
            return $this->json($e->status, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('[repairsync] ' . $method . ' ' . $path . ' ' . $e);
            return $this->json(500, ['error' => 'Something went wrong on the server.']);
        }
    }

    private function shopStatus(int $shopId): ?string { return $this->db->val('SELECT status FROM shops WHERE id=?', [$shopId]) ?: null; }

    private function json(int $status, mixed $data, array $extra = []): array
    {
        return [
            'status' => $status,
            'body' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE),
            'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'] + self::SECURITY_HEADERS + $extra,
        ];
    }

    private function route(string $method, string $pattern, callable $handler, array $opts = []): void
    {
        $keys = [];
        $re = '#^' . preg_replace_callback('/:([a-zA-Z]+)/', function ($m) use (&$keys) { $keys[] = $m[1]; return '([^/]+)'; }, $pattern) . '/?$#';
        $this->routes[] = ['method' => $method, 're' => $re, 'keys' => $keys, 'handler' => $handler] + $opts;
    }

    // ------------------------------------------------------------------ helpers
    private function now(): string { return $this->db->now(); }
    private function cookie(string $value, int $maxAge): string
    {
        return "rs_session=$value; HttpOnly; SameSite=Lax; Path=/; Max-Age=$maxAge" . (!empty($this->cfg['secure_cookie']) ? '; Secure' : '');
    }
    private function needUser(Ctx $c): array { if (!$c->user) throw new HttpError(401, 'Please sign in.'); return $c->user; }
    private function need(Ctx $c, string $perm): array
    {
        $u = $this->needUser($c);
        if (!Auth::can($u, $perm)) throw self::forbidden();
        return $u;
    }
    private static function forbidden(string $m = 'You do not have permission to do that.'): HttpError { return new HttpError(403, $m); }
    private static function notFound(string $m = 'Not found'): HttpError { return new HttpError(404, $m); }
    /** Same rounding as the Node version's Math.round(n * 100) / 100. */
    private static function r2(float $n): float { return floor($n * 100 + 0.5) / 100; }
    private function rate(string $key, int $windowSec, int $max, string $msg): void
    {
        if (!$this->limiter->hit($key, $windowSec, $max)) throw new HttpError(429, $msg);
    }

    private function indexFor(?int $shopId = null): CaseIndex
    {
        $k = $shopId ?? 0;
        return $this->indexes[$k] ??= Rag::loadIndex($this->db, $shopId);
    }
    private function reindex(): void { $this->indexes = []; }

    private function audit(Ctx $c, string $action, ?string $entity, mixed $entityId, ?string $detail = null): void
    {
        $shopId = $c->shopId ?? ($c->user['shop_id'] ?? null);
        if ($shopId === null && $entity === 'repair' && $entityId !== null) {
            $shopId = $this->db->val('SELECT shop_id FROM repairs WHERE id=?', [$entityId]);
        }
        $this->db->run('INSERT INTO audit_logs (shop_id, actor_id, actor_label, action, entity, entity_id, detail, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$shopId, $c->user['id'] ?? null, $c->user ? "{$c->user['name']} ({$c->user['role']})" : 'public', $action, $entity, $entityId !== null ? (string)$entityId : null, $detail, $c->ip, $this->now()]);
    }

    /** Absolute root for per-shop logo files; overridable for tests and deployments. */
    private function uploadRoot(): string
    {
        return rtrim((string)($this->cfg['upload_dir'] ?? (dirname(__DIR__) . '/public/uploads')), '/\\');
    }

    /**
     * Store a cropped square logo sent as a PNG data URL. The file lives under the
     * shop's own id, is never user-named, and the old file is removed after commit.
     */
    private function saveShopLogo(array $u, mixed $dataUrl): string
    {
        if (!is_string($dataUrl) || !preg_match('/^data:image\/(png|jpeg|webp);base64,([a-zA-Z0-9+\/=\s]+)$/', $dataUrl, $m)) {
            throw V::bad('Please choose a PNG, JPG, SVG or WEBP image.');
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_LOGO_BYTES) {
            throw V::bad('That image is too large. The maximum logo size is 2 MB.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext) throw V::bad('That image format is not supported.');

        $shopId = (int)$u['shop_id'];
        $dir = $this->uploadRoot() . '/shops/' . $shopId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new HttpError(500, 'Could not store the logo. Please try again.');

        $old = $this->db->val('SELECT logo_path FROM shops WHERE id=?', [$shopId]);
        $path = 'shops/' . $shopId . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . basename($path), $bytes) === false) throw new HttpError(500, 'Could not store the logo. Please try again.');

        try {
            $this->db->run('UPDATE shops SET logo_path=? WHERE id=?', [$path, $shopId]);
        } catch (\Throwable $e) {
            @unlink($dir . '/' . basename($path));
            throw $e;
        }
        if (is_string($old) && $old !== '' && $old !== $path) {
            $oldFile = $this->uploadRoot() . '/' . $old;
            if (is_file($oldFile)) @unlink($oldFile);
        }
        return $path;
    }

    /** Accept only a clean {mon:[["08:00","21:00"]], ...} object; blank input means "hours not set". */
    private function normalizeOpeningHours(mixed $raw): ?string
    {
        if ($raw === null || $raw === '' || $raw === []) return null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) throw V::bad('Opening hours must be a weekly schedule.');
            $raw = $decoded;
        }
        if (!is_array($raw)) throw V::bad('Opening hours must be a weekly schedule.');
        $clean = [];
        foreach (self::WEEKDAYS as $day) {
            $ranges = $raw[$day] ?? [];
            if ($ranges === null || $ranges === '') $ranges = [];
            if (!is_array($ranges)) throw V::bad('Opening hours for each day must be a list of time ranges.');
            $dayRanges = [];
            foreach ($ranges as $range) {
                if (!is_array($range) || count($range) !== 2) throw V::bad('Each opening-hours range needs a start and end time.');
                [$from, $to] = array_values($range);
                if (!is_string($from) || !is_string($to) || !preg_match(self::TIME_RE, $from) || !preg_match(self::TIME_RE, $to) || strcmp($from, $to) >= 0) {
                    throw V::bad('Use 24-hour times such as 08:00 and 21:00, with the end after the start.');
                }
                $dayRanges[] = [$from, $to];
            }
            $clean[$day] = $dayRanges;
        }
        return json_encode($clean);
    }

    private function decodedOpeningHours(mixed $raw): ?array
    {
        if (is_array($raw)) return $raw;
        if (empty($raw)) return null;
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** One shape for every public shop response used by the directory, shop page and finder map. */
    private function publicShop(array $s): array
    {
        $hours = $this->decodedOpeningHours($s['opening_hours'] ?? null);
        return [
            'id' => (int)$s['id'], 'name' => $s['name'], 'slug' => $s['slug'], 'city' => $s['city'],
            'address' => $s['address'] ?? null,
            'lat' => isset($s['lat']) && $s['lat'] !== '' ? (float)$s['lat'] : null,
            'lng' => isset($s['lng']) && $s['lng'] !== '' ? (float)$s['lng'] : null,
            'logo_path' => $s['logo_path'] ?? null,
            'logo_url' => !empty($s['logo_path']) ? '/uploads/' . $s['logo_path'] : null,
            'opening_hours' => $hours,
        ];
    }

    private function itemsTotal(int $id): float { return (float)$this->db->val('SELECT COALESCE(SUM(amount),0) FROM repair_items WHERE repair_id=?', [$id]); }
    private function paidTotal(int $id): float { return (float)$this->db->val('SELECT COALESCE(SUM(amount),0) FROM payments WHERE repair_id=?', [$id]); }

    private function nextCode(): string
    {
        $prefix = 'RS-' . gmdate('y') . gmdate('m') . '-';
        $last = $this->db->get('SELECT code FROM repairs WHERE code LIKE ? ORDER BY code DESC LIMIT 1', [$prefix . '%']);
        $n = $last ? (int)substr($last['code'], strlen($prefix)) + 1 : 1;
        return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    private function notify(array $repair, ?array $customer, string $to, array $extra = []): void
    {
        $msg = Workflow::customerMessage($repair, $to, $extra);
        $addr = ($customer['phone'] ?? null) ?: ($customer['email'] ?? null);
        if (!$addr) return;
        $this->db->run('INSERT INTO notifications (repair_id, channel, to_addr, message, status, created_at) VALUES (?,?,?,?,?,?)',
            [$repair['id'], !empty($customer['phone']) ? 'sms' : 'email', $addr, $msg, 'simulated', $this->now()]);
    }

    private function addEvent(int $repairId, ?string $from, string $to, Ctx $c, ?string $note, int $visible = 1): void
    {
        $this->db->run('INSERT INTO repair_events (repair_id, from_status, to_status, actor_id, actor_label, note, visible_to_customer, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$repairId, $from, $to, $c->user['id'] ?? null, $c->user ? "{$c->user['name']} ({$c->user['role']})" : 'Customer (tracking link)', $note, $visible, $this->now()]);
    }

    private static function json_(array $result, string $summary): string
    {
        return json_encode(['summary' => $summary] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function createRepair(Ctx $c, array $d): array
    {
        $d += ['userId' => null, 'email' => null, 'phone' => null, 'brand' => null, 'model' => null, 'serial' => null, 'accessories' => null, 'conditionNotes' => null, 'priority' => 'normal', 'consent' => false];
        $branch = $this->db->get('SELECT * FROM branches WHERE id=?', [$d['branchId']]);
        $shopId = (int)$branch['shop_id'];
        $c->shopId = $shopId;
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->db->tx(function () use ($c, $d, $shopId) {
                    $customer = null;
                    // Customer records belong to one shop; the same person at another shop gets a separate record.
                    if ($d['userId']) $customer = $this->db->get('SELECT * FROM customers WHERE user_id=? AND shop_id=? AND anonymized_at IS NULL', [$d['userId'], $shopId]);
                    // Walk-in / anonymous bookings reuse a record of the same shop by e-mail or phone, but never one that belongs to a
                    // signed-in account (e-mail and phone are not verified, so matching them must not expose someone else's repairs).
                    if (!$customer && !$d['userId'] && $d['email']) $customer = $this->db->get('SELECT * FROM customers WHERE lower(email)=? AND shop_id=? AND user_id IS NULL AND anonymized_at IS NULL', [$d['email'], $shopId]);
                    if (!$customer && !$d['userId'] && $d['phone']) $customer = $this->db->get('SELECT * FROM customers WHERE phone=? AND shop_id=? AND user_id IS NULL AND anonymized_at IS NULL', [$d['phone'], $shopId]);
                    if (!$customer) {
                        $cid = $this->db->run('INSERT INTO customers (shop_id, user_id, name, phone, email, consent_at, created_at) VALUES (?,?,?,?,?,?,?)',
                            [$shopId, $d['userId'], $d['name'], $d['phone'], $d['email'], $d['consent'] ? $this->now() : null, $this->now()]);
                        $customer = $this->db->get('SELECT * FROM customers WHERE id=?', [$cid]);
                    } elseif ($d['consent'] && empty($customer['consent_at'])) {
                        $this->db->run('UPDATE customers SET consent_at=? WHERE id=?', [$this->now(), $customer['id']]);
                    }
                    $code = $this->nextCode();
                    $token = bin2hex(random_bytes(12));
                    $now = $this->now();
                    $id = $this->db->run('INSERT INTO repairs (shop_id, code, track_token, customer_id, branch_id, device_type, brand, model, serial_no, accessories, reported_symptoms, condition_notes, priority, source, created_by, status, created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$shopId, $code, $token, $customer['id'], $d['branchId'], $d['deviceType'], $d['brand'], $d['model'], $d['serial'], $d['accessories'], $d['symptoms'], $d['conditionNotes'], $d['priority'] ?: 'normal', $d['source'], $c->user['id'] ?? null, $d['initialStatus'] ?? 'booked', $now, $now]);
                    $repair = $this->db->get('SELECT * FROM repairs WHERE id=?', [$id]);
                    $this->addEvent($id, null, $repair['status'], $c, $d['source'] === 'portal' ? 'Booked online by customer' : 'Walk-in intake by staff');
                    $this->notify($repair, $customer, $repair['status']);
                    // Automatic preliminary AI run at intake so the technician has suggestions waiting.
                    $result = Rag::diagnose($this->indexFor($shopId), ['symptoms' => $repair['reported_symptoms'], 'deviceType' => $repair['device_type'], 'brand' => $repair['brand'], 'model' => $repair['model']]);
                    $this->db->run('INSERT INTO ai_runs (repair_id, query_text, result_json, mode, created_by, created_at) VALUES (?,?,?,?,?,?)',
                        [$id, $result['query'], self::json_($result, Rag::composeSummary($result, $repair + ['deviceType' => $repair['device_type']])), 'local', $c->user['id'] ?? null, $now]);
                    $this->audit($c, 'repair.create', 'repair', $id, "$code via {$d['source']}");
                    return ['repair' => $repair, 'customer' => $customer, 'result' => $result];
                });
            } catch (PDOException $e) {
                // Two bookings at the same moment can pick the same code; the UNIQUE index catches it, so just try again.
                if ($attempt < 4 && str_contains($e->getMessage(), 'code')) continue;
                throw $e;
            }
        }
    }

    /** Staff only ever load repairs of their own shop; a repair of another shop looks like "not found". */
    private function loadRepair(int $id, ?array $user = null): ?array
    {
        $r = $this->db->get('SELECT r.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email, c.user_id AS customer_user_id,
            b.name AS branch_name, t.name AS tech_name FROM repairs r JOIN customers c ON c.id=r.customer_id JOIN branches b ON b.id=r.branch_id
            LEFT JOIN users t ON t.id=r.assigned_to WHERE r.id=?', [$id]);
        if ($r && $user && $user['role'] !== 'customer' && (int)$r['shop_id'] !== (int)$user['shop_id']) return null;
        return $r ?: null;
    }

    private function canSee(?array $user, array $r): bool
    {
        if (!$user) return false;
        if ($user['role'] === 'customer') return $r['customer_user_id'] !== null && (int)$r['customer_user_id'] === (int)$user['id'];
        return (int)$r['shop_id'] === (int)$user['shop_id'] && Auth::can($user, 'repair.view_all');
    }

    private function shape(array $user, array $r, bool $detail = false): array
    {
        $pii = Auth::can($user, 'repair.view_pii') || $user['role'] === 'customer';
        $total = $this->itemsTotal((int)$r['id']);
        $paid = $this->paidTotal((int)$r['id']);
        $out = [
            'id' => (int)$r['id'], 'code' => $r['code'], 'status' => $r['status'], 'status_label' => Workflow::STATUS_LABEL[$r['status']] ?? $r['status'], 'priority' => $r['priority'],
            'device_type' => $r['device_type'], 'brand' => $r['brand'], 'model' => $r['model'],
            'serial_no' => $pii ? $r['serial_no'] : ($r['serial_no'] ? '••••' . mb_substr((string)$r['serial_no'], -4) : null),
            'accessories' => $r['accessories'], 'reported_symptoms' => $r['reported_symptoms'], 'condition_notes' => $r['condition_notes'],
            'branch_id' => (int)$r['branch_id'], 'branch_name' => $r['branch_name'], 'assigned_to' => $r['assigned_to'] !== null ? (int)$r['assigned_to'] : null, 'tech_name' => $r['tech_name'],
            'customer_name' => $pii ? $r['customer_name'] : explode(' ', (string)($r['customer_name'] ?? ''))[0],
        ];
        if ($pii) { $out['customer_phone'] = $r['customer_phone']; $out['customer_email'] = $r['customer_email']; }
        $out += [
            'findings' => $r['findings'], 'verified_diagnosis' => $r['verified_diagnosis'], 'repair_action' => $r['repair_action'],
            'est_ready_date' => $r['est_ready_date'], 'approval_state' => $r['approval_state'], 'qc_passed' => $r['qc_passed'] !== null ? (int)$r['qc_passed'] : null,
            'source' => $r['source'], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'], 'completed_at' => $r['completed_at'], 'released_at' => $r['released_at'],
            'total' => $total, 'paid' => $paid, 'balance' => self::r2($total - $paid),
            'allowed_next' => array_values(array_keys(array_filter(Workflow::TRANSITIONS[$r['status']] ?? [], fn($perm) => Auth::can($user, $perm)))),
        ];
        if (!$detail) return $out;
        $out['events'] = $this->db->all('SELECT * FROM repair_events WHERE repair_id=? ORDER BY id', [$r['id']]);
        $out['items'] = $this->db->all('SELECT * FROM repair_items WHERE repair_id=? ORDER BY id', [$r['id']]);
        $out['payments'] = $this->db->all('SELECT p.*, u.name AS received_by_name FROM payments p LEFT JOIN users u ON u.id=p.received_by WHERE repair_id=? ORDER BY p.id', [$r['id']]);
        $out['notifications'] = $this->db->all('SELECT * FROM notifications WHERE repair_id=? ORDER BY id DESC', [$r['id']]);
        if (($user['role'] !== 'customer' && Auth::can($user, 'ai.run')) || Auth::can($user, 'report.view')) {
            $out['ai_runs'] = array_map(function ($a) {
                $a['result'] = json_decode($a['result_json'], true);
                unset($a['result_json']);
                return $a;
            }, $this->db->all('SELECT * FROM ai_runs WHERE repair_id=? ORDER BY id DESC', [$r['id']]));
        }
        if ($user['role'] === 'customer') {
            $out['events'] = array_values(array_filter($out['events'], fn($e) => (int)$e['visible_to_customer'] === 1));
            $out['notifications'] = array_map(fn($n) => ['id' => $n['id'], 'message' => $n['message'], 'created_at' => $n['created_at'], 'channel' => $n['channel']], $out['notifications']);
        }
        return $out;
    }

    private function assertWorkAccess(array $user, array $r): void
    {
        // Technicians may only work on repairs assigned to them (or unassigned ones they claim).
        if ($user['role'] === 'technician' && $r['assigned_to'] && (int)$r['assigned_to'] !== (int)$user['id']) throw self::forbidden('This repair is assigned to another technician.');
    }

    private function applyTransition(Ctx $c, array $r, string $to, ?string $note = null, bool $override = false, bool $byCustomer = false): array
    {
        $from = $r['status'];
        if (!isset(Workflow::TRANSITIONS[$from]) || !array_key_exists($to, Workflow::TRANSITIONS[$from])) {
            throw V::bad(sprintf('Cannot move from "%s" to "%s".', Workflow::STATUS_LABEL[$from] ?? $from, Workflow::STATUS_LABEL[$to] ?? $to));
        }
        if (!$byCustomer) {
            if (!Auth::can($c->user, Workflow::TRANSITIONS[$from][$to])) throw self::forbidden();
            if (in_array($to, ['diagnosing', 'repairing', 'quality_check', 'completed'], true) || $from === 'diagnosing') $this->assertWorkAccess($c->user, $r);
        }
        if ($override && ($c->user['role'] ?? null) !== 'admin') throw self::forbidden('Only an administrator can override the payment check.');
        if ($to === 'cancelled' && !($note !== null && trim($note) !== '')) throw V::bad('Please give a reason for cancelling.');
        if ($from === 'awaiting_approval' && $to === 'repairing' && !$byCustomer && !($note !== null && trim($note) !== '')) throw V::bad('Record how the customer approved (e.g. "approved by phone").');
        $total = $this->itemsTotal((int)$r['id']);
        $paid = $this->paidTotal((int)$r['id']);
        $g = Workflow::guard($r, $to, ['itemsTotal' => $total, 'paid' => $paid, 'override' => $override]);
        if ($g) throw V::bad($g);

        return $this->db->tx(function () use ($c, $r, $to, $from, $note, $total, $paid) {
            $now = $this->now();
            $sets = ['status=?', 'updated_at=?'];
            $vals = [$to, $now];
            if ($to === 'diagnosing' && !$r['assigned_to'] && ($c->user['role'] ?? null) === 'technician') { $sets[] = 'assigned_to=?'; $vals[] = $c->user['id']; }
            if ($to === 'awaiting_approval') $sets[] = "approval_state='pending'";
            if ($from === 'awaiting_approval' && $to === 'repairing') $sets[] = "approval_state='approved'";
            if ($from === 'awaiting_approval' && $to === 'cancelled') $sets[] = "approval_state='declined'";
            if ($from === 'awaiting_approval' && $to === 'diagnosing') $sets[] = "approval_state='none'";
            if ($to === 'completed') { $sets[] = 'completed_at=?'; $vals[] = $now; $sets[] = 'qc_passed=1'; }
            if ($from === 'quality_check' && $to === 'repairing') $sets[] = 'qc_passed=0';
            if ($to === 'released') { $sets[] = 'released_at=?'; $vals[] = $now; }
            $this->db->run('UPDATE repairs SET ' . implode(', ', $sets) . ' WHERE id=?', [...$vals, $r['id']]);
            $this->addEvent((int)$r['id'], $from, $to, $c, $note, 1);
            $fresh = $this->db->get('SELECT * FROM repairs WHERE id=?', [$r['id']]);
            $customer = $this->db->get('SELECT * FROM customers WHERE id=?', [$r['customer_id']]);
            if ($to !== 'diagnosing' || $from === 'received') $this->notify($fresh, $customer, $to, ['total' => $total, 'balance' => $total - $paid, 'note' => $note]);
            // Knowledge loop: a completed repair with a verified diagnosis becomes a pending case for admin approval.
            if ($to === 'completed' && $fresh['verified_diagnosis'] && !$this->db->get('SELECT id FROM cases WHERE repair_id=?', [$r['id']])) {
                $sym = mb_substr($fresh['reported_symptoms'] . ($fresh['findings'] ? ' ' . $fresh['findings'] : ''), 0, 900);
                $this->db->run("INSERT INTO cases (shop_id, device_type, brand, model_hint, symptoms, diagnosis, root_cause, checks, repair_action, parts, cost_min, cost_max, hours, status, source, repair_id, created_by, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'pending','shop',?,?,?)",
                    [$fresh['shop_id'], $fresh['device_type'], $fresh['brand'], $fresh['model'], $sym, $fresh['verified_diagnosis'], null, null, $fresh['repair_action'], null, $total, $total, null, $r['id'], $c->user['id'] ?? null, $now]);
            }
            $this->audit($c, 'repair.transition', 'repair', $r['id'], "$from -> $to" . ($note ? " | $note" : ''));
            return $this->db->get('SELECT * FROM repairs WHERE id=?', [$r['id']]);
        });
    }

    private function publicUser(array $u): array
    {
        return [
            'id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'], 'role' => $u['role'],
            'branch_id' => $u['branch_id'] !== null ? (int)$u['branch_id'] : null,
            'shop' => $u['shop_id'] ? (function () use ($u) {
                $shop = $this->db->get('SELECT id, name, slug, status, review_note, logo_path, accent_color, address, lat, lng, opening_hours FROM shops WHERE id=?', [$u['shop_id']]);
                if ($shop) $shop['opening_hours'] = $this->decodedOpeningHours($shop['opening_hours']);
                return $shop;
            })() : null,
        ];
    }

    private function withRepair(Ctx $c, string $perm): array
    {
        $u = $this->need($c, $perm);
        $r = $this->loadRepair(V::intId($c->params['id']), $u);
        if (!$r) throw self::notFound();
        return [$u, $r];
    }

    // ------------------------------------------------------------------ routes
    private function registerRoutes(): void
    {
        $this->routesCore();
        $this->routesPublic();
        $this->routesPlatform();
        $this->routesRepairs();
        $this->routesKnowledge();
        $this->routesAdmin();
        $this->routesReports();
    }

    private function routesCore(): void
    {
        $db = $this->db;
        $this->route('GET', '/api/health', fn(Ctx $c) => [
            'ok' => true, 'demo' => $this->demo, 'ai_mode' => $this->llm->enabled() ? 'llm' : 'local',
            'cases_indexed' => $this->indexFor(null)->N, 'shops' => (int)$db->val('SELECT COUNT(*) FROM shops'), 'time' => $this->now(),
            'database' => $db->driver,
        ]);
        // Staff get their own shop's branches; the public must say which shop (?shop=slug).
        $this->route('GET', '/api/branches', function (Ctx $c) use ($db) {
            if ($c->user && $c->user['role'] !== 'customer') return $db->all('SELECT id, name, address, phone, hours FROM branches WHERE shop_id=? ORDER BY id', [$c->user['shop_id']]);
            $shop = $db->get("SELECT id FROM shops WHERE slug=? AND status='active'", [(string)($c->query['shop'] ?? '')]);
            return $shop ? $db->all('SELECT id, name, address, phone, hours FROM branches WHERE shop_id=? ORDER BY id', [$shop['id']]) : [];
        });

        // --- auth ---
        $this->route('POST', '/api/auth/login', function (Ctx $c) use ($db) {
            $e = V::email($c->body['email'] ?? null, true);
            $pw = V::str($c->body['password'] ?? null, 'Password', 0, 200, true);
            $this->rate("login|{$c->ip}|$e", 15 * 60, 10, 'Too many login attempts. Please wait a few minutes.');
            $u = $db->get('SELECT * FROM users WHERE email=?', [$e]);
            if (!$u || !(int)$u['active'] || !Auth::verifyPassword($pw, $u['password_hash'])) {
                $this->audit($c->withUser(null), 'auth.login_failed', 'user', null, $e);
                throw new HttpError(401, 'Incorrect email or password.');
            }
            if ($u['shop_id'] && $u['role'] !== 'customer') {
                $sh = $db->get('SELECT status, review_note FROM shops WHERE id=?', [$u['shop_id']]);
                if ($sh && $sh['status'] === 'suspended') throw new HttpError(403, 'This shop account is suspended.' . ($sh['review_note'] ? ' Reason: ' . $sh['review_note'] : '') . ' Please contact the RepairSync AI administrator.');
                if ($sh && $sh['status'] === 'rejected') throw new HttpError(403, 'This shop registration was not approved.' . ($sh['review_note'] ? ' Reason: ' . $sh['review_note'] : '') . ' Please contact the RepairSync AI administrator.');
            }
            $c->setCookie = $this->cookie($this->signer->issue((int)$u['id']), 8 * 3600);
            $this->audit($c->withUser($u), 'auth.login', 'user', $u['id']);
            return $this->publicUser($u);
        });
        $this->route('POST', '/api/auth/logout', function (Ctx $c) {
            $c->setCookie = $this->cookie('', 0);
            if ($c->user) $this->audit($c, 'auth.logout', 'user', $c->user['id']);
            return ['ok' => true];
        });
        $this->route('GET', '/api/auth/me', fn(Ctx $c) => ['user' => $c->user ? $this->publicUser($c->user) : null]);
        $this->route('POST', '/api/auth/register', function (Ctx $c) use ($db) {
            $this->rate("public|{$c->ip}", 600, 30, 'Too many requests.');
            $b = $c->body;
            $name = V::str($b['name'] ?? null, 'Name', 2, 80, true);
            $em = V::email($b['email'] ?? null, true);
            $ph = V::phone($b['phone'] ?? null);
            if ($err = Auth::passwordProblem($b['password'] ?? null)) throw V::bad($err);
            if (($b['consent'] ?? null) !== true) throw V::bad('You must accept the Data Privacy notice to register.');
            if ($db->get('SELECT id FROM users WHERE email=?', [$em])) throw V::bad('An account with this email already exists.');
            $uid = $db->run("INSERT INTO users (name,email,phone,role,password_hash,created_at) VALUES (?,?,?,'customer',?,?)", [$name, $em, $ph, Auth::hashPassword($b['password']), $this->now()]);
            $u = $db->get('SELECT * FROM users WHERE id=?', [$uid]);
            $c->setCookie = $this->cookie($this->signer->issue((int)$u['id']), 8 * 3600);
            $this->audit($c->withUser($u), 'auth.register', 'user', $u['id']);
            return $this->publicUser($u);
        });
        $this->route('POST', '/api/auth/password', function (Ctx $c) use ($db) {
            $u = $this->needUser($c);
            $full = $db->get('SELECT * FROM users WHERE id=?', [$u['id']]);
            if (!Auth::verifyPassword((string)($c->body['current'] ?? ''), $full['password_hash'])) throw V::bad('Current password is incorrect.');
            if ($err = Auth::passwordProblem($c->body['password'] ?? null)) throw V::bad($err);
            $db->run('UPDATE users SET password_hash=? WHERE id=?', [Auth::hashPassword($c->body['password']), $u['id']]);
            $this->audit($c, 'auth.password_change', 'user', $u['id']);
            return ['ok' => true];
        });
    }

    private function trackLookup(mixed $code, mixed $token, mixed $phone4): array
    {
        $row = $this->db->get('SELECT id FROM repairs WHERE code=?', [strtoupper((string)($code ?? ''))]);
        $r = $row ? $this->loadRepair((int)$row['id']) : null;
        if (!$r) throw self::notFound('No repair found with those details.');
        $okToken = $token && is_string($token) && hash_equals((string)$r['track_token'], $token);
        $digits = fn($s) => preg_replace('/\D/', '', (string)$s);
        $okPhone = $phone4 && $r['customer_phone'] && substr($digits($r['customer_phone']), -4) === $digits($phone4);
        if (!$okToken && !$okPhone) throw self::notFound('No repair found with those details.');
        return $r;
    }

    private function publicTrackView(array $r): array
    {
        $db = $this->db;
        $total = $this->itemsTotal((int)$r['id']);
        $paid = $this->paidTotal((int)$r['id']);
        $shown = in_array($r['status'], ['awaiting_approval', 'repairing', 'quality_check', 'completed', 'released'], true);
        $dev = implode(' ', array_filter([$r['brand'], $r['model']])) ?: $r['device_type'];
        return [
            'code' => $r['code'], 'status' => $r['status'], 'status_label' => Workflow::STATUS_LABEL[$r['status']], 'device' => $dev,
            'customer_first_name' => explode(' ', (string)($r['customer_name'] ?? ''))[0], 'branch_name' => $r['branch_name'], 'est_ready_date' => $r['est_ready_date'], 'approval_state' => $r['approval_state'],
            'findings' => $shown ? $r['findings'] : null,
            'quote' => $shown ? $db->all('SELECT kind, description, amount FROM repair_items WHERE repair_id=? ORDER BY id', [$r['id']]) : [],
            'total' => $total, 'balance' => self::r2($total - $paid),
            'timeline' => array_map(fn($e) => [
                'to_status' => $e['to_status'], 'note' => $e['to_status'] === 'cancelled' ? $e['note'] : null, 'created_at' => $e['created_at'],
                'label' => Workflow::STATUS_LABEL[$e['to_status']] ?? $e['to_status'],
            ], $db->all('SELECT to_status, note, created_at FROM repair_events WHERE repair_id=? AND visible_to_customer=1 ORDER BY id', [$r['id']])),
            'messages' => $db->all('SELECT message, created_at FROM notifications WHERE repair_id=? ORDER BY id DESC LIMIT 10', [$r['id']]),
            'steps' => array_values(array_map(fn($s) => ['key' => $s, 'label' => Workflow::STATUS_LABEL[$s]], array_filter(Workflow::STATUSES, fn($s) => $s !== 'cancelled'))),
        ];
    }

    private static function slugify(string $name): string
    {
        $t = mb_strtolower($name, 'UTF-8');
        if (class_exists('Normalizer')) $t = \Normalizer::normalize($t, \Normalizer::FORM_KD) ?: $t;
        $t = trim(preg_replace('/[^a-z0-9]+/', '-', $t), '-');
        $t = rtrim(substr($t, 0, 40), '-');
        return $t !== '' ? $t : 'shop';
    }

    private function uniqueSlug(string $name): string
    {
        $base = self::slugify($name);
        $slug = $base;
        for ($i = 2; $this->db->get('SELECT id FROM shops WHERE slug=?', [$slug]); $i++) $slug = "$base-$i";
        return $slug;
    }

    private function routesPublic(): void
    {
        $db = $this->db;
        $this->route('POST', '/api/public/bookings', function (Ctx $c) use ($db) {
            $this->rate("public|{$c->ip}", 600, 30, 'Too many requests. Please try again later.');
            $b = $c->body;
            if (($b['consent'] ?? null) !== true) throw V::bad('Please accept the Data Privacy notice to continue.');
            $lc = ($c->user['role'] ?? null) === 'customer' ? $c->user : null;
            $data = [
                'name' => $lc ? $lc['name'] : V::str($b['name'] ?? null, 'Name', 2, 80, true),
                'email' => $lc ? $lc['email'] : V::email($b['email'] ?? null),
                'phone' => $lc ? ($lc['phone'] ?: V::phone($b['phone'] ?? null, true)) : V::phone($b['phone'] ?? null, true),
                'userId' => $lc ? (int)$lc['id'] : null,
                'consent' => true,
                'branchId' => V::intId($b['branchId'] ?? null),
                'deviceType' => V::oneOf($b['deviceType'] ?? null, 'Device type', self::DEVICES),
                'brand' => V::str($b['brand'] ?? null, 'Brand', 0, 40), 'model' => V::str($b['model'] ?? null, 'Model', 0, 60),
                'symptoms' => V::str($b['symptoms'] ?? null, 'Problem description', 8, 1000, true),
                'accessories' => V::str($b['accessories'] ?? null, 'Accessories', 0, 200),
                'source' => 'portal', 'initialStatus' => 'booked',
            ];
            if (!$db->get("SELECT b.id FROM branches b JOIN shops s ON s.id=b.shop_id WHERE b.id=? AND s.status='active'", [$data['branchId']])) throw V::bad('Please choose a shop and branch.');
            ['repair' => $repair, 'result' => $result] = $this->createRepair($c, $data);
            return ['code' => $repair['code'], 'token' => $repair['track_token'], 'track_path' => "{$this->basePath}#/track?code={$repair['code']}&token={$repair['track_token']}", 'hint' => Rag::customerHint($result)];
        });
        $this->route('GET', '/api/public/track', function (Ctx $c) {
            $this->rate("public|{$c->ip}|track", 600, 30, 'Too many requests.');
            return $this->publicTrackView($this->trackLookup($c->query['code'] ?? null, $c->query['token'] ?? null, $c->query['phone4'] ?? null));
        });
        $this->route('POST', '/api/public/track/approve', function (Ctx $c) {
            $this->rate("public|{$c->ip}|approve", 600, 30, 'Too many requests.');
            $r = $this->trackLookup($c->body['code'] ?? null, $c->body['token'] ?? null, $c->body['phone4'] ?? null);
            if ($r['status'] !== 'awaiting_approval') throw V::bad('There is no quote waiting for approval.');
            $anon = $c->withUser(null);
            if (($c->body['approve'] ?? null) === true) $this->applyTransition($anon, $r, 'repairing', 'Quote approved by customer via tracking page', false, true);
            else $this->applyTransition($anon, $r, 'cancelled', 'Quote declined by customer via tracking page', false, true);
            return $this->publicTrackView($this->loadRepair((int)$r['id']));
        });

        // --- shop settings: shop details and branches (administrator only) ---
        $this->route('GET', '/api/shop', function (Ctx $c) use ($db) {
            $u = $this->needUser($c);
            if (!$u['shop_id'] || $u['role'] === 'customer') throw self::forbidden();
            $shop = $db->get('SELECT id, name, slug, city, phone, email, status, logo_path, accent_color, address, lat, lng, opening_hours FROM shops WHERE id=?', [$u['shop_id']]);
            $shop['opening_hours'] = $this->decodedOpeningHours($shop['opening_hours']);
            return $shop + ['branches' => $db->all('SELECT id, name, address, phone, hours FROM branches WHERE shop_id=? ORDER BY id', [$u['shop_id']])];
        });
        $this->route('PATCH', '/api/shop', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'shop.manage');
            $b = $c->body;
            $name = V::str($b['name'] ?? null, 'Shop name', 2, 80, true);
            $city = V::str($b['city'] ?? null, 'City / town', 0, 80);
            $phone = V::str($b['phone'] ?? null, 'Contact number', 0, 40);
            $db->run('UPDATE shops SET name=?, city=?, phone=? WHERE id=?', [$name, $city, $phone, $u['shop_id']]);
            $this->audit($c, 'shop.update', 'shop', $u['shop_id'], $name);
            return ['ok' => true];
        });

        // --- per-shop branding. Admin/Owner only; always scoped to the session's shop_id. ---
        $this->route('PATCH', '/api/shop/name', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'shop.branding');
            if (!$u['shop_id']) throw self::forbidden();
            $name = V::str($c->body['name'] ?? null, 'Shop name', 2, 80, true);
            $db->run('UPDATE shops SET name=? WHERE id=?', [$name, $u['shop_id']]);
            $this->audit($c, 'shop.branding.name', 'shop', $u['shop_id'], $name);
            return ['ok' => true, 'name' => $name];
        });
        $this->route('PATCH', '/api/shop/theme', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'shop.branding');
            if (!$u['shop_id']) throw self::forbidden();
            $accent = V::oneOf($c->body['accent'] ?? null, 'Accent color', self::ACCENTS);
            $db->run('UPDATE shops SET accent_color=? WHERE id=?', [$accent, $u['shop_id']]);
            $this->audit($c, 'shop.branding.theme', 'shop', $u['shop_id'], $accent);
            return ['ok' => true, 'accent' => $accent];
        });
        $this->route('PUT', '/api/shop/logo', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'shop.branding');
            if (!$u['shop_id']) throw self::forbidden();
            $path = $this->saveShopLogo($u, $c->body['image'] ?? null);
            $this->audit($c, 'shop.branding.logo', 'shop', $u['shop_id'], $path);
            return ['ok' => true, 'logo_path' => $path, 'logo_url' => '/uploads/' . $path];
        }, ['maxBody' => 3_000_000]);

        // Exact public-map pin and machine-readable hours. Admin/Owner only, always the session's shop.
        $this->route('PATCH', '/api/shop/location', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'shop.branding');
            if (!$u['shop_id']) throw self::forbidden();
            $address = V::str($c->body['address'] ?? null, 'Address', 2, 255, true);
            $lat = V::num($c->body['lat'] ?? null);
            $lng = V::num($c->body['lng'] ?? null);
            if ($lat === null || $lat < -90 || $lat > 90) throw V::bad('Latitude must be between -90 and 90.');
            if ($lng === null || $lng < -180 || $lng > 180) throw V::bad('Longitude must be between -180 and 180.');
            $hours = $this->normalizeOpeningHours($c->body['openingHours'] ?? null);
            $db->run('UPDATE shops SET address=?, lat=?, lng=?, opening_hours=? WHERE id=?', [$address, $lat, $lng, $hours, $u['shop_id']]);
            $this->audit($c, 'shop.branding.location', 'shop', $u['shop_id'], "$lat,$lng");
            return ['ok' => true, 'address' => $address, 'lat' => $lat, 'lng' => $lng, 'opening_hours' => $hours ? json_decode($hours, true) : null];
        });

        $branchFields = function (array $b): array {
            return [V::str($b['name'] ?? null, 'Branch name', 2, 120, true), V::str($b['address'] ?? null, 'Branch address', 2, 200, true),
                    V::str($b['phone'] ?? null, 'Branch contact number', 0, 40), V::str($b['hours'] ?? null, 'Opening hours', 0, 100)];
        };
        $this->route('POST', '/api/branches', function (Ctx $c) use ($db, $branchFields) {
            $u = $this->need($c, 'shop.manage');
            [$n, $a, $p, $h] = $branchFields($c->body);
            $id = $db->run('INSERT INTO branches (shop_id, name, address, phone, hours) VALUES (?,?,?,?,?)', [$u['shop_id'], $n, $a, $p, $h]);
            $this->audit($c, 'branch.create', 'branch', $id, $n);
            return ['ok' => true, 'id' => $id];
        });
        $this->route('PATCH', '/api/branches/:id', function (Ctx $c) use ($db, $branchFields) {
            $u = $this->need($c, 'shop.manage');
            $br = $db->get('SELECT id FROM branches WHERE id=? AND shop_id=?', [(int)$c->params['id'], $u['shop_id']]);
            if (!$br) throw self::notFound('Branch not found.');
            [$n, $a, $p, $h] = $branchFields($c->body);
            $db->run('UPDATE branches SET name=?, address=?, phone=?, hours=? WHERE id=?', [$n, $a, $p, $h, $br['id']]);
            $this->audit($c, 'branch.update', 'branch', $br['id'], $n);
            return ['ok' => true];
        });

        // Import of past repairs and payments from the shop's own records (CSV). Customer names and numbers are never read:
        // every imported job is attached to one anonymized "past customer" record. Dry run first, then commit.
        $this->route('POST', '/api/repairs/import', fn(Ctx $c) => $this->importRepairs($c), ['maxBody' => 4_000_000]);

        // Public directory of shops that accepted to be listed (name, city and branches only).
        $this->route('GET', '/api/public/shops', function (Ctx $c) use ($db) {
            $this->rate("public|{$c->ip}|shops", 600, 30, 'Too many requests.');
            $like = '%' . mb_substr((string)($c->query['q'] ?? ''), 0, 60) . '%';
            $shops = $db->all("SELECT id, name, slug, city, logo_path, address, lat, lng, opening_hours FROM shops WHERE status='active' AND listed=1 AND (name LIKE ? OR city LIKE ?) ORDER BY name LIMIT 50", [$like, $like]);
            return array_map(fn($s) => $this->publicShop($s) + ['branches' => $db->all('SELECT id, name, address, phone, hours FROM branches WHERE shop_id=? ORDER BY id', [$s['id']])], $shops);
        });

        // One shop's own booking page (reached from the shop's personal link): only approved shops, listed or not.
        $this->route('GET', '/api/public/shop/:slug', function (Ctx $c) use ($db) {
            $this->rate("public|{$c->ip}|shop", 600, 60, 'Too many requests.');
            $s = $db->get("SELECT id, name, slug, city, logo_path, address, lat, lng, opening_hours FROM shops WHERE slug=? AND status='active'", [$c->params['slug']]);
            if (!$s) throw self::notFound('This shop is not available for booking.');
            return $this->publicShop($s) + ['branches' => $db->all('SELECT id, name, address, phone, hours FROM branches WHERE shop_id=? ORDER BY id', [$s['id']])];
        });

        // Free AI check for clients: coarse, non-final, nothing is stored, no personal data is asked for.
        $this->route('POST', '/api/public/diagnose', function (Ctx $c) use ($db) {
            $this->rate("check|{$c->ip}", 600, 40, 'Too many checks. Please try again in a few minutes.');
            $b = $c->body;
            $symptoms = V::str($b['symptoms'] ?? null, 'Problem description', 8, 1000, true);
            $deviceType = V::oneOf($b['deviceType'] ?? null, 'Device type', self::DEVICES);
            $shop = !empty($b['shop']) ? $db->get("SELECT id FROM shops WHERE slug=? AND status='active'", [(string)$b['shop']]) : null;
            $result = Rag::diagnose($this->indexFor($shop ? (int)$shop['id'] : null), ['symptoms' => $symptoms, 'deviceType' => $deviceType, 'brand' => V::str($b['brand'] ?? null, 'Brand', 0, 40), 'model' => V::str($b['model'] ?? null, 'Model', 0, 60)]);
            return Rag::publicCheck($result);
        });

        // A repair business registers itself: creates its own shop space, first branch and administrator account.
        $this->route('POST', '/api/business/signup', function (Ctx $c) use ($db) {
            $this->rate("signup|{$c->ip}", 3600, 20, 'Too many sign-up attempts. Please try again later.');
            $b = $c->body;
            $businessName = V::str($b['businessName'] ?? null, 'Business name', 2, 80, true);
            $ownerName = V::str($b['ownerName'] ?? null, 'Your name', 2, 80, true);
            $em = V::email($b['email'] ?? null, true);
            $ph = V::phone($b['phone'] ?? null, true);
            $city = V::str($b['city'] ?? null, 'City / town', 0, 80, true);
            $address = V::str($b['address'] ?? null, 'Shop address', 0, 160);
            if ($err = Auth::passwordProblem($b['password'] ?? null)) throw V::bad($err);
            if (($b['agree'] ?? null) !== true) throw V::bad('Please accept the terms and the Data Privacy notice.');
            if ($db->get('SELECT id FROM users WHERE email=?', [$em])) throw V::bad('An account with this email already exists.');
            $out = $db->tx(function () use ($db, $businessName, $ownerName, $em, $ph, $city, $address, $b) {
                $now = $this->now();
                $slug = $this->uniqueSlug($businessName);
                $shopId = $db->run("INSERT INTO shops (name, slug, city, phone, email, status, created_at) VALUES (?,?,?,?,?,'pending',?)", [$businessName, $slug, $city, $ph, $em, $now]);
                $branchId = $db->run('INSERT INTO branches (shop_id, name, address) VALUES (?,?,?)', [$shopId, "$businessName – Main", $address ?: $city]);
                $uid = $db->run("INSERT INTO users (shop_id, name, email, phone, role, password_hash, branch_id, created_at) VALUES (?,?,?,?,'admin',?,?,?)", [$shopId, $ownerName, $em, $ph, Auth::hashPassword($b['password']), $branchId, $now]);
                return ['shopId' => $shopId, 'uid' => $uid];
            });
            $u = $db->get('SELECT * FROM users WHERE id=?', [$out['uid']]);
            $c->setCookie = $this->cookie($this->signer->issue((int)$u['id']), 8 * 3600);
            $this->audit($c->withUser($u), 'business.signup', 'shop', $out['shopId'], $businessName);
            return $this->publicUser($u);
        });
    }

    private function routesRepairs(): void
    {
        $db = $this->db;
        $this->route('GET', '/api/repairs', function (Ctx $c) use ($db) {
            $u = $this->needUser($c);
            $where = [];
            $p = [];
            if ($u['role'] === 'customer') { $where[] = 'c.user_id = ?'; $p[] = $u['id']; }
            elseif (!Auth::can($u, 'repair.view_all')) throw self::forbidden();
            else { $where[] = 'r.shop_id = ?'; $p[] = $u['shop_id']; }
            $q = $c->query;
            if (!empty($q['status']) && in_array($q['status'], Workflow::STATUSES, true)) { $where[] = 'r.status = ?'; $p[] = $q['status']; }
            if (($q['active'] ?? '') === '1') $where[] = "r.status NOT IN ('released','cancelled')";
            if (!empty($q['branch'])) { $where[] = 'r.branch_id = ?'; $p[] = V::intId($q['branch']); }
            if (($q['mine'] ?? '') === '1') { $where[] = 'r.assigned_to = ?'; $p[] = $u['id']; }
            if (!empty($q['q'])) {
                $like = '%' . mb_substr((string)$q['q'], 0, 60) . '%';
                if (Auth::can($u, 'repair.view_pii') || $u['role'] === 'customer') { $where[] = '(r.code LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR r.brand LIKE ? OR r.model LIKE ?)'; array_push($p, $like, $like, $like, $like, $like); }
                else { $where[] = '(r.code LIKE ? OR r.brand LIKE ? OR r.model LIKE ? OR r.reported_symptoms LIKE ?)'; array_push($p, $like, $like, $like, $like); }
            }
            $rows = $db->all('SELECT r.id FROM repairs r JOIN customers c ON c.id=r.customer_id ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " ORDER BY CASE r.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END, r.updated_at DESC, r.id DESC LIMIT 300", $p);
            return array_map(fn($x) => $this->shape($u, $this->loadRepair((int)$x['id'])), $rows);
        });

        $this->route('POST', '/api/repairs', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'repair.create');
            $b = $c->body;
            $data = [
                'name' => V::str($b['name'] ?? null, 'Customer name', 2, 80, true), 'email' => V::email($b['email'] ?? null), 'phone' => V::phone($b['phone'] ?? null, true),
                'consent' => ($b['consent'] ?? null) === true, 'branchId' => V::intId($b['branchId'] ?? null) ?: (int)$u['branch_id'],
                'deviceType' => V::oneOf($b['deviceType'] ?? null, 'Device type', self::DEVICES),
                'brand' => V::str($b['brand'] ?? null, 'Brand', 0, 40), 'model' => V::str($b['model'] ?? null, 'Model', 0, 60), 'serial' => V::str($b['serial'] ?? null, 'Serial/IMEI', 0, 40),
                'accessories' => V::str($b['accessories'] ?? null, 'Accessories', 0, 200), 'conditionNotes' => V::str($b['conditionNotes'] ?? null, 'Condition notes', 0, 500),
                'symptoms' => V::str($b['symptoms'] ?? null, 'Problem description', 8, 1000, true),
                'priority' => V::oneOf($b['priority'] ?? null, 'Priority', ['low', 'normal', 'high', 'urgent'], 'normal'),
                'source' => 'walk_in', 'initialStatus' => 'received',
            ];
            if (!$data['consent']) throw V::bad("Record the customer's consent to the Data Privacy notice.");
            if (!$db->get('SELECT id FROM branches WHERE id=? AND shop_id=?', [$data['branchId'], $u['shop_id']])) throw V::bad('Please choose a branch.');
            ['repair' => $repair] = $this->createRepair($c, $data);
            return $this->shape($u, $this->loadRepair((int)$repair['id']), true);
        });

        $this->route('GET', '/api/repairs/:id', function (Ctx $c) {
            $u = $this->needUser($c);
            $r = $this->loadRepair(V::intId($c->params['id']), $u);
            if (!$r || !$this->canSee($u, $r)) throw self::notFound();
            if (Auth::can($u, 'repair.view_pii') && $u['role'] !== 'customer') $this->audit($c, 'record.view', 'repair', $r['id'], 'viewed customer details');
            return $this->shape($u, $r, true);
        });

        $this->route('PATCH', '/api/repairs/:id', function (Ctx $c) use ($db) {
            $u = $this->needUser($c);
            $r = $this->loadRepair(V::intId($c->params['id']), $u);
            if (!$r) throw self::notFound();
            if (!Auth::can($u, 'repair.update')) throw self::forbidden();
            if (Workflow::isTerminal($r['status'])) throw V::bad('This repair is closed and can no longer be edited.');
            $this->assertWorkAccess($u, $r);
            $b = $c->body;
            $f = [];
            if (array_key_exists('findings', $b)) $f['findings'] = V::str($b['findings'], 'Findings', 0, 2000);
            if (array_key_exists('verified_diagnosis', $b)) {
                if (!Auth::can($u, 'ai.feedback') && !Auth::can($u, 'repair.diagnose')) throw self::forbidden();
                $f['verified_diagnosis'] = V::str($b['verified_diagnosis'], 'Verified diagnosis', 0, 200);
            }
            if (array_key_exists('repair_action', $b)) $f['repair_action'] = V::str($b['repair_action'], 'Repair action', 0, 2000);
            if (array_key_exists('est_ready_date', $b)) {
                $d = V::str($b['est_ready_date'], 'Estimated date', 0, 10);
                if ($d && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)))) throw V::bad('Date must be YYYY-MM-DD.');
                $f['est_ready_date'] = $d;
            }
            if (array_key_exists('priority', $b)) $f['priority'] = V::oneOf($b['priority'], 'Priority', ['low', 'normal', 'high', 'urgent']);
            if (array_key_exists('condition_notes', $b)) $f['condition_notes'] = V::str($b['condition_notes'], 'Condition notes', 0, 500);
            if (!$f) throw V::bad('Nothing to update.');
            $db->run('UPDATE repairs SET ' . implode(', ', array_map(fn($k) => "$k=?", array_keys($f))) . ', updated_at=? WHERE id=?', [...array_values($f), $this->now(), $r['id']]);
            $this->audit($c, 'repair.update', 'repair', $r['id'], implode(',', array_keys($f)));
            return $this->shape($u, $this->loadRepair((int)$r['id']), true);
        });

        $this->route('POST', '/api/repairs/:id/transition', function (Ctx $c) {
            $u = $this->needUser($c);
            $r = $this->loadRepair(V::intId($c->params['id']), $u);
            if (!$r || !$this->canSee($u, $r)) throw self::notFound();
            $to = V::oneOf($c->body['to'] ?? null, 'Status', Workflow::STATUSES);
            if ($u['role'] === 'customer') throw self::forbidden();
            $this->applyTransition($c, $r, $to, V::str($c->body['note'] ?? null, 'Note', 0, 500), ($c->body['override'] ?? null) === true);
            return $this->shape($u, $this->loadRepair((int)$r['id']), true);
        });

        $this->route('POST', '/api/repairs/:id/approve', function (Ctx $c) {
            $u = $this->needUser($c);
            $r = $this->loadRepair(V::intId($c->params['id']), $u);
            if (!$r || !$this->canSee($u, $r)) throw self::notFound();
            if (!Auth::can($u, 'repair.approve_quote')) throw self::forbidden();
            if ($r['status'] !== 'awaiting_approval') throw V::bad('There is no quote waiting for approval.');
            $approve = ($c->body['approve'] ?? null) === true;
            if ($u['role'] === 'customer') {
                if ($approve) $this->applyTransition($c, $r, 'repairing', 'Quote approved by customer', false, true);
                else $this->applyTransition($c, $r, 'cancelled', 'Quote declined by customer', false, true);
            } else {
                $note = V::str($c->body['note'] ?? null, 'Note', 0, 300, true);
                $this->applyTransition($c, $r, $approve ? 'repairing' : 'cancelled', $note);
            }
            return $this->shape($u, $this->loadRepair((int)$r['id']), true);
        });

        $this->route('POST', '/api/repairs/:id/assign', function (Ctx $c) use ($db) {
            $u = $this->needUser($c);
            $r = $this->loadRepair(V::intId($c->params['id']), $u);
            if (!$r) throw self::notFound();
            $techId = V::intId($c->body['technicianId'] ?? null);
            $tech = $db->get("SELECT id,name FROM users WHERE id=? AND role='technician' AND active=1 AND shop_id=?", [$techId, $r['shop_id']]);
            if (!$tech) throw V::bad('Choose an active technician.');
            $self = $u['role'] === 'technician' && $techId === (int)$u['id'] && !$r['assigned_to'];
            if (!Auth::can($u, 'repair.assign') && !$self) throw self::forbidden();
            if (Workflow::isTerminal($r['status'])) throw V::bad('This repair is closed.');
            $db->run('UPDATE repairs SET assigned_to=?, updated_at=? WHERE id=?', [$techId, $this->now(), $r['id']]);
            $this->addEvent((int)$r['id'], $r['status'], $r['status'], $c, "Assigned to {$tech['name']}", 0);
            $this->audit($c, 'repair.assign', 'repair', $r['id'], $tech['name']);
            return $this->shape($u, $this->loadRepair((int)$r['id']), true);
        });

        $this->route('POST', '/api/repairs/:id/items', function (Ctx $c) use ($db) {
            [$u, $r] = $this->withRepair($c, 'repair.quote');
            if (!in_array($r['status'], ['received', 'diagnosing'], true)) throw V::bad('Quote items can only be changed while the repair is being diagnosed. Move it back to Diagnosing to revise a quote.');
            $this->assertWorkAccess($u, $r);
            $db->run('INSERT INTO repair_items (repair_id, kind, description, amount, created_at) VALUES (?,?,?,?,?)',
                [$r['id'], V::oneOf($c->body['kind'] ?? null, 'Type', ['labor', 'part', 'other']), V::str($c->body['description'] ?? null, 'Description', 0, 150, true), V::money($c->body['amount'] ?? null, 'Amount'), $this->now()]);
            $this->audit($c, 'repair.item_add', 'repair', $r['id'], (string)($c->body['description'] ?? ''));
            return $this->shape($u, $this->loadRepair((int)$r['id']), true);
        });
        $this->route('DELETE', '/api/repairs/:id/items/:itemId', function (Ctx $c) use ($db) {
            [$u, $r] = $this->withRepair($c, 'repair.quote');
            if (!in_array($r['status'], ['received', 'diagnosing'], true)) throw V::bad('Quote items can only be changed while the repair is being diagnosed.');
            $this->assertWorkAccess($u, $r);
            $db->run('DELETE FROM repair_items WHERE id=? AND repair_id=?', [V::intId($c->params['itemId']), $r['id']]);
            $this->audit($c, 'repair.item_delete', 'repair', $r['id'], $c->params['itemId']);
            return $this->shape($u, $this->loadRepair((int)$r['id']), true);
        });

        $this->route('POST', '/api/repairs/:id/payment', function (Ctx $c) use ($db) {
            [$u, $r] = $this->withRepair($c, 'repair.pay');
            if ($r['status'] === 'cancelled') throw V::bad('Repair is cancelled.');
            $amount = V::money($c->body['amount'] ?? null, 'Amount');
            if ($amount <= 0) throw V::bad('Amount must be greater than zero.');
            $total = $this->itemsTotal((int)$r['id']);
            $paid = $this->paidTotal((int)$r['id']);
            if ($total <= 0) throw V::bad('There is no quote to pay yet.');
            if ($amount - ($total - $paid) > 0.009) throw V::bad(sprintf('Payment exceeds the outstanding balance of PHP %.2f.', $total - $paid));
            $db->run('INSERT INTO payments (repair_id, amount, method, received_by, created_at) VALUES (?,?,?,?,?)',
                [$r['id'], $amount, V::oneOf($c->body['method'] ?? null, 'Method', ['cash', 'gcash', 'card', 'bank'], 'cash'), $u['id'], $this->now()]);
            $this->audit($c, 'repair.payment', 'repair', $r['id'], sprintf('PHP %.2f (simulated record)', $amount));
            return $this->shape($u, $this->loadRepair((int)$r['id']), true);
        });

        // --- AI diagnostics ---
        $this->route('POST', '/api/repairs/:id/diagnose', function (Ctx $c) use ($db) {
            [$u, $r] = $this->withRepair($c, 'ai.run');
            $this->assertWorkAccess($u, $r);
            $extra = V::str($c->body['extra'] ?? null, 'Additional observations', 0, 800) ?? '';
            $result = Rag::diagnose($this->indexFor((int)$r['shop_id']), ['symptoms' => $r['reported_symptoms'], 'deviceType' => $r['device_type'], 'brand' => $r['brand'], 'model' => $r['model'], 'extra' => $extra]);
            $fallback = Rag::composeSummary($result, $r + ['deviceType' => $r['device_type']]);
            ['text' => $text, 'mode' => $mode] = $this->llm->summary($result, ['deviceType' => $r['device_type'], 'brand' => $r['brand'], 'model' => $r['model']], $fallback);
            $id = $db->run('INSERT INTO ai_runs (repair_id, query_text, result_json, mode, created_by, created_at) VALUES (?,?,?,?,?,?)', [$r['id'], $result['query'], self::json_($result, $text), $mode, $u['id'], $this->now()]);
            $this->audit($c, 'ai.run', 'repair', $r['id'], "mode=$mode top=" . ($result['recommendations'][0]['diagnosis'] ?? 'none'));
            return ['id' => $id, 'mode' => $mode, 'result' => ['summary' => $text] + $result];
        });

        $this->route('POST', '/api/repairs/:id/ai/:runId/feedback', function (Ctx $c) use ($db) {
            [$u, $r] = $this->withRepair($c, 'ai.feedback');
            $this->assertWorkAccess($u, $r);
            $a = $db->get('SELECT * FROM ai_runs WHERE id=? AND repair_id=?', [V::intId($c->params['runId']), $r['id']]);
            if (!$a) throw self::notFound();
            $accepted = ($c->body['accepted'] ?? null) === true;
            $diagnosis = V::str($c->body['diagnosis'] ?? null, 'Diagnosis', 0, 200, true);
            $result = json_decode($a['result_json'], true);
            $idx = -1;
            foreach ($result['recommendations'] ?? [] as $i => $x) if ($x['diagnosis'] === $diagnosis) { $idx = $i; break; }
            $rank = $accepted && $idx >= 0 ? $idx + 1 : null;
            $now = $this->now();
            $db->run('UPDATE ai_runs SET feedback_diagnosis=?, feedback_rank=?, feedback_accepted=?, feedback_note=?, feedback_by=?, feedback_at=? WHERE id=?',
                [$diagnosis, $rank, $accepted ? 1 : 0, V::str($c->body['note'] ?? null, 'Note', 0, 300), $u['id'], $now, $a['id']]);
            if ($accepted && !Workflow::isTerminal($r['status'])) $db->run('UPDATE repairs SET verified_diagnosis=?, updated_at=? WHERE id=?', [$diagnosis, $now, $r['id']]);
            $this->audit($c, 'ai.feedback', 'repair', $r['id'], ($accepted ? 'accepted' : 'rejected') . ": $diagnosis");
            return $this->shape($u, $this->loadRepair((int)$r['id']), true);
        });
    }

    // ---- CSV import helpers ----
    private const DEVICE_ALIASES = ['smartphone' => 'smartphone', 'phone' => 'smartphone', 'cellphone' => 'smartphone', 'cell phone' => 'smartphone', 'mobile' => 'smartphone', 'android' => 'smartphone', 'iphone' => 'smartphone', 'laptop' => 'laptop', 'notebook' => 'laptop', 'macbook' => 'laptop', 'tablet' => 'tablet', 'ipad' => 'tablet'];
    private const CSV_COLS = [
        'device_type' => ['device_type', 'device', 'type'], 'brand' => ['brand'], 'model_hint' => ['model', 'model_hint'],
        'symptoms' => ['symptoms', 'complaint', 'problem', 'customer_complaint'], 'diagnosis' => ['diagnosis', 'verified_diagnosis', 'fault'],
        'root_cause' => ['root_cause', 'cause'], 'checks' => ['checks', 'suggested_checks'], 'repair_action' => ['repair_action', 'repair', 'action_taken', 'repair_done'],
        'parts' => ['parts', 'parts_used'], 'cost_min' => ['cost_min', 'min_cost', 'cost'], 'cost_max' => ['cost_max', 'max_cost'], 'hours' => ['hours', 'repair_hours'],
    ];

    private static function parseCsv(string $text): array
    {
        $rows = [];
        $row = [];
        $cell = '';
        $q = false;
        $t = preg_replace('/^\x{FEFF}/u', '', $text);
        $chars = preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            if ($q) {
                if ($c === '"') { if (($chars[$i + 1] ?? null) === '"') { $cell .= '"'; $i++; } else $q = false; }
                else $cell .= $c;
            } elseif ($c === '"') $q = true;
            elseif ($c === ',') { $row[] = $cell; $cell = ''; }
            elseif ($c === "\n" || $c === "\r") {
                if ($c === "\r" && ($chars[$i + 1] ?? null) === "\n") $i++;
                $row[] = $cell; $rows[] = $row; $row = []; $cell = '';
            } else $cell .= $c;
        }
        if ($cell !== '' || $row) { $row[] = $cell; $rows[] = $row; }
        return array_values(array_filter($rows, fn($r) => (bool)array_filter($r, fn($x) => trim($x) !== '')));
    }

    private function routesKnowledge(): void
    {
        $db = $this->db;
        $this->route('GET', '/api/cases', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'kb.view');
            // Shared base cases (read-only for shops) plus this shop's own cases.
            $where = ['(shop_id IS NULL OR shop_id = ?)'];
            $p = [$u['shop_id']];
            if (!empty($c->query['status']) && in_array($c->query['status'], ['approved', 'pending', 'rejected'], true)) { $where[] = 'status=?'; $p[] = $c->query['status']; }
            if (!empty($c->query['q'])) { $where[] = '(symptoms LIKE ? OR diagnosis LIKE ? OR brand LIKE ?)'; $l = '%' . mb_substr((string)$c->query['q'], 0, 60) . '%'; array_push($p, $l, $l, $l); }
            $rows = $db->all('SELECT *, CASE WHEN shop_id IS NULL THEN 1 ELSE 0 END AS shared FROM cases WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 300', $p);
            return array_map(function ($r) { $r['shared'] = (int)$r['shared']; return $r; }, $rows);
        });
        $this->route('POST', '/api/cases', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'kb.submit');
            $b = $c->body;
            $status = Auth::can($u, 'kb.manage') ? 'approved' : 'pending';
            $opt = fn($k, $n) => isset($b[$k]) && $b[$k] !== '' ? V::money($b[$k], $n) : null;
            $db->run("INSERT INTO cases (shop_id, device_type, brand, model_hint, symptoms, diagnosis, root_cause, checks, repair_action, parts, cost_min, cost_max, hours, status, source, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'shop',?,?)",
                [$u['shop_id'], V::oneOf($b['device_type'] ?? null, 'Device type', self::DEVICES), V::str($b['brand'] ?? null, 'Brand', 0, 40), V::str($b['model_hint'] ?? null, 'Model', 0, 60),
                 V::str($b['symptoms'] ?? null, 'Symptoms', 8, 900, true), V::str($b['diagnosis'] ?? null, 'Diagnosis', 0, 200, true), V::str($b['root_cause'] ?? null, 'Root cause', 0, 400),
                 V::str($b['checks'] ?? null, 'Checks', 0, 600), V::str($b['repair_action'] ?? null, 'Repair action', 0, 600), V::str($b['parts'] ?? null, 'Parts', 0, 200),
                 $opt('cost_min', 'Min cost'), $opt('cost_max', 'Max cost'), isset($b['hours']) && $b['hours'] !== '' ? V::num($b['hours']) : null, $status, $u['id'], $this->now()]);
            if ($status === 'approved') $this->reindex();
            $this->audit($c, 'kb.add', 'case', null, ($b['diagnosis'] ?? '') . " ($status)");
            return ['ok' => true, 'status' => $status];
        });
        $this->route('PATCH', '/api/cases/:id', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'kb.manage');
            $cs = $db->get('SELECT * FROM cases WHERE id=? AND shop_id=?', [V::intId($c->params['id']), $u['shop_id']]);
            if (!$cs) throw self::notFound();
            $status = V::oneOf($c->body['status'] ?? null, 'Status', ['approved', 'pending', 'rejected']);
            $sets = ['status=?'];
            $vals = [$status];
            foreach (['diagnosis', 'root_cause', 'checks', 'repair_action', 'parts'] as $k) {
                if (isset($c->body[$k]) && is_string($c->body[$k])) { $sets[] = "$k=?"; $vals[] = mb_substr(trim($c->body[$k]), 0, 600); }
            }
            $db->run('UPDATE cases SET ' . implode(', ', $sets) . ' WHERE id=?', [...$vals, $cs['id']]);
            $this->reindex();
            $this->audit($c, 'kb.review', 'case', $cs['id'], $status);
            return ['ok' => true];
        });

        // Bulk import of verified historical cases from a CSV (administrators only). Dry run first, then commit.
        $this->route('POST', '/api/cases/import', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'kb.manage');
            $text = is_string($c->body['csv'] ?? null) ? $c->body['csv'] : '';
            if (trim($text) === '') throw V::bad('The file is empty.');
            $table = self::parseCsv($text);
            if (count($table) < 2) throw V::bad('The file needs a header row and at least one case.');
            if (count($table) - 1 > 2000) throw V::bad('Please import at most 2000 cases at a time.');
            $header = array_map(fn($x) => preg_replace('/[\s-]+/', '_', mb_strtolower(trim($x), 'UTF-8')), $table[0]);
            $idx = [];
            foreach (self::CSV_COLS as $k => $names) {
                foreach ($header as $i => $h) if (in_array($h, $names, true)) { $idx[$k] = $i; break; }
            }
            foreach (['symptoms', 'diagnosis'] as $need) if (!isset($idx[$need])) throw V::bad("Missing column \"$need\". Download the template to see the expected columns.");
            // Reuse the exact spelling of diagnosis labels that already exist so similar cases vote together.
            $labels = [];
            foreach ($db->all('SELECT DISTINCT diagnosis FROM cases WHERE shop_id IS NULL OR shop_id=?', [$u['shop_id']]) as $r) $labels[mb_strtolower($r['diagnosis'], 'UTF-8')] = $r['diagnosis'];
            $existing = [];
            foreach ($db->all('SELECT symptoms, diagnosis FROM cases WHERE shop_id=?', [$u['shop_id']]) as $r) $existing[mb_strtolower($r['symptoms'], 'UTF-8') . '|' . mb_strtolower($r['diagnosis'], 'UTF-8')] = true;
            $errors = [];
            $good = [];
            $newLabels = [];
            $duplicates = 0;
            $matched = 0;
            $cell = fn($r, $k) => isset($idx[$k]) ? trim((string)($r[$idx[$k]] ?? '')) : '';
            // returns [ok, value]
            $num = function (string $v, string $name, int $row) use (&$errors) {
                if ($v === '') return [true, null];
                $n = preg_replace('/[₱,\s]|php/iu', '', $v);
                if (!is_numeric($n) || (float)$n < 0) { $errors[] = ['row' => $row, 'message' => "$name \"$v\" is not a valid number."]; return [false, null]; }
                return [true, (float)$n];
            };
            foreach (array_slice($table, 1) as $k => $r) {
                $row = $k + 2; // spreadsheet row number
                $symptoms = preg_replace('/\s+/u', ' ', $cell($r, 'symptoms'));
                $diagnosis = preg_replace('/\s+/u', ' ', $cell($r, 'diagnosis'));
                if (mb_strlen($symptoms) < 8) { $errors[] = ['row' => $row, 'message' => 'Symptoms must be at least 8 characters.']; continue; }
                if (mb_strlen($symptoms) > 900) { $errors[] = ['row' => $row, 'message' => 'Symptoms are longer than 900 characters.']; continue; }
                if ($diagnosis === '') { $errors[] = ['row' => $row, 'message' => 'Diagnosis is empty.']; continue; }
                if (mb_strlen($diagnosis) > 200) { $errors[] = ['row' => $row, 'message' => 'Diagnosis is longer than 200 characters.']; continue; }
                $dt = mb_strtolower($cell($r, 'device_type'), 'UTF-8');
                $deviceType = self::DEVICE_ALIASES[$dt] ?? ($dt !== '' ? 'other' : null);
                if (!$deviceType) { $errors[] = ['row' => $row, 'message' => 'Device type is empty (use smartphone, laptop, tablet or other).']; continue; }
                [$ok1, $cmin] = $num($cell($r, 'cost_min'), 'Cost', $row);
                [$ok2, $cmax] = $num($cell($r, 'cost_max'), 'Max cost', $row);
                [$ok3, $hours] = $num($cell($r, 'hours'), 'Hours', $row);
                if (!$ok1 || !$ok2 || !$ok3) continue;
                $lk = mb_strtolower($diagnosis, 'UTF-8');
                if (isset($labels[$lk])) { if ($labels[$lk] !== $diagnosis) $matched++; $diagnosis = $labels[$lk]; }
                else { $newLabels[$diagnosis] = true; $labels[$lk] = $diagnosis; }
                $key = mb_strtolower($symptoms, 'UTF-8') . '|' . mb_strtolower($diagnosis, 'UTF-8');
                if (isset($existing[$key])) { $duplicates++; continue; }
                $existing[$key] = true;
                $good[] = [
                    'device_type' => $deviceType, 'brand' => mb_substr($cell($r, 'brand'), 0, 40) ?: null, 'model_hint' => mb_substr($cell($r, 'model_hint'), 0, 60) ?: null,
                    'symptoms' => $symptoms, 'diagnosis' => $diagnosis, 'root_cause' => mb_substr($cell($r, 'root_cause'), 0, 400) ?: null, 'checks' => mb_substr($cell($r, 'checks'), 0, 600) ?: null,
                    'repair_action' => mb_substr($cell($r, 'repair_action'), 0, 600) ?: null, 'parts' => mb_substr($cell($r, 'parts'), 0, 200) ?: null,
                    'cost_min' => $cmin, 'cost_max' => $cmax ?? $cmin, 'hours' => $hours,
                ];
            }
            $dry = ($c->body['dryRun'] ?? null) !== false;
            if (!$dry && $good) {
                $db->tx(function () use ($db, $good, $u) {
                    $now = $this->now();
                    foreach ($good as $g) {
                        $db->run("INSERT INTO cases (shop_id, device_type, brand, model_hint, symptoms, diagnosis, root_cause, checks, repair_action, parts, cost_min, cost_max, hours, status, source, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'approved','import',?,?)",
                            [$u['shop_id'], $g['device_type'], $g['brand'], $g['model_hint'], $g['symptoms'], $g['diagnosis'], $g['root_cause'], $g['checks'], $g['repair_action'], $g['parts'], $g['cost_min'], $g['cost_max'], $g['hours'], $u['id'], $now]);
                    }
                });
                $this->reindex();
                $this->audit($c, 'kb.import', 'case', null, count($good) . " cases imported, $duplicates duplicates skipped, " . count($errors) . ' rows rejected');
            }
            return [
                'dry_run' => $dry, 'total_rows' => count($table) - 1, 'valid' => count($good), 'duplicates' => $duplicates, 'relabelled' => $matched, 'rejected' => count($errors),
                'new_labels' => array_slice(array_map('strval', array_keys($newLabels)), 0, 30), 'errors' => array_slice($errors, 0, 50), 'imported' => $dry ? 0 : count($good),
            ];
        }, ['maxBody' => 2_000_000]);
    }


    private const REPAIR_COLS = [
        'job_no' => ['job_no', 'job_order', 'job_order_no', 'job_number', 'jo', 'ref'], 'received' => ['date_received', 'received', 'date_in', 'date'],
        'released' => ['date_released', 'released', 'date_out', 'date_paid', 'paid_on'], 'branch' => ['branch'],
        'device_type' => ['device_type', 'device', 'type'], 'brand' => ['brand'], 'model' => ['model'],
        'problem' => ['problem', 'symptoms', 'complaint', 'customer_complaint'], 'diagnosis' => ['diagnosis', 'verified_diagnosis', 'fault'],
        'repair_done' => ['repair_done', 'repair_action', 'action_taken', 'repair'], 'status' => ['status'],
        'parts_cost' => ['parts_cost', 'parts', 'parts_amount'], 'labor_cost' => ['labor_cost', 'labor', 'labour', 'service_fee', 'labor_fee'],
        'total' => ['total', 'amount', 'total_amount', 'price'], 'amount_paid' => ['amount_paid', 'paid'],
        'method' => ['payment_method', 'method', 'mode_of_payment'], 'technician' => ['technician', 'tech'],
    ];

    /** Parses YYYY-MM-DD or M/D/YYYY into [y, m, d] or null. */
    private static function parseDate(string $v): ?array
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $v, $m)) [$y, $mo, $d] = [(int)$m[3], (int)$m[1], (int)$m[2]];
        else return null;
        return checkdate($mo, $d, $y) && $y >= 2000 ? [$y, $mo, $d] : null;
    }

    private function importRepairs(Ctx $c): array
    {
        $db = $this->db;
        $u = $this->need($c, 'data.import');
        $text = is_string($c->body['csv'] ?? null) ? $c->body['csv'] : '';
        if (trim($text) === '') throw V::bad('The file is empty.');
        $table = self::parseCsv($text);
        if (count($table) < 2) throw V::bad('The file needs a header row and at least one repair.');
        if (count($table) - 1 > 5000) throw V::bad('Please import at most 5000 repairs at a time.');
        $dry = ($c->body['dryRun'] ?? null) !== false;
        if (!$dry && ($c->body['permission'] ?? null) !== true) throw V::bad('Please confirm that you have permission to use these records and that customer names and numbers were left out.');
        $addToKb = ($c->body['addToKb'] ?? null) === true;
        $header = array_map(fn($x) => preg_replace('/[\s-]+/', '_', mb_strtolower(trim($x), 'UTF-8')), $table[0]);
        $idx = [];
        foreach (self::REPAIR_COLS as $k => $names) foreach ($header as $i => $h) if (in_array($h, $names, true)) { $idx[$k] = $i; break; }
        foreach (['received', 'problem'] as $need) if (!isset($idx[$need])) throw V::bad("Missing column \"$need\". Download the template to see the expected columns.");
        if (!isset($idx['total']) && !isset($idx['parts_cost']) && !isset($idx['labor_cost'])) throw V::bad('Missing an amount column: use "total", or "parts_cost" and "labor_cost".');

        $shopId = (int)$u['shop_id'];
        $branches = $db->all('SELECT id, name FROM branches WHERE shop_id=? ORDER BY id', [$shopId]);
        $techs = $db->all("SELECT id, name FROM users WHERE shop_id=? AND role='technician' AND active=1", [$shopId]);
        $known = [];
        foreach ($db->all('SELECT external_ref FROM repairs WHERE shop_id=? AND external_ref IS NOT NULL', [$shopId]) as $r) $known[$r['external_ref']] = true;
        $cell = fn($r, $k) => isset($idx[$k]) ? trim((string)($r[$idx[$k]] ?? '')) : '';
        $tz = 480; // Philippine time (UTC+8): dates in the file are local dates
        $toUtc = fn(array $d, int $hour) => gmdate('Y-m-d H:i:s', gmmktime($hour, 0, 0, $d[1], $d[2], $d[0]) - $tz * 60);
        $errors = []; $good = []; $dups = 0; $byBranch = []; $minD = null; $maxD = null; $sales = 0.0;
        $money = function (string $v, string $name, int $row) use (&$errors) {
            if ($v === '') return [true, null];
            $n = preg_replace('/[₱,\s]|php/iu', '', $v);
            if (!is_numeric($n) || (float)$n < 0 || (float)$n > 10_000_000) { $errors[] = ['row' => $row, 'message' => "$name \"$v\" is not a valid amount."]; return [false, null]; }
            return [true, round((float)$n, 2)];
        };
        $today = gmdate('Y-m-d', time() + $tz * 60);
        foreach (array_slice($table, 1) as $k => $r) {
            $row = $k + 2;
            $rec = $cell($r, 'received'); $recD = self::parseDate($rec);
            if (!$recD) { $errors[] = ['row' => $row, 'message' => "Date received \"$rec\" is not a date (use YYYY-MM-DD or M/D/YYYY)."]; continue; }
            $relRaw = $cell($r, 'released');
            $relD = $relRaw !== '' ? self::parseDate($relRaw) : $recD;
            if (!$relD) { $errors[] = ['row' => $row, 'message' => "Date released \"$relRaw\" is not a date."]; continue; }
            $recS = sprintf('%04d-%02d-%02d', ...$recD); $relS = sprintf('%04d-%02d-%02d', ...$relD);
            if ($recS > $today || $relS > $today) { $errors[] = ['row' => $row, 'message' => 'Dates cannot be in the future.']; continue; }
            if ($relS < $recS) { $errors[] = ['row' => $row, 'message' => 'Date released is before the date received.']; continue; }
            $problem = preg_replace('/\s+/u', ' ', $cell($r, 'problem'));
            if (mb_strlen($problem) < 4) { $errors[] = ['row' => $row, 'message' => 'Problem must be at least 4 characters.']; continue; }
            if (mb_strlen($problem) > 900) { $errors[] = ['row' => $row, 'message' => 'Problem is longer than 900 characters.']; continue; }
            $dt = mb_strtolower($cell($r, 'device_type'), 'UTF-8');
            $device = self::DEVICE_ALIASES[$dt] ?? ($dt !== '' ? 'other' : null);
            if (!$device) { $errors[] = ['row' => $row, 'message' => 'Device type is empty (use smartphone, laptop, tablet or other).']; continue; }
            // branch
            $bc = mb_strtolower($cell($r, 'branch'), 'UTF-8'); $branch = null;
            if ($bc === '' && count($branches) === 1) $branch = $branches[0];
            elseif ($bc === '') { $errors[] = ['row' => $row, 'message' => 'Branch is empty.']; continue; }
            else {
                $hits = array_values(array_filter($branches, fn($b) => mb_strlen($bc) >= 3 && str_contains(mb_strtolower($b['name'], 'UTF-8'), $bc)));
                if (count($hits) === 1) $branch = $hits[0];
                else { $errors[] = ['row' => $row, 'message' => count($hits) > 1 ? "Branch \"{$cell($r, 'branch')}\" matches more than one branch." : "Branch \"{$cell($r, 'branch')}\" does not match any of your branches."]; continue; }
            }
            // status
            $st = mb_strtolower($cell($r, 'status'), 'UTF-8');
            $status = in_array($st, ['', 'released', 'completed', 'done', 'claimed'], true) ? 'released' : (in_array($st, ['cancelled', 'canceled', 'unrepairable'], true) ? 'cancelled' : null);
            if (!$status) { $errors[] = ['row' => $row, 'message' => "Status \"{$cell($r, 'status')}\" is not supported (use released or cancelled)."]; continue; }
            // money
            [$o1, $parts] = $money($cell($r, 'parts_cost'), 'Parts cost', $row); [$o2, $labor] = $money($cell($r, 'labor_cost'), 'Labor cost', $row);
            [$o3, $totalC] = $money($cell($r, 'total'), 'Total', $row); [$o4, $paidC] = $money($cell($r, 'amount_paid'), 'Amount paid', $row);
            if (!$o1 || !$o2 || !$o3 || !$o4) continue;
            $parts = $parts ?? 0.0; $labor = $labor ?? 0.0;
            $total = $totalC ?? round($parts + $labor, 2);
            if ($parts + $labor > $total + 0.009) { $errors[] = ['row' => $row, 'message' => 'Parts + labor is more than the total.']; continue; }
            if ($status === 'released' && $total <= 0) { $errors[] = ['row' => $row, 'message' => 'A released repair needs an amount above 0.']; continue; }
            $paid = $status === 'released' ? ($paidC ?? $total) : 0.0;
            $diag = preg_replace('/\s+/u', ' ', $cell($r, 'diagnosis'));
            if (mb_strlen($diag) > 200) { $errors[] = ['row' => $row, 'message' => 'Diagnosis is longer than 200 characters.']; continue; }
            $jobNo = mb_substr($cell($r, 'job_no'), 0, 40);
            $ref = $jobNo !== '' ? $jobNo : 'auto:' . substr(sha1(implode('|', [$branch['id'], $recS, $relS, $device, mb_strtolower($problem, 'UTF-8'), $total])), 0, 20);
            if (isset($known[$ref])) { $dups++; continue; }
            $known[$ref] = true;
            $techId = null;
            $tn = mb_strtolower($cell($r, 'technician'), 'UTF-8');
            if ($tn !== '') foreach ($techs as $t) if (str_contains(mb_strtolower($t['name'], 'UTF-8'), $tn)) { $techId = (int)$t['id']; break; }
            $m = mb_strtolower($cell($r, 'method'), 'UTF-8');
            $method = str_contains($m, 'gcash') ? 'gcash' : (str_contains($m, 'card') ? 'card' : (str_contains($m, 'bank') ? 'bank' : 'cash'));
            $good[] = ['ref' => $ref, 'branch' => $branch, 'device' => $device, 'brand' => mb_substr($cell($r, 'brand'), 0, 60) ?: null, 'model' => mb_substr($cell($r, 'model'), 0, 60) ?: null,
                'problem' => $problem, 'diagnosis' => $diag ?: null, 'action' => mb_substr($cell($r, 'repair_done'), 0, 600) ?: null, 'status' => $status,
                'parts' => $parts, 'labor' => $labor, 'total' => $total, 'paid' => $paid, 'method' => $method, 'tech' => $techId,
                'created' => $toUtc($recD, 9), 'closed' => $toUtc($relD, 12), 'recS' => $recS, 'relS' => $relS];
            $bn = $branch['name'];
            $byBranch[$bn] = ($byBranch[$bn] ?? ['branch' => $bn, 'repairs' => 0, 'sales' => 0.0]);
            $byBranch[$bn]['repairs']++; $byBranch[$bn]['sales'] += $paid;
            $sales += $paid;
            $minD = $minD === null || $recS < $minD ? $recS : $minD; $maxD = $maxD === null || $relS > $maxD ? $relS : $maxD;
        }

        $kbAdded = 0;
        if (!$dry && $good) {
            $kbAdded = $db->tx(function () use ($db, $good, $u, $shopId, $addToKb) {
                $now = $this->now();
                $cust = $db->get('SELECT id FROM customers WHERE shop_id=? AND anonymized_at IS NOT NULL AND name=? AND user_id IS NULL', [$shopId, 'Past customer (imported, anonymized)']);
                $cid = $cust ? (int)$cust['id'] : $db->run('INSERT INTO customers (shop_id, name, phone, email, consent_at, anonymized_at, created_at) VALUES (?,?,?,?,?,?,?)', [$shopId, 'Past customer (imported, anonymized)', null, null, $now, $now, $now]);
                $seq = [];
                $labels = []; $existing = [];
                if ($addToKb) {
                    foreach ($db->all('SELECT DISTINCT diagnosis FROM cases WHERE shop_id IS NULL OR shop_id=?', [$shopId]) as $r) $labels[mb_strtolower($r['diagnosis'], 'UTF-8')] = $r['diagnosis'];
                    foreach ($db->all('SELECT symptoms, diagnosis FROM cases WHERE shop_id=?', [$shopId]) as $r) $existing[mb_strtolower($r['symptoms'], 'UTF-8') . '|' . mb_strtolower($r['diagnosis'], 'UTF-8')] = true;
                }
                $kb = 0;
                foreach ($good as $g) {
                    $pre = 'RS-H' . substr($g['recS'], 2, 2) . substr($g['recS'], 5, 2) . '-';
                    $seq[$pre] ??= (int)$db->val('SELECT COUNT(*) FROM repairs WHERE code LIKE ?', [$pre . '%']);
                    do { $code = $pre . sprintf('%04d', ++$seq[$pre]); } while ($db->get('SELECT id FROM repairs WHERE code=?', [$code]));
                    $closed = $g['status'] === 'released';
                    $rid = $db->run('INSERT INTO repairs (shop_id, code, track_token, customer_id, branch_id, device_type, brand, model, reported_symptoms, status, priority, assigned_to, findings, verified_diagnosis, repair_action, approval_state, qc_passed, source, external_ref, created_by, created_at, updated_at, completed_at, released_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$shopId, $code, 'imp' . bin2hex(random_bytes(10)), $cid, $g['branch']['id'], $g['device'], $g['brand'], $g['model'], $g['problem'], $g['status'], 'normal', $g['tech'],
                         $g['diagnosis'] ? "Recorded diagnosis: {$g['diagnosis']}." : null, $g['diagnosis'], $g['action'], $closed ? 'approved' : 'none', $closed ? 1 : null, 'import', $g['ref'], $u['id'],
                         $g['created'], $g['closed'], $closed ? $g['closed'] : null, $closed ? $g['closed'] : null]);
                    if ($closed) {
                        $rest = round($g['total'] - $g['parts'] - $g['labor'], 2);
                        if ($g['parts'] > 0) $db->run('INSERT INTO repair_items (repair_id, kind, description, amount, created_at) VALUES (?,?,?,?,?)', [$rid, 'part', 'Parts', $g['parts'], $g['created']]);
                        if ($g['labor'] > 0) $db->run('INSERT INTO repair_items (repair_id, kind, description, amount, created_at) VALUES (?,?,?,?,?)', [$rid, 'labor', 'Labor / service', $g['labor'], $g['created']]);
                        if ($rest > 0.009) $db->run('INSERT INTO repair_items (repair_id, kind, description, amount, created_at) VALUES (?,?,?,?,?)', [$rid, 'other', $g['parts'] + $g['labor'] > 0 ? 'Other charges' : 'Repair service (imported)', $rest, $g['created']]);
                        if ($g['paid'] > 0) $db->run('INSERT INTO payments (repair_id, amount, method, received_by, created_at) VALUES (?,?,?,?,?)', [$rid, $g['paid'], $g['method'], $u['id'], $g['closed']]);
                    }
                    $db->run('INSERT INTO repair_events (repair_id, from_status, to_status, actor_id, actor_label, note, visible_to_customer, created_at) VALUES (?,?,?,?,?,?,0,?)', [$rid, null, 'booked', $u['id'], 'Imported record', 'Imported from the shop’s past records', $g['created']]);
                    $db->run('INSERT INTO repair_events (repair_id, from_status, to_status, actor_id, actor_label, note, visible_to_customer, created_at) VALUES (?,?,?,?,?,?,0,?)', [$rid, $closed ? 'completed' : 'booked', $g['status'], $u['id'], 'Imported record', 'Imported from the shop’s past records', $g['closed']]);
                    if ($addToKb && $g['diagnosis'] && mb_strlen($g['problem']) >= 8) {
                        $dg = $g['diagnosis']; $lk = mb_strtolower($dg, 'UTF-8');
                        $dg = $labels[$lk] ?? $dg; $labels[$lk] = $dg;
                        $key = mb_strtolower($g['problem'], 'UTF-8') . '|' . mb_strtolower($dg, 'UTF-8');
                        if (!isset($existing[$key]) && $g['status'] === 'released') {
                            $existing[$key] = true;
                            $db->run("INSERT INTO cases (shop_id, device_type, brand, model_hint, symptoms, diagnosis, repair_action, cost_min, cost_max, status, source, repair_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,'approved','import',?,?,?)",
                                [$shopId, $g['device'], mb_substr((string)$g['brand'], 0, 40) ?: null, mb_substr((string)$g['model'], 0, 60) ?: null, $g['problem'], $dg, $g['action'], $g['total'], $g['total'], $rid, $u['id'], $now]);
                            $kb++;
                        }
                    }
                }
                return $kb;
            });
            if ($kbAdded) $this->reindex();
            $this->audit($c, 'repairs.import', 'repair', null, count($good) . " past repairs imported, $dups duplicates skipped, " . count($errors) . " rows rejected, $kbAdded added to the AI knowledge base");
        }
        return [
            'dry_run' => $dry, 'total_rows' => count($table) - 1, 'valid' => count($good), 'duplicates' => $dups, 'rejected' => count($errors),
            'total_sales' => round($sales, 2), 'from' => $minD, 'to' => $maxD, 'by_branch' => array_values($byBranch),
            'with_diagnosis' => count(array_filter($good, fn($g) => $g['diagnosis'] && $g['status'] === 'released')),
            'errors' => array_slice($errors, 0, 50), 'imported' => $dry ? 0 : count($good), 'kb_added' => $kbAdded,
        ];
    }
    /** Platform administrator: reviews new shop registrations before they can take bookings. */
    private function routesPlatform(): void
    {
        $db = $this->db;
        $this->route('GET', '/api/platform/shops', function (Ctx $c) use ($db) {
            $this->need($c, 'platform.review');
            $status = (string)($c->query['status'] ?? 'pending');
            $where = ''; $p = [];
            if ($status !== 'all') { $where = 'WHERE s.status=?'; $p[] = V::oneOf($status, 'Status', ['pending', 'active', 'rejected', 'suspended']); }
            $rows = $db->all("SELECT s.id, s.name, s.slug, s.city, s.phone, s.email, s.status, s.review_note, s.reviewed_at, s.created_at,
                (SELECT u.name FROM users u WHERE u.shop_id=s.id AND u.role='admin' ORDER BY u.id LIMIT 1) AS admin_name,
                (SELECT b.address FROM branches b WHERE b.shop_id=s.id ORDER BY b.id LIMIT 1) AS address,
                (SELECT COUNT(*) FROM repairs r WHERE r.shop_id=s.id) AS repairs
                FROM shops s $where ORDER BY s.created_at DESC, s.id DESC LIMIT 200", $p);
            $counts = ['pending' => 0, 'active' => 0, 'rejected' => 0, 'suspended' => 0];
            foreach ($db->all('SELECT status, COUNT(*) AS n FROM shops GROUP BY status') as $r) $counts[$r['status']] = (int)$r['n'];
            return ['shops' => array_map(function ($r) { $r['id'] = (int)$r['id']; $r['repairs'] = (int)$r['repairs']; return $r; }, $rows), 'counts' => $counts];
        });
        $this->route('POST', '/api/platform/shops/:id/review', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'platform.review');
            $shop = $db->get('SELECT * FROM shops WHERE id=?', [(int)$c->params['id']]);
            if (!$shop) throw self::notFound('Shop not found.');
            $action = V::oneOf($c->body['action'] ?? null, 'Action', ['approve', 'reject', 'suspend']);
            $note = V::str($c->body['note'] ?? null, 'Note', 0, 250);
            $from = $shop['status'];
            if ($action === 'approve') {
                if (!in_array($from, ['pending', 'rejected', 'suspended'], true)) throw V::bad('This shop is already approved.');
                $to = 'active';
            } elseif ($action === 'reject') {
                if ($from !== 'pending') throw V::bad('Only a pending registration can be rejected.');
                if ($note === null || trim($note) === '') throw V::bad('Please give a short reason so the shop knows what to fix.');
                $to = 'rejected';
            } else {
                if ($from !== 'active') throw V::bad('Only an approved shop can be suspended.');
                if ($note === null || trim($note) === '') throw V::bad('Please give the reason for suspending this shop.');
                $to = 'suspended';
            }
            $db->run('UPDATE shops SET status=?, listed=?, review_note=?, reviewed_at=?, reviewed_by=? WHERE id=?', [$to, $to === 'active' ? 1 : (int)$shop['listed'], $note !== null && trim($note) !== '' ? trim($note) : null, $this->now(), $u['id'], $shop['id']]);
            $c->shopId = (int)$shop['id'];
            $this->audit($c, "shop.$action", 'shop', $shop['id'], "{$shop['name']}: $from -> $to" . ($note ? " ($note)" : ''));
            return ['ok' => true, 'id' => (int)$shop['id'], 'status' => $to];
        });
    }

    private function routesAdmin(): void
    {
        $db = $this->db;
        // A shop administrator may create staff accounts, never customer or platform accounts.
        $roles = array_values(array_filter(Auth::ROLES, fn($x) => !in_array($x, ['customer', 'platform'], true)));
        $this->route('GET', '/api/users', function (Ctx $c) use ($db) {
            $u = $this->needUser($c);
            if (!Auth::can($u, 'user.manage') && !Auth::can($u, 'user.view') && !Auth::can($u, 'repair.assign')) throw self::forbidden();
            $rows = array_map(function ($x) { $x['id'] = (int)$x['id']; $x['active'] = (int)$x['active']; $x['branch_id'] = $x['branch_id'] !== null ? (int)$x['branch_id'] : null; return $x; },
                $db->all('SELECT id,name,email,phone,role,branch_id,active,created_at FROM users WHERE shop_id=? ORDER BY role, name', [$u['shop_id']]));
            if (Auth::can($u, 'user.manage') || Auth::can($u, 'user.view')) return $rows;
            return array_values(array_map(fn($x) => ['id' => $x['id'], 'name' => $x['name'], 'role' => $x['role']], array_filter($rows, fn($x) => $x['role'] === 'technician' && $x['active'])));
        });
        $this->route('POST', '/api/users', function (Ctx $c) use ($db, $roles) {
            $admin = $this->need($c, 'user.manage');
            $b = $c->body;
            $role = V::oneOf($b['role'] ?? null, 'Role', $roles);
            if (!empty($b['branch_id']) && !$db->get('SELECT id FROM branches WHERE id=? AND shop_id=?', [V::intId($b['branch_id']), $admin['shop_id']])) throw V::bad('Choose a branch of your shop.');
            if ($err = Auth::passwordProblem($b['password'] ?? null)) throw V::bad($err);
            $em = V::email($b['email'] ?? null, true);
            if ($db->get('SELECT id FROM users WHERE email=?', [$em])) throw V::bad('Email already in use.');
            $id = $db->run('INSERT INTO users (shop_id,name,email,phone,role,password_hash,branch_id,created_at) VALUES (?,?,?,?,?,?,?,?)',
                [$admin['shop_id'], V::str($b['name'] ?? null, 'Name', 2, 80, true), $em, V::phone($b['phone'] ?? null), $role, Auth::hashPassword($b['password']), !empty($b['branch_id']) ? V::intId($b['branch_id']) : null, $this->now()]);
            $this->audit($c, 'user.create', 'user', $id, "$em as $role");
            return ['id' => $id];
        });
        $this->route('PATCH', '/api/users/:id', function (Ctx $c) use ($db, $roles) {
            $admin = $this->need($c, 'user.manage');
            $id = V::intId($c->params['id']);
            $t = $db->get('SELECT * FROM users WHERE id=? AND shop_id=?', [$id, $admin['shop_id']]);
            if (!$t) throw self::notFound();
            $b = $c->body;
            $sets = [];
            $vals = [];
            if (array_key_exists('active', $b)) {
                if ($id === (int)$admin['id'] && !$b['active']) throw V::bad('You cannot deactivate your own account.');
                $sets[] = 'active=?'; $vals[] = $b['active'] ? 1 : 0;
            }
            if (array_key_exists('role', $b)) {
                $role = V::oneOf($b['role'], 'Role', $roles);
                if ($id === (int)$admin['id'] && $role !== 'admin') throw V::bad('You cannot change your own role.');
                $sets[] = 'role=?'; $vals[] = $role;
            }
            if (array_key_exists('branch_id', $b)) {
                if (!empty($b['branch_id']) && !$db->get('SELECT id FROM branches WHERE id=? AND shop_id=?', [V::intId($b['branch_id']), $admin['shop_id']])) throw V::bad('Choose a branch of your shop.');
                $sets[] = 'branch_id=?'; $vals[] = !empty($b['branch_id']) ? V::intId($b['branch_id']) : null;
            }
            if (!empty($b['password'])) {
                if ($e = Auth::passwordProblem($b['password'])) throw V::bad($e);
                $sets[] = 'password_hash=?'; $vals[] = Auth::hashPassword($b['password']);
            }
            if (!$sets) throw V::bad('Nothing to update.');
            $db->run('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id=?', [...$vals, $id]);
            $this->audit($c, 'user.update', 'user', $id, implode(',', array_filter(array_keys($b), fn($k) => $k !== 'password')) . (!empty($b['password']) ? ',password' : ''));
            return ['ok' => true];
        });

        // --- data privacy: right to erasure (anonymise customer) ---
        $this->route('GET', '/api/customers', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'customer.erase');
            $like = '%' . mb_substr((string)($c->query['q'] ?? ''), 0, 60) . '%';
            return $db->all("SELECT c.id, c.name, c.phone, c.email, c.consent_at, c.anonymized_at, (SELECT COUNT(*) FROM repairs r WHERE r.customer_id=c.id) AS repairs,
                (SELECT COUNT(*) FROM repairs r WHERE r.customer_id=c.id AND r.status NOT IN ('released','cancelled')) AS active_repairs FROM customers c WHERE c.shop_id=? AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?) ORDER BY c.id DESC LIMIT 100", [$u['shop_id'], $like, $like, $like]);
        });
        $this->route('POST', '/api/customers/:id/anonymize', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'customer.erase');
            $cu = $db->get('SELECT * FROM customers WHERE id=? AND shop_id=?', [V::intId($c->params['id']), $u['shop_id']]);
            if (!$cu) throw self::notFound();
            $open = (int)$db->val("SELECT COUNT(*) FROM repairs WHERE customer_id=? AND status NOT IN ('released','cancelled')", [$cu['id']]);
            if ($open > 0) throw V::bad('This customer still has open repairs. Close them before erasing personal data.');
            $db->tx(function () use ($db, $cu) {
                $db->run("UPDATE customers SET name='Anonymized Customer', phone=NULL, email=NULL, anonymized_at=? WHERE id=?", [$this->now(), $cu['id']]);
                $db->run('UPDATE repairs SET serial_no=NULL, condition_notes=NULL WHERE customer_id=?', [$cu['id']]);
                $db->run('DELETE FROM notifications WHERE repair_id IN (SELECT id FROM repairs WHERE customer_id=?)', [$cu['id']]);
                // The customer's own sign-in account is theirs (it may be used at other shops), so it is not touched here.
            });
            $this->audit($c, 'customer.erase', 'customer', $cu['id'], 'personal data anonymised on request');
            return ['ok' => true];
        });

        $this->route('GET', '/api/audit', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'audit.view');
            $limit = max(1, min(V::intId($c->query['limit'] ?? 0) ?: 100, 500));
            return $db->all("SELECT * FROM audit_logs WHERE shop_id=? ORDER BY id DESC LIMIT $limit", [$u['shop_id']]);
        });
    }

    // ---- sales report: payments collected, bucketed by hour (today), day (month) or month (year) ----
    private function salesPeriod(string $range, ?string $at, int $tzMin): array
    {
        $p2 = fn($n) => str_pad((string)$n, 2, '0', STR_PAD_LEFT);
        $fmt = fn($t) => gmdate('Y-m-d H:i:s', $t);
        $ymd = fn($t) => gmdate('Y-m-d', $t);
        $nowL = time() + $tzMin * 60; // "local wall clock" expressed on a UTC calendar
        $today = gmmktime(0, 0, 0, (int)gmdate('n', $nowL), (int)gmdate('j', $nowL), (int)gmdate('Y', $nowL));
        $base = $today;
        if ($at && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $at, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[1] >= 2000 && (int)$m[1] <= 2100) {
            $base = gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
        }
        $Y = (int)gmdate('Y', $base); $M = (int)gmdate('n', $base); $D = (int)gmdate('j', $base);
        if ($range === 'today') {
            $start = gmmktime(0, 0, 0, $M, $D, $Y); $end = gmmktime(0, 0, 0, $M, $D + 1, $Y); $prevStart = gmmktime(0, 0, 0, $M, $D - 1, $Y);
            $label = gmdate('D, j M Y', $start);
            $buckets = array_map(fn($h) => ['label' => $p2($h), 'total' => 0.0, 'count' => 0], range(0, 23));
        } elseif ($range === 'month') {
            $start = gmmktime(0, 0, 0, $M, 1, $Y); $end = gmmktime(0, 0, 0, $M + 1, 1, $Y); $prevStart = gmmktime(0, 0, 0, $M - 1, 1, $Y);
            $label = gmdate('F', $start) . " $Y";
            $buckets = array_map(fn($i) => ['label' => (string)($i + 1), 'total' => 0.0, 'count' => 0], range(0, (int)round(($end - $start) / 86400) - 1));
        } else {
            $start = gmmktime(0, 0, 0, 1, 1, $Y); $end = gmmktime(0, 0, 0, 1, 1, $Y + 1); $prevStart = gmmktime(0, 0, 0, 1, 1, $Y - 1);
            $label = (string)$Y;
            $buckets = array_map(fn($mm) => ['label' => $mm, 'total' => 0.0, 'count' => 0], self::MONTHS);
        }
        $prevAt = $ymd($prevStart);
        $nextAt = $end <= $today ? $ymd($end) : null;
        // An unfinished period (e.g. this month so far) is compared with the same point of the previous period, not its full length.
        $current = $nowL >= $start && $nowL < $end;
        $prevEnd = $current ? min($start, $prevStart + ($nowL - $start)) : $start;
        $idx = fn(string $local) => $range === 'today' ? (int)substr($local, 11, 2) : ($range === 'month' ? (int)substr($local, 8, 2) - 1 : (int)substr($local, 5, 2) - 1);
        return ['start' => $start, 'end' => $end, 'prevStart' => $prevStart, 'prevEnd' => $prevEnd, 'comparison' => $current ? 'same_point' : 'full',
                'prevAt' => $prevAt, 'nextAt' => $nextAt, 'label' => $label, 'buckets' => $buckets, 'idx' => $idx, 'at' => $ymd($base), 'fmt' => $fmt];
    }

    private function salesData(array $u, array $q): array
    {
        $range = in_array($q['range'] ?? null, ['today', 'month', 'year'], true) ? $q['range'] : 'month';
        $tz = isset($q['tz']) && preg_match('/^\s*[+-]?\d+/', (string)$q['tz'], $mm) ? (int)$q['tz'] : null;
        if ($tz === null || $tz < -720 || $tz > 840) $tz = 480; // default UTC+8 (Philippines)
        $per = $this->salesPeriod($range, isset($q['at']) ? (string)$q['at'] : null, $tz);
        $fmt = $per['fmt'];
        // Period bounds are converted to UTC so the query can use the index on created_at and stays portable (MySQL / SQLite).
        $utc = fn($t) => $fmt($t - $tz * 60);
        $rows = $this->db->all('SELECT p.id, p.amount, p.method, p.created_at, r.code, c.name AS customer_name, b.name AS branch, u.name AS received_by_name
            FROM payments p JOIN repairs r ON r.id=p.repair_id LEFT JOIN customers c ON c.id=r.customer_id LEFT JOIN branches b ON b.id=r.branch_id LEFT JOIN users u ON u.id=p.received_by
            WHERE r.shop_id=? AND p.created_at >= ? AND p.created_at < ? ORDER BY p.created_at DESC, p.id DESC', [$u['shop_id'], $utc($per['start']), $utc($per['end'])]);
        $buckets = $per['buckets'];
        $total = 0.0;
        $byMethod = [];
        $byBranch = [];
        foreach ($rows as &$r) {
            $r['amount'] = (float)$r['amount'];
            $r['id'] = (int)$r['id'];
            $r['local_at'] = $fmt(strtotime($r['created_at'] . ' UTC') + $tz * 60);
            unset($r['created_at']);
            $total += $r['amount'];
            $i = $per['idx']($r['local_at']);
            if (isset($buckets[$i])) { $buckets[$i]['total'] += $r['amount']; $buckets[$i]['count']++; }
            $m = $r['method'];
            $byMethod[$m] ??= ['method' => $m, 'total' => 0.0, 'count' => 0];
            $byMethod[$m]['total'] += $r['amount']; $byMethod[$m]['count']++;
            $bn = $r['branch'] ?: 'Unassigned';
            $byBranch[$bn] ??= ['name' => $bn, 'total' => 0.0, 'count' => 0];
            $byBranch[$bn]['total'] += $r['amount']; $byBranch[$bn]['count']++;
        }
        unset($r);
        $prev = (float)$this->db->val('SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN repairs r ON r.id=p.repair_id WHERE r.shop_id=? AND p.created_at >= ? AND p.created_at < ?',
            [$u['shop_id'], $utc($per['prevStart']), $utc($per['prevEnd'])]);
        $n = count($rows);
        $sortDesc = function (array $a) { $a = array_values($a); usort($a, fn($x, $y) => $y['total'] <=> $x['total']); return $a; };
        return [
            'range' => $range, 'at' => $per['at'], 'label' => $per['label'], 'prev_at' => $per['prevAt'], 'next_at' => $per['nextAt'], 'tz' => $tz,
            'buckets' => array_map(fn($b) => ['label' => $b['label'], 'total' => self::r2($b['total']), 'count' => $b['count']], $buckets),
            'totals' => ['comparison' => $per['comparison'], 'total' => self::r2($total), 'count' => $n, 'average' => $n ? self::r2($total / $n) : 0, 'previous_total' => self::r2($prev),
                         'change_pct' => $prev > 0 ? floor((($total - $prev) / $prev) * 1000 + 0.5) / 10 : null],
            'by_method' => $sortDesc(array_map(fn($m) => ['total' => self::r2($m['total'])] + $m, $byMethod)),
            'by_branch' => $sortDesc(array_map(fn($b) => ['total' => self::r2($b['total'])] + $b, $byBranch)),
            'log' => array_slice($rows, 0, 300), 'log_truncated' => $n > 300,
            '_rows' => $rows,
        ];
    }

    private static function csvCell(mixed $v): string
    {
        $t = (string)($v ?? '');
        if (preg_match('/^[=+\-@\t\r]/', $t)) $t = "'" . $t; // neutralise spreadsheet formulas
        return preg_match('/[",\n\r]/', $t) ? '"' . str_replace('"', '""', $t) . '"' : $t;
    }

    private function routesReports(): void
    {
        $db = $this->db;
        $this->route('GET', '/api/reports/summary', function (Ctx $c) use ($db) {
            $u = $this->need($c, 'report.view');
            $sid = $u['shop_id'];
            $byStatus = array_fill_keys(Workflow::STATUSES, 0);
            foreach ($db->all('SELECT status, COUNT(*) AS n FROM repairs WHERE shop_id=? GROUP BY status', [$sid]) as $r) $byStatus[$r['status']] = (int)$r['n'];
            // Average hours from booking to release (computed here rather than in SQL so it is identical on MySQL and SQLite).
            $hrs = [];
            foreach ($db->all('SELECT created_at, released_at FROM repairs WHERE shop_id=? AND released_at IS NOT NULL', [$sid]) as $r) {
                $hrs[] = (strtotime($r['released_at'] . ' UTC') - strtotime($r['created_at'] . ' UTC')) / 3600;
            }
            $turnaround = $hrs ? array_sum($hrs) / count($hrs) : null;
            $ai = $db->get('SELECT COUNT(*) AS runs, SUM(CASE WHEN a.feedback_accepted IS NOT NULL THEN 1 ELSE 0 END) AS reviewed, SUM(CASE WHEN a.feedback_accepted=1 THEN 1 ELSE 0 END) AS accepted,
                SUM(CASE WHEN a.feedback_rank=1 THEN 1 ELSE 0 END) AS top1, SUM(CASE WHEN a.feedback_rank<=3 THEN 1 ELSE 0 END) AS top3 FROM ai_runs a JOIN repairs r ON r.id=a.repair_id WHERE r.shop_id=?', [$sid]);
            $int = fn($v) => (int)($v ?? 0);
            $cnt = fn($sql) => (int)$db->val($sql, [$sid]);
            $rows = fn($sql) => array_map(fn($r) => array_map(fn($v) => is_numeric($v) && !is_float($v) && preg_match('/^\d+$/', (string)$v) ? (int)$v : $v, $r), $db->all($sql, [$sid]));
            return [
                'totals' => ['repairs' => $cnt('SELECT COUNT(*) FROM repairs WHERE shop_id=?'), 'active' => $cnt("SELECT COUNT(*) FROM repairs WHERE shop_id=? AND status NOT IN ('released','cancelled')"), 'customers' => $cnt('SELECT COUNT(*) FROM customers WHERE shop_id=? AND anonymized_at IS NULL')],
                'by_status' => $byStatus,
                'by_branch' => array_map(fn($r) => ['name' => $r['name'], 'n' => (int)$r['n']], $db->all('SELECT b.name, COUNT(r.id) AS n FROM branches b LEFT JOIN repairs r ON r.branch_id=b.id WHERE b.shop_id=? GROUP BY b.id, b.name ORDER BY b.id', [$sid])),
                'by_device' => array_map(fn($r) => ['device_type' => $r['device_type'], 'n' => (int)$r['n']], $db->all('SELECT device_type, COUNT(*) AS n FROM repairs WHERE shop_id=? GROUP BY device_type ORDER BY n DESC, device_type', [$sid])),
                'top_diagnoses' => array_map(fn($r) => ['diagnosis' => $r['diagnosis'], 'n' => (int)$r['n']], $db->all('SELECT verified_diagnosis AS diagnosis, COUNT(*) AS n FROM repairs WHERE shop_id=? AND verified_diagnosis IS NOT NULL GROUP BY verified_diagnosis ORDER BY n DESC, verified_diagnosis LIMIT 8', [$sid])),
                'avg_turnaround_hours' => $turnaround === null ? null : floor($turnaround * 10 + 0.5) / 10,
                'overdue' => (int)$db->val("SELECT COUNT(*) FROM repairs WHERE shop_id=? AND est_ready_date IS NOT NULL AND est_ready_date < ? AND status NOT IN ('completed','released','cancelled')", [$sid, gmdate('Y-m-d')]),
                'revenue_collected' => (float)$db->val('SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN repairs r ON r.id=p.repair_id WHERE r.shop_id=?', [$sid]),
                'technician_load' => array_map(fn($r) => ['name' => $r['name'], 'active' => (int)$r['active']], $db->all("SELECT u.name, COUNT(r.id) AS active FROM users u LEFT JOIN repairs r ON r.assigned_to=u.id AND r.status NOT IN ('completed','released','cancelled') WHERE u.shop_id=? AND u.role='technician' AND u.active=1 GROUP BY u.id, u.name ORDER BY active DESC, u.name", [$sid])),
                'ai' => ['runs' => $int($ai['runs']), 'reviewed' => $int($ai['reviewed']), 'accepted' => $int($ai['accepted']), 'top1_accepted' => $int($ai['top1']), 'top3_accepted' => $int($ai['top3']), 'kb_cases' => $this->indexFor((int)$sid)->N, 'mode' => $this->llm->enabled() ? 'llm' : 'local'],
            ];
        });

        $this->route('GET', '/api/reports/sales', function (Ctx $c) {
            $u = $this->need($c, 'report.view');
            $out = $this->salesData($u, $c->query);
            unset($out['_rows']);
            return $out;
        });

        // CSV download of the same report. Every export is written to the audit log.
        $this->route('GET', '/api/reports/sales.csv', function (Ctx $c) {
            $u = $this->need($c, 'report.view');
            $d = $this->salesData($u, $c->query);
            $head = ['Date/time (local)', 'Repair code', 'Customer', 'Branch', 'Method', 'Amount (PHP)', 'Received by'];
            $lines = [implode(',', array_map([self::class, 'csvCell'], $head))];
            foreach ($d['_rows'] as $r) {
                $lines[] = implode(',', array_map([self::class, 'csvCell'], [$r['local_at'], $r['code'], $r['customer_name'], $r['branch'], $r['method'], number_format($r['amount'], 2, '.', ''), $r['received_by_name']]));
            }
            $lines[] = '';
            $lines[] = implode(',', array_map([self::class, 'csvCell'], ['Total', '', '', '', '', number_format($d['totals']['total'], 2, '.', ''), '']));
            $this->audit($c, 'report.export', 'report', null, sprintf('sales %s %s: %d payments, PHP %s', $d['range'], $d['label'], $d['totals']['count'], number_format($d['totals']['total'], 2, '.', '')));
            return ['__raw' => ['type' => 'text/csv; charset=utf-8', 'filename' => "sales-{$d['range']}-{$d['at']}.csv", 'body' => "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n"]];
        });
    }
}
