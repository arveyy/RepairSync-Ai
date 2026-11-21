<?php
declare(strict_types=1);

namespace RepairSync;

/** Authentication (password_hash, HMAC-signed session tokens), RBAC matrix and the DB-backed rate limiter. */
final class Auth
{
    public const ROLES = ['customer', 'frontdesk', 'technician', 'admin', 'owner', 'platform'];
    public const STAFF = ['frontdesk', 'technician', 'admin', 'owner'];
    /** Platform administrator: reviews shop registrations. Not tied to any shop and cannot see repair data. */

    /** Permission matrix (role => permissions). Single source of truth for RBAC. */
    public const PERMISSIONS = [
        'customer'   => ['repair.view_own', 'repair.book', 'repair.approve_quote'],
        'frontdesk'  => ['repair.view_all', 'repair.view_pii', 'repair.create', 'repair.update', 'repair.assign', 'repair.quote', 'repair.pay', 'repair.release', 'repair.cancel', 'repair.receive', 'repair.approve_quote', 'ai.run', 'kb.view'],
        'technician' => ['repair.view_all', 'repair.update', 'repair.diagnose', 'repair.quote', 'repair.work', 'ai.run', 'ai.feedback', 'kb.view', 'kb.submit'],
        'admin'      => ['repair.view_all', 'repair.view_pii', 'repair.create', 'repair.update', 'repair.assign', 'repair.quote', 'repair.pay', 'repair.release', 'repair.cancel', 'repair.receive', 'repair.diagnose', 'repair.work', 'repair.approve_quote', 'ai.run', 'ai.feedback', 'kb.view', 'kb.submit', 'kb.manage', 'user.manage', 'audit.view', 'report.view', 'customer.erase', 'shop.manage', 'shop.branding', 'data.import'],
        'platform'   => ['platform.review'],
        'owner'      => ['repair.view_all', 'repair.view_pii', 'repair.cancel', 'kb.view', 'kb.manage', 'audit.view', 'report.view', 'user.view', 'shop.branding'],
    ];

    public static function can(?array $user, string $perm): bool
    {
        return $user !== null && in_array($perm, self::PERMISSIONS[$user['role']] ?? [], true);
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $password, string $stored): bool
    {
        return password_verify($password, $stored);
    }

    public static function passwordProblem(mixed $pw): ?string
    {
        if (!is_string($pw) || strlen($pw) < 8) return 'Password must be at least 8 characters.';
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) return 'Password must contain letters and numbers.';
        return null;
    }

    public static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    public static function unb64(string $s): string|false { return base64_decode(strtr($s, '-_', '+/'), true); }
}

/** HMAC-signed session token: base64url(json{uid,exp}).signature */
final class Signer
{
    public function __construct(private string $secret) {}

    private function sign(string $data): string { return Auth::b64(hash_hmac('sha256', $data, $this->secret, true)); }

    public function issue(int $uid, int $ttl = 8 * 3600): string
    {
        $payload = Auth::b64(json_encode(['uid' => $uid, 'exp' => time() + $ttl]));
        return $payload . '.' . $this->sign($payload);
    }

    public function verify(?string $token): ?array
    {
        if (!$token || !is_string($token)) return null;
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') return null;
        if (!hash_equals($this->sign($parts[0]), $parts[1])) return null;
        $raw = Auth::unb64($parts[0]);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !isset($data['exp'], $data['uid'])) return null;
        return $data['exp'] > time() ? $data : null;
    }
}

/** Fixed-window rate limiter stored in the database (PHP keeps no state between requests). */
final class Limiter
{
    public function __construct(private Db $db) {}

    /** Returns true while the caller is within the limit. */
    public function hit(string $key, int $windowSec, int $max): bool
    {
        return (bool)$this->db->tx(function () use ($key, $windowSec, $max) {
            $now = time();
            $rec = $this->db->get('SELECT window_start, n FROM rate_limits WHERE k = ?', [$key]);
            if (!$rec || $now - (int)$rec['window_start'] > $windowSec) {
                if ($rec) $this->db->run('UPDATE rate_limits SET window_start = ?, n = 1 WHERE k = ?', [$now, $key]);
                else $this->db->run('INSERT INTO rate_limits (k, window_start, n) VALUES (?, ?, 1)', [$key, $now]);
                return true;
            }
            $n = (int)$rec['n'] + 1;
            $this->db->run('UPDATE rate_limits SET n = ? WHERE k = ?', [$n, $key]);
            return $n <= $max;
        });
    }

    /** Housekeeping: forget old counters. */
    public function sweep(int $olderThanSec = 86400): void
    {
        $this->db->run('DELETE FROM rate_limits WHERE window_start < ?', [time() - $olderThanSec]);
    }
}
