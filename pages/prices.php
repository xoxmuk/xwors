<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/content.php';
public_start('prices', 'Покупка');

$goods = [];
$error = null;

try {
    $goods = db()->query(
        'SELECT id, status, image, name, description, prices, discount, purchase, create_at FROM goods WHERE hidden = FALSE ORDER BY create_at DESC, id DESC'
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
                <div class="product-visual">
                    <img src="<?= e((string)$good['image'] !== '' ? (string)$good['image'] : '/assets/goods/default_64.png') ?>" alt="<?= e((string)$good['name']) ?>" loading="lazy">
                    <span class="status product-status" data-color="<?= e($meta['color']) ?>"><i></i> <?= e($meta['label']) ?></span>
                </div>
                <div class="product-heading">
                    <h2><?= e((string)$good['name']) ?></h2>
                </div>
                <div>
                    <div class="label">Описание</div>
                    <div>
<?php if ((string)$good['description'] !== ''): ?>
                        <div class="product-description"><?= content_render_html((string)$good['description']) ?></div>
<?php else: ?>
                        <p>Описание не указано.</p>
<?php endif; ?>
                    </div>
                </div>
                <?php $priceOptions = goods_prices((string)$good['prices']); $firstPrice = $priceOptions[0]; $productDiscount = (float)($good['discount'] ?? 0); $totalDiscount = min(100, (float)$firstPrice['discount'] + $productDiscount); ?>
                <div class="product-price-row" data-product-discount="<?= e((string)$productDiscount) ?>" data-price-options="<?= e(json_encode($priceOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                    <div class="product-price-stack">
                        <div class="product-price-main">
                            <span class="product-price" data-total-price><?= e(content_price((float)$firstPrice['price'] * (1 - $totalDiscount / 100))) ?></span>
                            <span class="product-old-price" data-original-price<?= $totalDiscount > 0 ? '' : ' hidden' ?>><?= e(content_price((float)$firstPrice['price'])) ?></span>
                            <span class="product-discount" data-discount<?= $totalDiscount > 0 ? '' : ' hidden' ?>>-<?= e((string)$totalDiscount) ?>%</span>
                        </div>
                    </div>
                    <div class="duration-picker">
                        <button class="duration-trigger" type="button" aria-haspopup="listbox" aria-expanded="false" data-duration-trigger>
                            <span data-duration-value><?= e((string)$firstPrice['name']) ?></span>
                        </button>
                        <div class="duration-menu" role="listbox" aria-label="Срок действия подписки" data-duration-menu hidden>
                            <?php foreach ($priceOptions as $priceIndex => $priceOption): ?>
                                <button type="button" role="option" aria-selected="<?= $priceIndex === 0 ? 'true' : 'false' ?>" data-price-index="<?= $priceIndex ?>" data-label="<?= e($priceOption['name']) ?>"><?= e($priceOption['name']) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
<?php $purchase = goods_purchase((string)$good['purchase']); $buyType = $purchase['type']; $buyUrl = $purchase['url']; ?>
<?php if ($buyType === 'none' || $buyUrl === ''): ?>
                <span class="btn btn-disabled tooltip" aria-disabled="true" tabindex="0" tooltip-text="Способ покупки пока не указан">Оплатить</span>
<?php elseif ($buyType === 'page'): ?>
                <a class="btn" href="<?= e($buyUrl) ?>">Оплатить</a>
<?php else: ?>
                <a class="btn" href="<?= e($buyUrl) ?>" target="_blank" rel="noopener noreferrer nofollow">Оплатить</a>
<?php endif; ?>
            </article>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </div>
</section>
<?php public_end(); ?>
