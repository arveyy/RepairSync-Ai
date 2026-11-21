<?php
declare(strict_types=1);

namespace RepairSync;

final class HttpError extends \RuntimeException
{
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}

/** Per-request context handed to every route handler. */
final class Ctx
{
    public ?array $user = null;
    public array $params = [];
    public array $query = [];
    public array $body = [];
    public string $ip = '';
    public ?string $setCookie = null;
    public ?int $shopId = null;

    public function withUser(?array $u): self { $c = clone $this; $c->user = $u; return $c; }
}

/** Input validation helpers (same rules and messages as the Node version). */
final class V
{
    public static function bad(string $m): HttpError { return new HttpError(400, $m); }

    /** JS Number() for loose values: numeric strings/numbers convert, anything else is NaN (returned as null). */
    public static function num(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) return is_finite((float)$v) ? (float)$v : null;
        if (is_bool($v)) return $v ? 1.0 : 0.0;
        if (is_string($v)) { $t = trim($v); if ($t === '') return 0.0; return is_numeric($t) ? (float)$t : null; }
        return null;
    }

    public static function intId(mixed $v): int { $n = self::num($v); return $n === null ? 0 : (int)$n; }

    public static function str(mixed $v, string $name, int $min = 0, int $max = 500, bool $required = false): ?string
    {
        if ($v === null || $v === '') { if ($required) throw self::bad("$name is required."); return null; }
        if (!is_string($v)) throw self::bad("$name must be text.");
        $s = trim($v);
        $len = mb_strlen($s, 'UTF-8');
        if ($len < $min) throw self::bad("$name must be at least $min characters.");
        if ($len > $max) throw self::bad("$name must be at most $max characters.");
        if ($required && $s === '') throw self::bad("$name is required.");
        return $s === '' ? null : $s;
    }

    /** @param string[] $list */
    public static function oneOf(mixed $v, string $name, array $list, ?string $def = null): string
    {
        if ($v === null || $v === '') {
            if ($def !== null) return $def;
            throw self::bad("$name is required.");
        }
        if (!is_string($v) || !in_array($v, $list, true)) throw self::bad("$name must be one of: " . implode(', ', $list) . '.');
        return $v;
    }

    public static function money(mixed $v, string $name): float
    {
        $n = self::num($v);
        if ($n === null || $n < 0 || $n > 10_000_000) throw self::bad("$name must be a valid amount.");
        return round($n, 2);
    }

    public static function email(mixed $v, bool $required = false): ?string
    {
        $s = self::str($v, 'Email', 0, 120, $required);
        if ($s !== null && !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $s)) throw self::bad('Email address is not valid.');
        return $s !== null ? mb_strtolower($s, 'UTF-8') : null;
    }

    public static function phone(mixed $v, bool $required = false): ?string
    {
        $s = self::str($v, 'Phone', 0, 30, $required);
        if ($s !== null && !preg_match('/^[0-9+\-\s()]{7,20}$/', $s)) throw self::bad('Phone number is not valid.');
        return $s;
    }
}
