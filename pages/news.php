<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/content.php';
public_start('news', 'Новости');

$news = [];
$error = null;

try {
    $news = db()->query(
        'SELECT id, version, data, create_at FROM news WHERE hidden = FALSE ORDER BY create_at DESC, id DESC'
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Public news page error: ' . $e->getMessage());
    $error = 'Не удалось загрузить новости. Попробуйте обновить страницу позже.';
}
?>
<section class="content">
<?php if ($error): ?>
    <div class="card"><p class="form-error"><?= e($error) ?></p></div>
<?php elseif (!$news): ?>
    <div class="card"><p>Пока новостей нет. Загляните позже.</p></div>
<?php else: ?>
<?php foreach ($news as $item): ?>
<?php $data = news_data((string)$item['data']); ?>
    <article class="card news-card">
        <div class="news-head">
            <span class="label"><?= (string)$item['version'] !== '' ? e((string)$item['version']) : 'Новость' ?></span>
            <time class="news-date" datetime="<?= e((string)$item['create_at']) ?>"><?= e(date('d.m.Y', strtotime((string)$item['create_at']))) ?></time>
        </div>
<?php foreach ($data['blocks'] as $block): ?>
<?php if ($block['title'] !== ''): ?><h2><?= e($block['title']) ?></h2><?php endif; ?>
<?php if ($block['description'] !== ''): ?><p><?= nl2br(e($block['description'])) ?></p><?php endif; ?>
<?php endforeach; ?>
<?php if ($data['images']): ?>
        <div class="news-images">
<?php foreach ($data['images'] as $image): ?>
            <img src="<?= e($image) ?>" alt="" loading="lazy">
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </article>
<?php endforeach; ?>
<?php endif; ?>
</section>
<?php public_end(); ?>
