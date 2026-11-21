<?php
// Router for PHP's built-in server (no Apache needed):  php -S localhost:8000 router.php
// Serves public/ as the site and sends /api/... to the front controller.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($uri, '/api/')) { $_SERVER['SCRIPT_NAME'] = '/api/index.php'; require __DIR__ . '/public/api/index.php'; return true; }
if ($uri === '/setup.php') { require __DIR__ . '/setup.php'; return true; }
$file = realpath(__DIR__ . '/public' . $uri);
if ($uri === '/' || $file === false || !is_file($file) || !str_starts_with($file, realpath(__DIR__ . '/public'))) { $file = __DIR__ . '/public/index.html'; }
$types = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'json' => 'application/json'];
header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://tile.openstreetmap.org; connect-src 'self' https://router.project-osrm.org https://nominatim.openstreetmap.org; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: strict-origin-when-cross-origin');
readfile($file);
