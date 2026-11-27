<?php
require_once __DIR__ . '/_layout.php';
dash_start('users', 'Список юзеров');

$csrf = csrf_token();
$created = (string)($_GET['created'] ?? '');
$users = [];
$error = null;

try {
    $users = db()->query(
        'SELECT uuid::text AS uuid, "key" AS access_key, created_at, expires_at FROM users ORDER BY created_at DESC'
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Admin users page error: ' . $e->getMessage());
    $error = 'Не удалось загрузить пользователей. Проверьте структуру таблицы users в базе данных.';
}

function format_date(?string $value, string $empty): string
{
    return $value ? date('d.m.Y H:i', strtotime($value)) : $empty;
}
?>
<?php if ($created !== ''): ?><p class="success-message flash">Пользователь создан, ключ сгенерирован.</p><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><p class="success-message flash">Пользователь удалён.</p><?php endif; ?>
<?php if (($_GET['error'] ?? '') === 'date'): ?><p class="form-error flash">Некорректная дата окончания.</p><?php endif; ?>

<section class="panel-card">
    <h2>Создать пользователя</h2>
    <p>Будет сгенерирован случайный UUID и уникальный ключ из 32 символов.</p>
    <form method="post" action="/api/create-user" class="admin-form key-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <div class="field">
            <label for="key-expires">Действует до (необязательно)</label>
            <input id="key-expires" type="datetime-local" name="expires_at">
            <span class="hint">Если не указано — ключ бессрочный.</span>
        </div>
        <div class="admin-actions"><button type="submit" class="btn">Сгенерировать ключ</button></div>
    </form>
</section>

<section class="panel-card table-panel">
    <div class="table-heading">
        <div>
            <h2>Пользователи</h2>
            <p>UUID, ключ доступа и сроки его действия</p>
        </div>
        <span class="count"><?= count($users) ?></span>
    </div>

<?php if ($error): ?>
    <p class="form-error"><?= e($error) ?></p>
<?php elseif (!$users): ?>
    <p class="empty-state">В базе данных пока нет пользователей.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="users-table">
            <thead>
                <tr>
                    <th>UUID</th>
                    <th>KEY</th>
                    <th>Дата создания</th>
                    <th>Действует до</th>
                    <th class="actions-col"><span class="sr-only">Действия</span></th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($users as $i => $u): ?>
                <tr<?= $u['uuid'] === $created ? ' class="row-new"' : '' ?>>
                    <td class="uuid-cell"><?= e((string)$u['uuid']) ?></td>
                    <td class="key-cell" id="key-<?= $i ?>"><?= e((string)$u['access_key']) ?></td>
                    <td><?= e(format_date($u['created_at'], '—')) ?></td>
                    <td><?= e(format_date($u['expires_at'], 'Бессрочно')) ?></td>
                    <td class="actions-cell">
                        <div class="actions">
                            <button type="button" class="btn-secondary" data-copy="#key-<?= $i ?>">Копировать</button>
                            <form method="post" action="/api/delete-user" class="inline-form" data-confirm="Удалить этого пользователя? Действие необратимо.">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="uuid" value="<?= e((string)$u['uuid']) ?>">
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
<?php dash_end(); ?>
