<?php
declare(strict_types=1);

use RepairSync\{App, Db};

require_once __DIR__ . '/autoload.php';
date_default_timezone_set('UTC');

const REPAIRSYNC_ROOT = __DIR__ . '/..';

function repairsync_config(): array
{
    return require REPAIRSYNC_ROOT . '/config.php';
}

/** Session-signing secret, generated once and kept in data/ so logins survive restarts. */
function repairsync_secret(array $cfg): string
{
    if (!empty($cfg['session_secret'])) return (string)$cfg['session_secret'];
    $dir = REPAIRSYNC_ROOT . '/data';
    $file = $dir . '/.session_secret';
    if (is_file($file)) return trim((string)file_get_contents($file));
    @mkdir($dir, 0775, true);
    $s = bin2hex(random_bytes(32));
    if (@file_put_contents($file, $s) === false) return hash('sha256', __FILE__ . ($cfg['pass'] ?? '') . ($cfg['name'] ?? '')); // read-only disk fallback
    @chmod($file, 0600);
    return $s;
}

/** Folder the site is served from, e.g. "/" or "/repairsync/public/" (used to build links). */
function repairsync_base_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/api/index.php');
    $dir = dirname(dirname($script)); // .../public/api/index.php -> .../public
    return rtrim($dir === '.' ? '' : $dir, '/') . '/';
}

function repairsync_app(?Db $db = null, ?array $cfg = null, ?string $basePath = null): App
{
    $cfg ??= repairsync_config();
    $db ??= new Db($cfg);
    return new App($db, $cfg, repairsync_secret($cfg), !empty($cfg['demo_data']), $basePath ?? repairsync_base_path());
}
