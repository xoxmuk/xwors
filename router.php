<?php
declare(strict_types=1);
/* Локальная разработка: php -S localhost:8000 router.php (встроенный сервер PHP использует этот файл как роутер) */

// Подгружаем переменные окружения из файла .env (если он есть) — простой разбор строк вида KEY=VALUE
$env = __DIR__ . '/.env';
if (is_file($env)) {
    foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        // Пропускаем комментарии и строки без знака "="
        if ($line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v, " \t\"'"));
    }
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Отдаём favicon напрямую
if ($path === '/favicon.ico' && is_file(__DIR__ . $path)) {
    header('Content-Type: image/x-icon');
    readfile(__DIR__ . $path);
    return true;
}
// Статические файлы (стили, скрипты, картинки) отдаёт сам встроенный сервер: return false; защита от выхода из каталога через ".."
if (preg_match('#^/(styles|scripts|assets)/[\w./-]+$#', $path) && !str_contains($path, '..') && is_file(__DIR__ . $path)) {
    return false;
}
// Всё остальное обрабатывает общая точка входа
require __DIR__ . '/api/index.php';
