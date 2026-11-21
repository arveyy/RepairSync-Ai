<?php
// Runs every test file:  php tests/run-all.php
$code = 0;
foreach (['unit.php', 'integration.php'] as $f) { passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/' . $f), $c); $code |= $c; echo "\n"; }
passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/rag-eval.php') . ' | head -5');
exit($code);
