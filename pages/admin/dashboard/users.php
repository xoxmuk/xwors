<?php require __DIR__.'/_layout.php'; dash_start('users','Список юзеров'); $users=[]; $error=null;
try { $users=db()->query('SELECT uuid::text AS uuid, "key" AS access_key, created_at, expires_at FROM users ORDER BY created_at DESC')->fetchAll(); }
catch(Throwable $e){ error_log('Admin users page error: '.$e->getMessage()); $error='Не удалось загрузить пользователей. Проверьте структуру таблицы users в базе данных.'; } ?>
<section class="panel-card table-panel"><div class="table-heading"><div><h2>Пользователи</h2><p>UUID, ключ доступа и сроки его действия</p></div><span class="count"><?= count($users) ?></span></div>
<?php if($error): ?><p class="form-error"><?= e($error) ?></p><?php elseif(!$users): ?><p class="empty-state">В базе данных пока нет пользователей.</p><?php else: ?>
<div class="table-scroll"><table class="users-table"><thead><tr><th>UUID</th><th>KEY</th><th>Дата создания</th><th>Действует до</th></tr></thead><tbody>
<?php foreach($users as $u): ?><tr><td class="uuid-cell"><?= e((string)$u['uuid']) ?></td><td class="key-cell"><?= e((string)$u['access_key']) ?></td><td><?= e($u['created_at']?date('d.m.Y H:i',strtotime((string)$u['created_at'])):'—') ?></td><td><?= e($u['expires_at']?date('d.m.Y H:i',strtotime((string)$u['expires_at'])):'Бессрочно') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section><?php dash_end(); ?>
