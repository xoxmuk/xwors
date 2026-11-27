<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../lib/auth.php';

/** Verified admin login for this request (redirects to /admin when not authenticated). */
function dash_login(): string
{
    static $login = null;
    if ($login === null) {
        header('Cache-Control: no-store');
        $login = require_admin();
    }
    return $login;
}

function dash_start(string $active, string $title): void
{
    $adminLogin = dash_login();
    $links = [
        'index'    => ['Главная', '/admin/dashboard/'],
        'users'    => ['Список юзеров', '/admin/dashboard/users'],
        'news'     => ['Новости', '/admin/dashboard/news'],
        'goods'    => ['Товары', '/admin/dashboard/goods'],
        'keys'     => ['Ключи', '/admin/dashboard/keys'],
        'security' => ['Безопасность', '/admin/dashboard/security'],
    ];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> — XWorsClient Admin</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=2">
    <link rel="stylesheet" href="/styles/index.css">
    <script src="/scripts/qrcode.min.js" defer></script>
    <script src="/scripts/app.js" defer></script>
</head>
<body class="root admin-root">
<aside class="sidebar col">
    <section class="side-info col"><div class="brand"><a href="/">XWorsClient</a></div><div class="label">УПРАВЛЕНИЕ</div></section>
    <nav class="side-items">
<?php foreach ($links as $key => $item): ?>
        <a href="<?= $item[1] ?>" class="side<?= $active === $key ? ' active' : '' ?>"><?= e($item[0]) ?></a>
<?php endforeach; ?>
    </nav>
    <section class="sidebar-bottom center col">
        <span><?= e($adminLogin) ?></span>
        <form method="post" action="/api/logout"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="logout-button" type="submit">Выйти</button></form>
    </section>
</aside>
<main class="main admin-main">
<div class="topline"><span class="label"><?= e($title) ?></span></div>
<?php } function dash_end(): void { ?>
</main>
</body>
</html>
<?php }
