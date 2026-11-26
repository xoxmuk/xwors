<?php
declare(strict_types=1);
function public_start(string $active, string $title): void {
    $items = ['index'=>['Главная','/'], 'news'=>['Новости','/news'], 'prices'=>['Покупка','/prices']];
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> — XWorsClient</title><link rel="stylesheet" href="/styles/index.css"></head><body class="root">
<aside class="sidebar col"><section class="side-info col"><div class="brand"><span>XWorsClient</span></div><div class="label">НАВИГАЦИЯ</div></section><nav class="side-items">
<?php foreach ($items as $key=>$item): ?><a href="<?= $item[1] ?>" class="side<?= $active===$key?' active':'' ?>"><?= htmlspecialchars($item[0],ENT_QUOTES,'UTF-8') ?></a><?php endforeach; ?>
<a href="/admin" class="side admin-link">Админ-панель</a></nav>
<section class="sidebar-bottom center col"><span class="status" data-color="lime"><i></i> Undetected</span><span>&copy; <?= date('Y') ?> XWorsClient</span><span>Site designed by <a class="tooltip" href="https://t.me/ialonet" data-tooltip="In no way involved in the administration or management of the site.">@ialonet</a></span></section></aside>
<main class="content"><div class="topline"><span class="label"><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?></span></div>
<?php } function public_end(): void { ?></main></body></html><?php }
