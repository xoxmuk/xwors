<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
header('Cache-Control: no-store');

try {
    if (auth_user() !== null) { require_admin(); redirect('/admin/dashboard/'); }
    $csrfToken = csrf_token();
    $step2fa = ($_GET['step'] ?? '') === '2fa' && pending_auth_user() !== null;
} catch (Throwable $e) {
    error_log('Admin login page error: ' . $e->getMessage());
    http_response_code(503);
    exit('Не удалось проверить авторизацию. Проверьте подключение к базе данных и AUTH_SECRET.');
}

$error = null;
if (($_GET['error'] ?? '') === 'expired' || (($_GET['step'] ?? '') === '2fa' && !$step2fa)) {
    $error = 'Сессия входа истекла. Войдите заново.';
} elseif (isset($_GET['error'])) {
    $error = $step2fa ? 'Неверный или устаревший код.' : 'Неверный логин или пароль.';
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Авторизация — XWorsClient</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="/styles/index.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <h1 class="center"><?= $step2fa ? 'Подтверждение входа' : 'Авторизация' ?></h1>
<?php if ($error): ?>
    <p class="form-error"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($step2fa): ?>
    <p class="center">Введите 6-значный код из приложения-аутентификатора.</p>
    <form method="post" action="/api/verify-2fa" class="auth-form">
        <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
        <label for="code">Код</label>
        <input id="code" class="otp-input" name="code" type="text" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" autofocus required>
        <button type="submit">Подтвердить</button>
    </form>
    <a class="back-link" href="/admin">Начать заново</a>
<?php else: ?>
    <form method="post" action="/api/authorization" class="auth-form">
        <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
        <label for="login">Логин</label>
        <input id="login" name="login" type="text" autocomplete="username" required maxlength="255">
        <label for="password">Пароль</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="1024">
        <button type="submit">Войти</button>
    </form>
    <div class="col">
        <p class="center">Зашли случайно?</p>
        <a class="back-link" href="/">Вернуться на сайт</a>
    </div>
<?php endif; ?>
</main>
</body>
</html>
