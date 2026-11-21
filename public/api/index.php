<?php
declare(strict_types=1);

// Front controller: every /api/... request lands here (see public/.htaccess).
require __DIR__ . '/../../src/bootstrap.php';

function send(array $res): never
{
    http_response_code($res['status']);
    foreach ($res['headers'] as $k => $v) header("$k: $v");
    echo $res['body'];
    exit;
}

$headers = [];
foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Take everything from "/api/" onwards, whatever folder the site lives in (also tolerates /api/index.php/health).
$pos = strpos($uri, '/api/');
$path = $pos === false ? '/api/' : preg_replace('#^/api/index\.php#', '/api', substr($uri, $pos));

try {
    $app = repairsync_app();
    $res = $app->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $_GET, (string)file_get_contents('php://input'), $_COOKIE, $headers, $_SERVER['REMOTE_ADDR'] ?? '');
} catch (PDOException $e) {
    error_log('[repairsync] database error: ' . $e->getMessage());
    $msg = 'The database is not reachable or not set up yet. Start MySQL in XAMPP, then open setup.php once (see README).';
    $res = ['status' => 503, 'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'], 'body' => json_encode(['error' => $msg, 'setup_needed' => true])];
}
send($res);
