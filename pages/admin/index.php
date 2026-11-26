<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/auth.php';
header('Cache-Control: no-store');
try { if (auth_user() !== null) { require_admin(); redirect('/admin/dashboard/'); } $csrfToken = csrf_token(); }
catch (Throwable $e) { error_log('Admin login page error: '.$e->getMessage()); http_response_code(503); exit('Не удалось проверить авторизацию. Проверьте подключение к базе данных и AUTH_SECRET.'); }
$error=isset($_GET['error']);
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Авторизация — XWorsClient</title><link rel="stylesheet" href="/styles/index.css"></head><body class="auth-page">
<main class="auth-card"><h1 class="center">Авторизация</h1>
<?php if($error): ?><p class="form-error">Неверный логин или пароль либо временно заблокирована попытка входа.</p><?php endif; ?>
<form method="post" action="/api/authorization" class="auth-form"><input type="hidden" name="csrf" value="<?= e($csrfToken) ?>"><label for="login">Логин</label><input id="login" name="login" type="text" autocomplete="username" required maxlength="255"><label for="password">Пароль</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="1024"><button type="submit">Войти</button></form><div class="col"><p class="center">Зашли случайно?</p><a class="back-link" href="/">Вернуться на сайт</a></div></main></body></html>
