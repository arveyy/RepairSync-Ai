<?php
declare(strict_types=1);
// Minimal autoloader (no Composer needed on XAMPP). Several small classes share a file, so load them all.
foreach (['Nlp', 'Db', 'Rag', 'Auth', 'Workflow', 'Llm', 'Http', 'Seeder', 'SampleData', 'App'] as $f) {
    $p = __DIR__ . "/$f.php";
    if (is_file($p)) require_once $p;
}
