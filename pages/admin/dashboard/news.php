<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../../../lib/content.php';

$adminLogin = dash_login();
$csrf = csrf_token();
$editId = (int)($_GET['edit'] ?? 0);
$isNew = isset($_GET['new']);
$news = [];
$editing = null;
$error = null;

try {
    $news = db()->query(
        "SELECT id, version, hidden, create_at, CASE WHEN jsonb_typeof(data->'blocks') = 'array' THEN (SELECT b->>'title' FROM jsonb_array_elements(data->'blocks') AS b WHERE COALESCE(b->>'title', '') <> '' LIMIT 1) END AS title FROM news ORDER BY create_at DESC, id DESC"
    )->fetchAll();

    if ($editId > 0) {
        $stmt = db()->prepare('SELECT id, version, data, hidden, create_at FROM news WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $editing = $stmt->fetch() ?: null;
        if (!$editing) $error = 'Новость не найдена. Возможно, её уже удалили.';
    }
} catch (Throwable $e) {
    error_log('Admin news page error: ' . $e->getMessage());
    $error = 'Не удалось загрузить новости. Проверьте подключение к базе данных.';
}

$formData = $editing ? news_data((string)$editing['data']) : ['blocks' => [], 'images' => []];
if (!$formData['blocks']) $formData['blocks'] = [['title' => '', 'description' => '']];
$formVersion = (string)($editing['version'] ?? '');
$formHidden = !empty($editing['hidden']);
$showForm = $isNew || $editing !== null;

function admin_news_date(?string $value): string
{
    return $value ? date('d.m.Y H:i', strtotime($value)) : '—';
}

dash_start('news', 'Новости');
?>
<?php if (isset($_GET['saved'])): ?><p class="success-message flash">Новость сохранена.</p><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><p class="success-message flash">Новость удалена.</p><?php endif; ?>
<?php if (isset($_GET['toggled'])): ?><p class="success-message flash"><?= $_GET['toggled'] === 'hidden' ? 'Новость скрыта.' : 'Новость опубликована.' ?></p><?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <p class="form-error flash"><?= $_GET['error'] === 'empty' ? 'Добавьте версию или текст новости.' : 'Не удалось сохранить новость. Проверьте данные и попробуйте снова.' ?></p>
<?php endif; ?>
<?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>

<section class="panel-card table-panel">
    <div class="table-heading">
        <div>
            <h2>Новости</h2>
            <p>Публикации на странице «Новости». Нажмите на видимость, чтобы скрыть или опубликовать запись.</p>
        </div>
        <a class="btn-secondary" href="/admin/dashboard/news?new=1">+ Новая новость</a>
    </div>

<?php if (!$news): ?>
    <p class="empty-state">Новостей пока нет. Добавьте первую публикацию.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="users-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Версия</th>
                    <th>Заголовок</th>
                    <th>Дата</th>
                    <th>Видимость</th>
                    <th class="actions-col"><span class="sr-only">Действия</span></th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($news as $item): ?>
                <tr>
                    <td><?= (int)$item['id'] ?></td>
                    <td><?= $item['version'] !== '' ? e((string)$item['version']) : '—' ?></td>
                    <td><?= e((string)($item['title'] ?? '')) ?: '—' ?></td>
                    <td><?= e(admin_news_date($item['create_at'])) ?></td>
                    <td>
                        <form method="post" action="/api/toggle-news" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                            <button class="badge badge-button tooltip <?= $item['hidden'] ? 'badge-muted' : 'badge-success' ?>" tooltip-position="right" type="submit" tooltip-text="<?= $item['hidden'] ? 'Новость скрыта. Нажмите, чтобы опубликовать' : 'Новость видна посетителям. Нажмите, чтобы скрыть' ?>"><?= $item['hidden'] ? 'Скрыт' : 'Опубликован' ?></button>
                        </form>
                    </td>
                    <td class="actions-cell">
                        <div class="actions">
                            <a class="btn-secondary" href="/admin/dashboard/news?edit=<?= (int)$item['id'] ?>">Изменить</a>
                            <form method="post" action="/api/delete-news" class="inline-form" data-confirm="Удалить эту новость? Действие необратимо.">
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
<?php endif; ?>
</section>

<?php if ($showForm): ?>
<section class="panel-card" id="news-form">
    <h2><?= $editing ? 'Редактировать новость #' . (int)$editing['id'] : 'Новая новость' ?></h2>
    <form method="post" action="/api/save-news" class="admin-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

        <div class="form-grid">
            <div class="field">
                <label for="news-version">Версия</label>
                <input id="news-version" name="version" value="<?= e($formVersion) ?>" placeholder="v1.0.0" maxlength="120">
            </div>
            <div class="field">
                <span class="field-label">Видимость</span>
                <label class="checkbox-row"><input type="checkbox" name="hidden" value="1"<?= $formHidden ? ' checked' : '' ?>> Скрыть новость</label>
            </div>
        </div>

        <div class="field">
            <span class="field-label">Блоки новости</span>
            <div class="news-blocks" data-blocks>
<?php foreach ($formData['blocks'] as $block): ?>
                <div class="news-block" data-block>
                    <div class="news-block-head">
                        <span class="label">Блок</span>
                        <button type="button" class="btn-secondary btn-danger" data-remove-block>Удалить</button>
                    </div>
                    <div class="field">
                        <label>Заголовок</label>
                        <input name="block_title[]" value="<?= e($block['title']) ?>" maxlength="255">
                    </div>
                    <div class="field">
                        <label>Описание</label>
                        <textarea name="block_description[]" rows="6" placeholder="Текст или HTML-разметка"><?= e($block['description']) ?></textarea>
                    <div class="field-tools"><button type="button" class="btn-secondary" data-insert-tooltip>+ Tooltip</button><span class="hint">Подсказка при наведении на выделенный текст</span></div>
                    </div>
                </div>
<?php endforeach; ?>
            </div>
            <div><button type="button" class="btn-secondary" data-add-block>+ Добавить блок</button></div>
        </div>

        <div class="admin-actions">
            <button type="submit" class="btn">Сохранить</button>
            <a class="btn-secondary" href="/admin/dashboard/news">Отмена</a>
        </div>
    </form>
</section>

<template id="news-block-template">
    <div class="news-block" data-block>
        <div class="news-block-head">
            <span class="label">Блок</span>
            <button type="button" class="btn-secondary btn-danger" data-remove-block>Удалить</button>
        </div>
        <div class="field">
            <label>Заголовок</label>
            <input name="block_title[]" maxlength="255">
        </div>
        <div class="field">
            <label>Описание</label>
            <textarea name="block_description[]" rows="6" placeholder="Текст или HTML-разметка"></textarea>
            <div class="field-tools"><button type="button" class="btn-secondary" data-insert-tooltip>+ Tooltip</button><span class="hint">Подсказка при наведении на выделенный текст</span></div>
        </div>
    </div>
</template>
<?php endif; ?>
<?php dash_end(); ?>
