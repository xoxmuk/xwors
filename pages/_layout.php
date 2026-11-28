<?php
/** Общий шаблон публичных страниц: боковое меню, шапка страницы и завершающие теги. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

/**
 * Выводит начало страницы (head, боковую панель с навигацией, заголовок).
 * $active — ключ подсвеченного пункта меню, $title — заголовок страницы.
 */
function public_start(string $active, string $title): void {
    // Короткое кэширование на CDN (15 сек.) с возможностью отдать устаревшую копию, пока обновляется новая
    if (!headers_sent()) header('Cache-Control: public, max-age=0, s-maxage=15, stale-while-revalidate=60');
    // Пункты меню: ключ => [подпись, адрес]
    $items = ['index' => ['Главная', '/'], 'news' => ['Новости', '/news'], 'prices' => ['Покупка', '/prices']];
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $t ?> — XWorsClient</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=2">
    <link rel="stylesheet" href="/styles/index.css">
    <script src="/scripts/app.js" defer></script>
</head>
<body class="root">
<aside class="sidebar col">
    <section class="side-info col"><div class="brand"><a href="/">XWorsClient</a></div><div class="label">НАВИГАЦИЯ</div></section>
    <nav class="side-items">
<?php /* Список ссылок меню; у активной страницы добавляется класс active */ ?>
<?php foreach ($items as $key => $item): ?>
        <a href="<?= $item[1] ?>" class="side<?= $active === $key ? ' active' : '' ?>"><?= htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') ?></a>
<?php endforeach; ?>
    </nav>
    <section class="sidebar-bottom center col">
        <span>&copy; <?= date('Y') ?> XWorsClient</span>
        <span class="sidebar-designer">Site designed by <a class="tooltip" href="https://t.me/ialonet" tooltip-text="In no way involved in the administration or management of the site.">@ialonet</a></span>
    </section>
</aside>
<main class="main">
<div class="topline"><span class="label"><?= $t ?></span></div>
<?php } /* Закрывает контент страницы — вызывается в конце каждой публичной страницы */ function public_end(): void { ?>
</main>
</body>
</html>
<?php }
