<?php
declare(strict_types=1);
/* Local dev: php -S localhost:8000 router.php */
$env = __DIR__ . '/.env';
if (is_file($env)) {
    foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v, " \t\"'"));
    }
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/favicon.ico' && is_file(__DIR__ . $path)) {
    header('Content-Type: image/x-icon');
    readfile(__DIR__ . $path);
    return true;
}
if (preg_match('#^/(styles|scripts)/[\w./-]+$#', $path) && !str_contains($path, '..') && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/api/index.php';
