<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

function public_start(string $active, string $title): void {
    if (!headers_sent()) header('Cache-Control: public, max-age=0, s-maxage=15, stale-while-revalidate=60');
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
<?php } function public_end(): void { ?>
</main>
</body>
</html>
<?php }
