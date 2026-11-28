<?php
/** Маршрутизатор: сопоставляет URL запроса с файлом страницы или с обработчиком действия /api/*. */
declare(strict_types=1);

/**
 * Определяет, какую страницу показать для запрошенного URL.
 * Возвращает путь к PHP-файлу страницы, либо null — если запрос уже полностью обработан
 * (выполнено действие /api/* или выведена страница 404).
 */
function route_resolve(): ?string
{
    $root = dirname(__DIR__);
    // Нормализуем путь: декодируем %-последовательности, схлопываем повторные слэши, убираем завершающий слэш
    $path = '/' . trim((string)preg_replace('#/+#', '/', rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'))), '/');
    // Запросы /api/<действие> передаём обработчику действий (вход, сохранение новостей и т.д.)
    if (preg_match('#^/api/([a-z0-9-]+?)(?:\.php)?$#', $path, $m)) { require_once $root . '/lib/actions.php'; handle_action($m[1]); return null; }
    // Приводим путь к «ключу»: убираем окончания /index и .php, чтобы /news, /news.php и /news/index совпадали
    $key = (string)preg_replace('#(?:/index)?(?:\.php)?$#', '', $path); if ($key === '') $key = '/';
    // Таблица маршрутов: URL => файл страницы
    $pages = ['/' => 'pages/index.php', '/news' => 'pages/news.php', '/prices' => 'pages/prices.php', '/admin' => 'pages/admin/index.php', '/admin/dashboard' => 'pages/admin/dashboard/index.php', '/admin/dashboard/users' => 'pages/admin/dashboard/users.php', '/admin/dashboard/news' => 'pages/admin/dashboard/news.php', '/admin/dashboard/goods' => 'pages/admin/dashboard/goods.php', '/admin/dashboard/security' => 'pages/admin/dashboard/security.php'];
    if (isset($pages[$key])) return $root . '/' . $pages[$key];
    // Маршрут не найден — отдаём простую страницу 404
    http_response_code(404); echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>404 — XWorsClient</title><link rel="stylesheet" href="/styles/index.css"></head><body class="auth-page"><main class="auth-card center"><h1>404</h1><p>Страница не найдена.</p><a class="back-link" href="/">Вернуться на сайт</a></main></body></html>'; return null;
}
