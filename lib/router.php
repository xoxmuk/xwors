<?php
declare(strict_types=1);

/**
 * Resolves the request path to a page file.
 * API actions and 404 are handled here (returns null); pages are returned for the caller to require
 * in the global scope.
 */
function route_resolve(): ?string
{
    $root = dirname(__DIR__);
    $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $path = rawurldecode($path);
    $path = '/' . trim((string)preg_replace('#/+#', '/', $path), '/');

    if (preg_match('#^/api/([a-z0-9-]+?)(?:\.php)?$#', $path, $m)) {
        require_once $root . '/lib/actions.php';
        handle_action($m[1]);
        return null;
    }

    $key = (string)preg_replace('#(?:/index)?(?:\.php)?$#', '', $path);
    if ($key === '') $key = '/';

    $pages = [
        '/'                       => 'pages/index.php',
        '/news'                   => 'pages/news.php',
        '/prices'                 => 'pages/prices.php',
        '/admin'                  => 'pages/admin/index.php',
        '/admin/dashboard'        => 'pages/admin/dashboard/index.php',
        '/admin/dashboard/users'  => 'pages/admin/dashboard/users.php',
        '/admin/dashboard/news'   => 'pages/admin/dashboard/news.php',
        '/admin/dashboard/goods'  => 'pages/admin/dashboard/goods.php',
        '/admin/dashboard/keys'   => 'pages/admin/dashboard/keys.php',
        '/admin/dashboard/security' => 'pages/admin/dashboard/security.php',
    ];

    if (isset($pages[$key])) return $root . '/' . $pages[$key];

    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>404 — XWorsClient</title><link rel="icon" type="image/x-icon" href="/favicon.ico?v=2"><link rel="stylesheet" href="/styles/index.css"></head>'
       . '<body class="auth-page"><main class="auth-card center"><h1>404</h1><p>Страница не найдена.</p>'
       . '<a class="back-link" href="/">Вернуться на сайт</a></main></body></html>';
    return null;
}
