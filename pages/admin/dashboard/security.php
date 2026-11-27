<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

$adminLogin = dash_login();
try {
    $admin = admin_by_login($adminLogin);
} catch (Throwable $e) {
    error_log('Security page error: ' . $e->getMessage());
    http_response_code(503);
    exit('Database unavailable');
}
if (!$admin) { http_response_code(403); exit('Administrator not found'); }

$twoFactor = is_array($admin['2fa'] ?? null) ? $admin['2fa'] : ['enabled' => 0, 'secret' => null];
$enabled = !empty($twoFactor['enabled']);
$secret = (string)($twoFactor['secret'] ?? '');
$csrf = csrf_token();
$issuer = rawurlencode('XWorsClient');
$account = rawurlencode($adminLogin);
$otpauth = $secret !== ''
    ? "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30"
    : '';

dash_start('security', 'Безопасность');
?>
<section class="panel-card security-card">
    <h2>Двухэтапная авторизация</h2>
    <p>Подключи Google Authenticator или совместимое приложение Aegis. После включения при каждом новом входе после пароля потребуется одноразовый шестизначный код.</p>
    <div class="security-status"><span>Статус защиты</span><strong class="<?= $enabled ? 'security-on' : 'security-off' ?>"><?= $enabled ? 'Включена' : 'Не включена' ?></strong></div>
<?php if (isset($_GET['enabled'])): ?><p class="success-message">Двухэтапная авторизация успешно включена.</p><?php endif; ?>
<?php if (isset($_GET['disabled'])): ?><p class="success-message">Двухэтапная авторизация отключена.</p><?php endif; ?>
<?php if (isset($_GET['error'])): ?><p class="form-error">Код неверный или устарел. Проверь время на телефоне и попробуй снова.</p><?php endif; ?>

<?php if ($enabled): ?>
    <h3>Отключение 2FA</h3>
    <p>Для отключения введи текущий код из приложения-аутентификатора.</p>
    <form method="post" action="/api/disable-2fa" class="auth-form inline-auth-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label for="disable-code">Текущий код</label>
        <input id="disable-code" class="otp-input" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required>
        <button type="submit" class="danger-button">Отключить 2FA</button>
    </form>
<?php elseif ($secret !== ''): ?>
    <h3>Завершение настройки</h3>
    <p>В Google Authenticator выбери добавление аккаунта по ключу настройки и введи данные ниже.</p>
    <label class="secret-label" for="totp-secret">Секретный ключ</label>
    <div class="secret-value"><code id="totp-secret"><?= e($secret) ?></code><button type="button" class="copy-secret" data-copy="#totp-secret">Копировать</button></div>
    <a href="<?= e($otpauth) ?>">Открыть в приложении</a>
    <form method="post" action="/api/enable-2fa" class="auth-form inline-auth-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label for="enable-code">Код из Google Authenticator</label>
        <input id="enable-code" class="otp-input" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required>
        <button type="submit">Подтвердить и включить 2FA</button>
    </form>
<?php else: ?>
    <form method="post" action="/api/setup-2fa" class="auth-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button type="submit">Добавить Google Authenticator</button>
    </form>
<?php endif; ?>
</section>
<section class="panel-card"><h3>Важно</h3><p>Сохрани секретный ключ в надёжном месте. Если потеряешь доступ к приложению или секретному ключу, снеси 2FA через БД.</p></section>
<?php dash_end(); ?>
