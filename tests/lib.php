<?php
// Tiny dependency-free test harness (PHPUnit is not needed on XAMPP).
declare(strict_types=1);

final class T
{
    public static int $pass = 0, $fail = 0;
    public static array $failures = [];

    public static function test(string $name, callable $fn): void
    {
        try { $fn(); self::$pass++; echo "  ok   $name\n"; }
        catch (Throwable $e) { self::$fail++; self::$failures[] = $name; echo "  FAIL $name\n       " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n"; }
    }
    public static function eq(mixed $actual, mixed $expected, string $msg = ''): void
    {
        if ($actual !== $expected) throw new AssertionError(($msg ? "$msg: " : '') . 'expected ' . json_encode($expected) . ' got ' . json_encode($actual));
    }
    public static function ok(mixed $v, string $msg = 'expected truthy'): void { if (!$v) throw new AssertionError($msg); }
    public static function match(string $re, ?string $s, string $msg = ''): void
    {
        if ($s === null || !preg_match($re, $s)) throw new AssertionError(($msg ? "$msg: " : '') . "'" . ($s ?? 'null') . "' does not match $re");
    }
    public static function summary(): int
    {
        echo "\n" . self::$pass . ' passed, ' . self::$fail . " failed\n";
        return self::$fail ? 1 : 0;
    }
}

/** In-process HTTP client: calls App::handle() directly, keeps the session cookie like a browser. */
final class Client
{
    public string $cookie = '';
    public static ?\RepairSync\App $app = null;
    public static ?\RepairSync\Db $db = null;

    public function req(string $method, string $path, ?array $body = null, array $headers = [], ?string $raw = null): array
    {
        $u = parse_url($path);
        $query = [];
        parse_str($u['query'] ?? '', $query);
        $h = ['x-requested-with' => $method !== 'GET' ? 'RepairSync' : ''] + $headers;
        $h = array_filter($h, fn($v) => $v !== '' && $v !== null);
        $cookies = $this->cookie ? ['rs_session' => $this->cookie] : [];
        $res = self::$app->handle($method, $u['path'], $query, $raw ?? ($body !== null ? json_encode($body) : ''), $cookies, $h, '127.0.0.1');
        if (isset($res['headers']['Set-Cookie']) && preg_match('/^rs_session=([^;]*)/', $res['headers']['Set-Cookie'], $m)) $this->cookie = $m[1];
        $data = json_decode($res['body'], true);
        return ['status' => $res['status'], 'data' => is_array($data) ? $data : [], 'raw' => $res['body'], 'headers' => $res['headers']];
    }
    public function get(string $p): array { return $this->req('GET', $p); }
    public function post(string $p, array $b = []): array { return $this->req('POST', $p, $b === [] ? [] : $b); }
    public function patch(string $p, array $b = []): array { return $this->req('PATCH', $p, $b); }
    public function put(string $p, array $b = []): array { return $this->req('PUT', $p, $b); }
    public function login(string $u): self
    {
        $r = $this->post('/api/auth/login', ['email' => "$u@repairsync.test", 'password' => \RepairSync\Seeder::DEMO_PASSWORD]);
        T::eq($r['status'], 200, json_encode($r['data']));
        return $this;
    }
}
