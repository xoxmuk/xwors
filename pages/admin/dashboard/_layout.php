<?php
/** Общий шаблон страниц админ-панели: проверка доступа, боковое меню, кнопка выхода. */
declare(strict_types=1);
require_once __DIR__ . '/../../../lib/auth.php';

/** Проверенный логин администратора для этого запроса (при отсутствии авторизации перенаправляет на /admin). */
function dash_login(): string
{
    // Проверка выполняется один раз за запрос, результат запоминается
    static $login = null;
    if ($login === null) {
        header('Cache-Control: no-store');
        $login = require_admin();
    }
    return $login;
}

/** Выводит начало страницы админки: head, меню разделов и заголовок. $active — подсвеченный раздел. */
function dash_start(string $active, string $title): void
{
    $adminLogin = dash_login();
    // Разделы админки: ключ => [подпись, адрес]
    $links = [
        'index'    => ['Главная', '/admin/dashboard/'],
        'news'     => ['Новости', '/admin/dashboard/news'],
        'goods'    => ['Товары', '/admin/dashboard/goods'],
        'users'    => ['Юзеры', '/admin/dashboard/users'],
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
<?php /* Библиотека QR-кодов нужна только на странице безопасности (подключение 2FA) */ ?>
<?php if ($active === 'security'): ?>
    <script src="/scripts/qrcode.min.js" defer></script>
<?php endif; ?>
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
        <?php /* Логин текущего администратора и форма выхода (POST + CSRF-токен) */ ?>
        <span><?= e($adminLogin) ?></span>
        <form method="post" action="/api/logout"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="logout-button" type="submit">Выйти</button></form>
    </section>
</aside>
<main class="main admin-main">
<div class="topline"><span class="label"><?= e($title) ?></span></div>
<?php } /* Закрывающая часть страницы админки */ function dash_end(): void { ?>
</main>
</body>
</html>
<?php }
