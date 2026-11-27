<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../../../lib/content.php';

$adminLogin = dash_login();
$csrf = csrf_token();
$editId = (int)($_GET['edit'] ?? 0);
$isNew = isset($_GET['new']);
$goods = [];
$editing = null;
$error = null;

try {
    $goods = db()->query(
        'SELECT id, status, image, name, prices, discount, hidden, create_at FROM goods ORDER BY create_at DESC, id DESC'
    )->fetchAll();

    if ($editId > 0) {
        $stmt = db()->prepare('SELECT id, status, image, name, description, prices, discount, purchase, hidden, create_at FROM goods WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $editing = $stmt->fetch() ?: null;
        if (!$editing) $error = 'Товар не найден. Возможно, его уже удалили.';
    }
} catch (Throwable $e) {
    error_log('Admin goods page error: ' . $e->getMessage());
    $error = 'Не удалось загрузить товары. Проверьте подключение к базе данных.';
}

$formStatus = (string)($editing['status'] ?? 'undetected');
if (!isset(goods_statuses()[$formStatus])) $formStatus = 'undetected';
$formImage = (string)($editing['image'] ?? '');
$formName = (string)($editing['name'] ?? '');
$formDescription = (string)($editing['description'] ?? '');
$formPrices = goods_prices(isset($editing['prices']) ? (string)$editing['prices'] : '[{"name":"1d","price":0,"discount":0}]');
$formDiscount = (float)($editing['discount'] ?? 0);
$purchase = goods_purchase(isset($editing['purchase']) ? (string)$editing['purchase'] : null);
$formBuyType = $purchase['type'];
$formBuyUrl = $purchase['url'];
$formHidden = !empty($editing['hidden']);
$showForm = $isNew || $editing !== null;

function admin_goods_date(?string $value): string
{
    return $value ? date('d.m.Y H:i', strtotime($value)) : '—';
}

dash_start('goods', 'Товары');
?>
<?php if (isset($_GET['saved'])): ?><p class="success-message flash">Товар сохранён.</p><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><p class="success-message flash">Товар удалён.</p><?php endif; ?>
<?php if (isset($_GET['toggled'])): ?><p class="success-message flash"><?= match ($_GET['toggled']) { 'hidden' => 'Товар скрыт.', 'visible' => 'Товар опубликован.', default => 'Данные товара обновлены.' } ?></p><?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <p class="form-error flash"><?php
        if ($_GET['error'] === 'name') echo 'Укажите название товара.';
        elseif ($_GET['error'] === 'price') echo 'Укажите цену от 0 до 9 999 999 999,99 ₽.';
        elseif ($_GET['error'] === 'buy') echo e(mb_substr((string)($_GET['msg'] ?? ''), 0, 200) ?: 'Проверьте, куда должна вести кнопка «Купить».');
        else echo 'Не удалось сохранить товар. Проверьте данные и попробуйте снова.';
    ?></p>
<?php endif; ?>
<?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>

<section class="panel-card table-panel">
    <div class="table-heading">
        <div>
            <h2>Товары</h2>
            <p>Товары на странице «Покупка». Нажмите на статус или видимость, чтобы изменить. Скрытые товары не видны посетителям.</p>
        </div>
        <a class="btn-secondary" href="/admin/dashboard/goods?new=1">+ Новый товар</a>
    </div>

<?php if (!$goods): ?>
    <p class="empty-state">Товаров пока нет. Добавьте первый товар.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="users-table goods-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Фото</th>
                    <th>Название</th>
                    <th>Цена</th>
                    <th>Статус</th>
                    <th>Видимость</th>
                    <th>Дата</th>
                    <th class="actions-col"><span class="sr-only">Действия</span></th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($goods as $item): ?>
<?php $meta = goods_status_meta((string)$item['status']); ?>
                <tr>
                    <td><?= (int)$item['id'] ?></td>
                    <td>
                        <img class="table-thumb" src="<?= e((string)$item['image'] !== '' ? (string)$item['image'] : '/assets/goods/default_64.png') ?>" alt="">
                    </td>
                    <td><?= e((string)$item['name']) ?: '—' ?></td>
                    <td><?= e(implode(', ', array_map(fn($p) => $p['name'] . ': ' . content_price($p['price']), goods_prices((string)$item['prices']))) ) ?></td>
                    <td>
                        <button class="status status-button tooltip" tooltip-position="right" tooltip-text="Нажмите, чтобы выбрать другой статус" type="button" data-status-menu data-id="<?= (int)$item['id'] ?>" data-current="<?= e((string)$item['status']) ?>" data-color="<?= e($meta['color']) ?>" aria-haspopup="menu" aria-expanded="false"><i></i> <?= e($meta['label']) ?></button>
                    </td>
                    <td>
                        <form method="post" action="/api/toggle-good" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                            <button class="badge badge-button tooltip <?= $item['hidden'] ? 'badge-muted' : 'badge-success' ?>" tooltip-position="right" type="submit" tooltip-text="<?= $item['hidden'] ? 'Товар скрыт. Нажмите, чтобы опубликовать' : 'Товар виден посетителям. Нажмите, чтобы скрыть' ?>"><?= $item['hidden'] ? 'Скрыт' : 'Опубликован' ?></button>
                        </form>
                    </td>
                    <td><?= e(admin_goods_date($item['create_at'])) ?></td>
                    <td class="actions-cell">
                        <div class="actions">
                            <a class="btn-secondary" href="/admin/dashboard/goods?edit=<?= (int)$item['id'] ?>">Изменить</a>
                            <form method="post" action="/api/delete-good" class="inline-form" data-confirm="Удалить этот товар? Действие необратимо.">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                <button class="btn-secondary btn-danger" type="submit">Удалить</button>
                            </form>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="post" action="/api/set-good-status" id="good-status-form" hidden>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="status" value="">
    </form>
    <script type="application/json" id="good-status-options"><?= json_encode(array_map(fn($k, $m) => ['key' => $k, 'label' => $m['label'], 'color' => $m['color']], array_keys(goods_statuses()), array_values(goods_statuses())), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
</section>

<?php if ($showForm): ?>
<section class="panel-card" id="goods-form">
    <h2><?= $editing ? 'Редактировать товар #' . (int)$editing['id'] : 'Новый товар' ?></h2>
    <form method="post" action="/api/save-good" class="admin-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

        <div class="goods-identity">
        <div class="field">
            <span class="field-label">Иконка товара</span>
            <div class="media-box single" data-uploader="single" data-folder="goods" data-max-bytes="2097152" data-csrf="<?= e($csrf) ?>">
                <input type="hidden" name="image" value="<?= $formImage !== '' ? '__keep__' : '' ?>" data-value>
                <input type="file" accept="image/png,image/jpeg,image/gif,image/webp,image/avif" hidden data-file>
                <div class="media-surface" data-drop tabindex="0" role="button" aria-label="Загрузить изображение товара">
                    <img src="<?= e($formImage) ?>" alt=""<?= $formImage === '' ? ' hidden' : '' ?> data-preview>
                    <div class="media-empty" data-empty<?= $formImage !== '' ? ' hidden' : '' ?>>
                        <span class="media-icon">↑</span>
                        <strong>Перетащите изображение сюда</strong>
                        <span class="hint">или нажмите, чтобы выбрать · PNG, JPG, WEBP, GIF · до 2 МБ</span>
                    </div>
                    <div class="media-hover" data-hover><span>Перетащите или нажмите, чтобы заменить</span></div>
                    <div class="upload-progress" data-progress><i></i></div>
                </div>
                <button type="button" class="btn-secondary btn-danger media-remove" data-remove<?= $formImage === '' ? ' hidden' : '' ?>>Удалить</button>
            </div>
            <span class="upload-error" data-error hidden role="alert"></span>
        </div>

        <div class="field goods-name-field">
            <label for="good-name">Название</label>
            <input id="good-name" name="name" value="<?= e($formName) ?>" maxlength="255" required>
        </div>
        </div>

        <div class="form-grid goods-settings-grid">
            <div class="field goods-prices-field">
                <span class="field-label">Варианты цены</span>
                <input type="hidden" name="prices" id="good-prices-json" value="<?= e(json_encode($formPrices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                <button type="button" class="btn-secondary price-toggle" data-price-toggle aria-expanded="true">Свернуть список цен</button>
                <div class="price-editor-scroll" data-price-scroll>
                <div class="price-editor" data-price-editor>
<?php foreach ($formPrices as $priceIndex => $priceItem): ?>
                    <div class="price-editor-row" data-price-row>
                        <input aria-label="Название варианта" data-price-name value="<?= e($priceItem['name']) ?>" placeholder="Например, 1d">
                        <input aria-label="Цена" data-price-value type="number" min="0" max="9999999999.99" step="0.01" value="<?= e((string)$priceItem['price']) ?>" placeholder="Цена, ₽">
                        <input aria-label="Скидка в процентах" data-price-discount type="number" min="0" max="100" step="0.01" value="<?= e((string)$priceItem['discount']) ?>" placeholder="Скидка, %">
                        <button type="button" class="btn-secondary btn-danger" data-price-remove>Удалить</button>
                    </div>
<?php endforeach; ?>
                </div>
                </div>
                <button type="button" class="btn-secondary" data-price-add>+ Добавить цену</button>
                <p class="hint">Название (например, 1d), цена и скидка в процентах. Скидка рассчитывается от указанной цены.</p>
            </div>
            <div class="goods-right-fields">
            <div class="field">
                <label for="good-discount">Общая скидка товара, %</label>
                <input id="good-discount" name="discount" type="number" min="0" max="100" step="0.01" value="<?= e((string)$formDiscount) ?>" placeholder="0">
                <p class="hint">Применяется ко всем вариантам цены дополнительно к индивидуальной скидке варианта. Скидки складываются, максимум — 100%.</p>
            </div>
            <div class="field">
                <label for="good-status">Статус</label>
                <select id="good-status" name="status">
<?php foreach (goods_statuses() as $key => $meta): ?>
                    <option value="<?= e($key) ?>"<?= $formStatus === $key ? ' selected' : '' ?>><?= e($meta['label']) ?></option>
<?php endforeach; ?>
                </select>
            </div>
        <div class="field buy-field buy-field-right" data-buy>
            <span class="field-label">Кнопка «Купить» <span class="info-tip tooltip" tabindex="0" tooltip-text="Куда попадёт посетитель после нажатия на кнопку «Купить» на странице товара.">?</span></span>
            <div class="buy-options">
                <select name="buy_type" aria-label="Куда ведёт кнопка" data-buy-type>
<?php foreach (goods_buy_types() as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $formBuyType === $key ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                </select>
                <div data-buy-panel="telegram"<?= $formBuyType === 'telegram' ? '' : ' hidden' ?>>
                    <input name="buy_value" value="<?= $formBuyType === 'telegram' ? e($formBuyUrl) : '' ?>" placeholder="@username или https://t.me/username" maxlength="255"<?= $formBuyType === 'telegram' ? '' : ' disabled' ?>>
                </div>
                <div data-buy-panel="link"<?= $formBuyType === 'link' ? '' : ' hidden' ?>>
                    <input name="buy_value" type="text" inputmode="url" value="<?= $formBuyType === 'link' ? e($formBuyUrl) : '' ?>" placeholder="https://example.com/buy" maxlength="500"<?= $formBuyType === 'link' ? '' : ' disabled' ?>>
                </div>
                <div data-buy-panel="page"<?= $formBuyType === 'page' ? '' : ' hidden' ?>>
                    <select name="buy_page"<?= $formBuyType === 'page' ? '' : ' disabled' ?> aria-label="Страница сайта">
<?php foreach (goods_buy_pages() as $path => $label): ?>
                        <option value="<?= e($path) ?>"<?= $formBuyType === 'page' && $formBuyUrl === $path ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <p class="buy-note" data-buy-panel="none"<?= $formBuyType === 'none' ? '' : ' hidden' ?>>Кнопка будет показана неактивной.</p>
            </div>
        </div>
            <div class="field">
                <span class="field-label">Видимость</span>
                <label class="checkbox-row"><input type="checkbox" name="hidden" value="1"<?= $formHidden ? ' checked' : '' ?>> Скрыть товар <span class="info-tip tooltip" tabindex="0" tooltip-text="Скрытый товар не показывается на странице «Покупка». Позже можно опубликовать одним кликом в списке.">?</span></label>
            </div>
            </div>
        </div>



        <div class="field">
            <label for="good-description">Описание</label>
            <textarea id="good-description" name="description" rows="4"><?= e($formDescription) ?></textarea>
            <div class="field-tools"><button type="button" class="btn-secondary" data-insert-tooltip>+ Tooltip</button><span class="hint">Выделите текст и добавьте подсказку при наведении</span></div>
        </div>

        <div class="admin-actions">
            <button type="submit" class="btn">Сохранить</button>
            <a class="btn-secondary" href="/admin/dashboard/goods">Отмена</a>
        </div>
    </form>
</section>
<?php endif; ?>
<?php dash_end(); ?>
