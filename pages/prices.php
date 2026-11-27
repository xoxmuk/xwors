<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/content.php';
public_start('prices', 'Покупка');

$goods = [];
$error = null;

try {
    $goods = db()->query(
        'SELECT id, status, image, name, description, price, purchase, create_at FROM goods WHERE hidden = FALSE ORDER BY create_at DESC, id DESC'
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Public prices page error: ' . $e->getMessage());
    $error = 'Не удалось загрузить товары. Попробуйте обновить страницу позже.';
}
?>
<section class="content">
    <div class="card">
        <p>Здесь вы можете приобрести подписку на XWors Client.</p>
    </div>
    <div>
        <h1 class="center">Доступные товары</h1>
<?php if ($error): ?>
        <div class="card"><p class="form-error"><?= e($error) ?></p></div>
<?php elseif (!$goods): ?>
        <div class="card"><p>Товары скоро появятся.</p></div>
<?php else: ?>
        <div class="products">
<?php foreach ($goods as $good): ?>
<?php $meta = goods_status_meta((string)$good['status']); ?>
            <article class="card col product">
                <div>
<?php if ((string)$good['image'] !== ''): ?>
                    <img src="<?= e((string)$good['image']) ?>" alt="<?= e((string)$good['name']) ?>" loading="lazy">
<?php endif; ?>
                    <div class="product-head">
                        <h2><?= e((string)$good['name']) ?></h2>
                        <span class="status" data-color="<?= e($meta['color']) ?>"><i></i> <?= e($meta['label']) ?></span>
                    </div>
                </div>
                <div>
                    <div class="label">Описание</div>
                    <div>
<?php if ((string)$good['description'] !== ''): ?>
                        <p><?= nl2br(e((string)$good['description'])) ?></p>
<?php else: ?>
                        <p>Описание не указано.</p>
<?php endif; ?>
                    </div>
                </div>
                <div class="product-price"><?= e(content_price((string)$good['price'])) ?></div>
<?php $purchase = goods_purchase((string)$good['purchase']); $buyType = $purchase['type']; $buyUrl = $purchase['url']; ?>
<?php if ($buyType === 'none' || $buyUrl === ''): ?>
                <span class="btn btn-disabled tooltip" aria-disabled="true" tabindex="0" data-tooltip="Способ покупки пока не указан">Купить</span>
<?php elseif ($buyType === 'page'): ?>
                <a class="btn" href="<?= e($buyUrl) ?>">Купить</a>
<?php else: ?>
                <a class="btn" href="<?= e($buyUrl) ?>" target="_blank" rel="noopener noreferrer nofollow">Купить</a>
<?php endif; ?>
            </article>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </div>
</section>
<?php public_end(); ?>
