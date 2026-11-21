<?php
/**
 * RepairSync AI configuration.
 * Defaults match a fresh XAMPP install (MySQL user "root", empty password, database "repairsync").
 * To change anything, copy this file to config.local.php and edit the values there - or set the
 * REPAIRSYNC_* environment variables. config.local.php is never overwritten and should not be committed.
 */
$cfg = [
    'driver'      => 'mysql',                         // 'mysql' (XAMPP) or 'sqlite' (no database server needed)
    'host'        => '127.0.0.1',
    'port'        => 3306,
    'name'        => 'repairsync',
    'user'        => 'root',
    'pass'        => '',
    'sqlite_path' => __DIR__ . '/data/repairsync.sqlite',
    'upload_dir'  => __DIR__ . '/public/uploads',   // per-shop logos; served from /uploads/
    'platform_admin' => null,                         // e.g. ['name' => 'Your Name', 'email' => 'you@example.com', 'password' => 'a-strong-password'] in config.local.php
    'demo_data'   => true,                            // seed demo shops/users/repairs on first setup
    'anthropic_api_key' => '',                        // optional: lets Claude rewrite the AI summary (falls back offline)
    'anthropic_model'   => 'claude-sonnet-5-5',
    'secure_cookie'     => false,                     // set true when served over HTTPS
];

if (is_file(__DIR__ . '/config.local.php')) {
    $cfg = array_replace($cfg, (array)require __DIR__ . '/config.local.php');
}
$env = ['REPAIRSYNC_DB_DRIVER' => 'driver', 'REPAIRSYNC_DB_HOST' => 'host', 'REPAIRSYNC_DB_PORT' => 'port', 'REPAIRSYNC_DB_NAME' => 'name',
        'REPAIRSYNC_DB_USER' => 'user', 'REPAIRSYNC_DB_PASS' => 'pass', 'REPAIRSYNC_SQLITE' => 'sqlite_path', 'ANTHROPIC_API_KEY' => 'anthropic_api_key'];
foreach ($env as $e => $k) {
    $v = getenv($e);
    if ($v !== false && $v !== '') $cfg[$k] = $k === 'port' ? (int)$v : $v;
}
return $cfg;
