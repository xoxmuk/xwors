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
        'SELECT id, status, image, name, price, hidden, create_at FROM goods ORDER BY create_at DESC, id DESC'
    )->fetchAll();

    if ($editId > 0) {
        $stmt = db()->prepare('SELECT id, status, image, name, description, price, hidden, create_at FROM goods WHERE id = :id');
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
$formPrice = (string)($editing['price'] ?? '0');
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
<?php if (isset($_GET['toggled'])): ?><p class="success-message flash">Данные товара обновлены.</p><?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <p class="form-error flash"><?= $_GET['error'] === 'name' ? 'Укажите название товара.' : 'Не удалось сохранить товар. Проверьте данные и попробуйте снова.' ?></p>
<?php endif; ?>
<?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>

<section class="panel-card table-panel">
    <div class="table-heading">
        <div>
            <h2>Товары</h2>
            <p>Товары на странице «Покупка». Нажмите на статус, чтобы выбрать другой. Скрытые товары не видны посетителям.</p>
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
<?php if ((string)$item['image'] !== ''): ?>
                        <img class="table-thumb" src="<?= e((string)$item['image']) ?>" alt="">
<?php else: ?>
                        —
<?php endif; ?>
                    </td>
                    <td><?= e((string)$item['name']) ?: '—' ?></td>
                    <td><?= e(content_price((string)$item['price'])) ?></td>
                    <td>
                        <button class="status status-button" type="button" data-status-menu data-id="<?= (int)$item['id'] ?>" data-current="<?= e((string)$item['status']) ?>" data-color="<?= e($meta['color']) ?>" aria-haspopup="menu" aria-expanded="false"><i></i> <?= e($meta['label']) ?></button>
                    </td>
                    <td><span class="badge <?= $item['hidden'] ? 'badge-muted' : 'badge-success' ?>"><?= $item['hidden'] ? 'Скрыт' : 'Опубликован' ?></span></td>
                    <td><?= e(admin_goods_date($item['create_at'])) ?></td>
                    <td class="actions-cell">
                        <div class="actions">
                            <a class="btn-secondary" href="/admin/dashboard/goods?edit=<?= (int)$item['id'] ?>">Изменить</a>
                            <form method="post" action="/api/toggle-good" class="inline-form">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                <button class="btn-secondary" type="submit"><?= $item['hidden'] ? 'Показать' : 'Скрыть' ?></button>
                            </form>
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

        <div class="field">
            <span class="field-label">Изображение товара</span>
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
            <span class="upload-error" data-error hidden></span>
            <label for="good-image-url" class="sub-label">Или ссылка на картинку</label>
            <input id="good-image-url" name="image_url" value="<?= ($formImage !== '' && !str_starts_with($formImage, 'data:') && !str_starts_with($formImage, '/')) ? e($formImage) : '' ?>" placeholder="https://example.com/image.png" data-url>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="good-name">Название</label>
                <input id="good-name" name="name" value="<?= e($formName) ?>" maxlength="255" required>
            </div>
            <div class="field">
                <label for="good-price">Цена</label>
                <input id="good-price" name="price" type="number" min="0" step="0.01" value="<?= e($formPrice) ?>">
            </div>
            <div class="field">
                <label for="good-status">Статус</label>
                <select id="good-status" name="status">
<?php foreach (goods_statuses() as $key => $meta): ?>
                    <option value="<?= e($key) ?>"<?= $formStatus === $key ? ' selected' : '' ?>><?= e($meta['label']) ?></option>
<?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <span class="field-label">Видимость</span>
                <label class="checkbox-row"><input type="checkbox" name="hidden" value="1"<?= $formHidden ? ' checked' : '' ?>> Скрыть товар</label>
            </div>
        </div>

        <div class="field">
            <label for="good-description">Описание</label>
            <textarea id="good-description" name="description" rows="4"><?= e($formDescription) ?></textarea>
        </div>

        <div class="admin-actions">
            <button type="submit" class="btn">Сохранить</button>
            <a class="btn-secondary" href="/admin/dashboard/goods">Отмена</a>
        </div>
    </form>
</section>
<?php endif; ?>
<?php dash_end(); ?>
