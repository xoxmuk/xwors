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
        'SELECT id, status, image, name, description, price, hidden, create_at FROM goods ORDER BY create_at DESC, id DESC'
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
<?php if (isset($_GET['saved'])): ?><p class="success-message">Товар сохранён.</p><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><p class="success-message">Товар удалён.</p><?php endif; ?>
<?php if (isset($_GET['toggled'])): ?><p class="success-message">Данные товара обновлены.</p><?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <p class="form-error"><?= $_GET['error'] === 'name' ? 'Укажите название товара.' : 'Не удалось сохранить товар. Проверьте данные и попробуйте снова.' ?></p>
<?php endif; ?>
<?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>

<section class="panel-card table-panel">
    <div class="table-heading">
        <div>
            <h2>Товары</h2>
            <p>Товары на странице «Покупка». Статус меняется одним нажатием на бейдж, скрытые товары не видны посетителям.</p>
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
                    <th>Действия</th>
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
                        <form method="post" action="/api/cycle-good-status" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                            <button class="status status-button" type="submit" data-color="<?= e($meta['color']) ?>" title="Нажмите, чтобы сменить статус"><i></i> <?= e($meta['label']) ?></button>
                        </form>
                    </td>
                    <td><span class="badge <?= $item['hidden'] ? 'badge-muted' : 'badge-success' ?>"><?= $item['hidden'] ? 'Скрыт' : 'Опубликован' ?></span></td>
                    <td><?= e(admin_goods_date($item['create_at'])) ?></td>
                    <td class="actions-cell">
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
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</section>

<?php if ($showForm): ?>
<section class="panel-card" id="goods-form">
    <h2><?= $editing ? 'Редактировать товар #' . (int)$editing['id'] : 'Новый товар' ?></h2>
    <form method="post" action="/api/save-good" class="admin-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

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

        <div class="form-grid">
            <div class="field">
                <label for="good-image">Ссылка на картинку</label>
                <input id="good-image" name="image" value="<?= str_starts_with($formImage, 'data:') ? '' : e($formImage) ?>" placeholder="https://example.com/image.png">
            </div>
            <div class="field">
                <label for="good-image-file">Или загрузить файл</label>
                <input id="good-image-file" type="file" name="image_file" accept="image/*">
                <span class="hint">До 2 МБ. Загрузка заменит текущую картинку.</span>
            </div>
        </div>

<?php if ($formImage !== ''): ?>
        <div class="field">
            <span class="field-label">Текущая картинка</span>
            <div class="image-preview"><img src="<?= e($formImage) ?>" alt=""></div>
            <label class="checkbox-row"><input type="checkbox" name="remove_image" value="1"> Удалить текущую картинку</label>
        </div>
<?php endif; ?>

        <div class="admin-actions">
            <button type="submit" class="btn">Сохранить</button>
            <a class="btn-secondary" href="/admin/dashboard/goods">Отмена</a>
        </div>
    </form>
</section>
<?php endif; ?>
<?php dash_end(); ?>
