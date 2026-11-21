<?php
declare(strict_types=1);

namespace RepairSync;

use PDO;

/**
 * Thin PDO wrapper. MySQL (XAMPP) is the production driver; SQLite is supported so the whole system can
 * run and be tested without a database server. All SQL used by the app is portable between the two.
 */
final class Db
{
    public PDO $pdo;
    public string $driver;
    private int $depth = 0;

    public function __construct(array $cfg)
    {
        $this->driver = $cfg['driver'] ?? 'mysql';
        if ($this->driver === 'sqlite') {
            $path = $cfg['sqlite_path'];
            if ($path !== ':memory:') @mkdir(dirname($path), 0775, true);
            $this->pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
            if ($path !== ':memory:') $this->pdo->exec('PRAGMA journal_mode = WAL');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['name']);
            $this->pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->exec("SET time_zone = '+00:00'"); // all timestamps stored in UTC
        }
    }

    public function exec(string $sql): void { $this->pdo->exec($sql); }

    private function stmt(string $sql, array $params): \PDOStatement
    {
        $s = $this->pdo->prepare($sql);
        $s->execute(array_values($params));
        return $s;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(string $sql, array $params = []): array { return $this->stmt($sql, $params)->fetchAll(); }

    public function get(string $sql, array $params = []): ?array
    {
        $r = $this->stmt($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public function val(string $sql, array $params = []): mixed
    {
        $r = $this->stmt($sql, $params)->fetch(PDO::FETCH_NUM);
        return $r === false ? null : $r[0];
    }

    /** Runs a write; returns last insert id for INSERTs, affected rows otherwise. */
    public function run(string $sql, array $params = []): int
    {
        $s = $this->stmt($sql, $params);
        if (stripos(ltrim($sql), 'insert') === 0) return (int)$this->pdo->lastInsertId();
        return $s->rowCount();
    }

    /** Nested-safe transaction. */
    public function tx(callable $fn): mixed
    {
        if ($this->depth > 0) return $fn();
        $this->pdo->beginTransaction();
        $this->depth++;
        try {
            $r = $fn();
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        } finally {
            $this->depth--;
        }
    }

    public function now(): string { return gmdate('Y-m-d H:i:s'); }

    /** Creates all tables from the schema file for the active driver (no-op when they already exist). */
    public function migrate(): void
    {
        $file = dirname(__DIR__) . '/database/' . ($this->driver === 'sqlite' ? 'schema.sqlite.sql' : 'schema.mysql.sql');
        $sql = file_get_contents($file);
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            // drop comment-only chunks
            $clean = trim(preg_replace('/^--.*$/m', '', $stmt));
            if ($clean === '' || preg_match('/^(CREATE DATABASE|USE)\b/i', $clean)) continue;
            $this->pdo->exec($clean);
        }
        $this->upgrade();
    }

    /** Brings a database created by an earlier version up to date (safe to run every time). */
    private function upgrade(): void
    {
        $sqlite = $this->driver === 'sqlite';
        $cols = [
            'review_note'   => $sqlite ? 'TEXT NULL' : 'VARCHAR(255) NULL',
            'reviewed_at'   => $sqlite ? 'TEXT NULL' : 'DATETIME NULL',
            'reviewed_by'   => $sqlite ? 'INTEGER NULL' : 'INT NULL',
            'logo_path'     => $sqlite ? 'TEXT NULL' : 'VARCHAR(255) NULL',
            'accent_color'  => $sqlite ? 'TEXT NULL' : 'VARCHAR(16) NULL',
            'address'       => $sqlite ? 'TEXT NULL' : 'VARCHAR(255) NULL',
            'lat'           => $sqlite ? 'REAL NULL' : 'DECIMAL(10,7) NULL',
            'lng'           => $sqlite ? 'REAL NULL' : 'DECIMAL(10,7) NULL',
            'opening_hours' => 'TEXT NULL',
        ];
        foreach ($cols as $col => $def) {
            if (!$this->hasColumn('shops', $col)) $this->pdo->exec("ALTER TABLE shops ADD COLUMN $col $def");
        }
        foreach (['phone' => $sqlite ? 'TEXT NULL' : 'VARCHAR(40) NULL', 'hours' => $sqlite ? 'TEXT NULL' : 'VARCHAR(120) NULL'] as $col => $def) {
            if (!$this->hasColumn('branches', $col)) $this->pdo->exec("ALTER TABLE branches ADD COLUMN $col $def");
        }
        if (!$this->hasColumn('repairs', 'external_ref')) $this->pdo->exec('ALTER TABLE repairs ADD COLUMN external_ref ' . ($sqlite ? 'TEXT NULL' : 'VARCHAR(60) NULL'));
        if (!$sqlite) {
            // Shop status and user role are now validated in PHP (new values: pending/rejected shops, platform role).
            foreach ([['shops', 'chk_shop_status'], ['users', 'chk_user_role']] as [$t, $n]) {
                foreach (["ALTER TABLE $t DROP CONSTRAINT $n", "ALTER TABLE $t DROP CHECK $n"] as $q) {
                    try { $this->pdo->exec($q); break; } catch (\Throwable) { /* constraint absent or syntax differs: try next form */ }
                }
            }
        }
    }

    private function hasColumn(string $table, string $col): bool
    {
        if ($this->driver === 'sqlite') {
            foreach ($this->pdo->query("PRAGMA table_info($table)")->fetchAll(\PDO::FETCH_ASSOC) as $r) if ($r['name'] === $col) return true;
            return false;
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    }
}
