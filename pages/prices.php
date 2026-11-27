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
                <?php $dailyPrice = (float)$good['price']; ?>
                <div class="product-price-row" data-daily-price="<?= e((string)$dailyPrice) ?>">
                    <span class="product-price" data-total-price><?= e(content_price($dailyPrice)) ?></span>
                    <span class="product-price-separator">/</span>
                    <div class="duration-picker">
                        <button class="duration-trigger" type="button" aria-haspopup="listbox" aria-expanded="false" data-duration-trigger>
                            <span data-duration-value>1d</span>
                        </button>
                        <div class="duration-menu" role="listbox" aria-label="Срок действия подписки" data-duration-menu hidden>
                            <?php foreach (['1d' => 1, '3d' => 3, '7d' => 7, '14d' => 14, '30d' => 30, '90d' => 90, 'Навсегда' => 0] as $durationLabel => $days): ?>
                                <button type="button" role="option" aria-selected="<?= $days === 1 ? 'true' : 'false' ?>" data-days="<?= $days ?>" data-label="<?= e($durationLabel) ?>"><?= e($durationLabel) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
<?php $purchase = goods_purchase((string)$good['purchase']); $buyType = $purchase['type']; $buyUrl = $purchase['url']; ?>
<?php if ($buyType === 'none' || $buyUrl === ''): ?>
                <span class="btn btn-disabled tooltip" aria-disabled="true" tabindex="0" tooltip-text="Способ покупки пока не указан">Купить</span>
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
