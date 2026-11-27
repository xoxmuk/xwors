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

function tip(string $text): string
{
    return '<span class="info-tip tooltip" tabindex="0" role="note" tooltip-text="' . e($text) . '">?</span>';
}
?>
<section class="panel-card security-card">
    <div class="security-head">
        <div>
            <h2>Двухэтапная авторизация <?= tip('После пароля при каждом новом входе нужно вводить одноразовый 6-значный код из приложения Google Authenticator, Aegis или другого TOTP-приложения.') ?></h2>
            <p>Дополнительная защита входа в админку.</p>
        </div>
        <span class="security-pill <?= $enabled ? 'on' : 'off' ?> tooltip" tooltip-position="left" tabindex="0" tooltip-text="<?= $enabled ? 'Вход в админку защищён кодом из приложения.' : 'Сейчас вход защищён только паролем.' ?>"><i></i><?= $enabled ? 'Включена' : 'Не включена' ?></span>
    </div>
<?php if (isset($_GET['enabled'])): ?><p class="success-message flash">Двухэтапная авторизация успешно включена.</p><?php endif; ?>
<?php if (isset($_GET['disabled'])): ?><p class="success-message flash">Двухэтапная авторизация отключена.</p><?php endif; ?>
<?php if (isset($_GET['error'])): ?><p class="form-error flash">Код неверный или устарел. Проверь время на телефоне и попробуй снова.</p><?php endif; ?>

<?php if ($enabled): ?>
    <div class="security-divider"></div>
    <h3>Отключение 2FA <?= tip('Для отключения нужен текущий код из приложения — так никто посторонний не сможет снять защиту.') ?></h3>
    <form method="post" action="/api/disable-2fa" class="security-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label for="disable-code">Текущий код</label>
        <div class="security-form-row">
            <input id="disable-code" class="otp-input" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000 000" autocomplete="one-time-code" required>
            <button type="submit" class="danger-button">Отключить 2FA</button>
        </div>
    </form>
<?php elseif ($secret !== ''): ?>
    <div class="security-divider"></div>
    <h3>Подключение аутентификатора <?= tip('Отсканируй QR-код в Google Authenticator, Aegis или другом TOTP-приложении. Если камера недоступна, введи секретный ключ вручную.') ?></h3>
    <div class="totp-layout">
        <div class="totp-side">
            <div id="totp-qr" class="totp-qr" data-qr="<?= e($otpauth) ?>" aria-label="QR-код для добавления в аутентификатор"></div>
            <a class="totp-open tooltip" href="<?= e($otpauth) ?>" tooltip-text="Откроется установленное приложение-аутентификатор на этом устройстве">Открыть в приложении</a>
        </div>
        <div class="totp-main">
            <div class="field-block">
                <span class="secret-label">Секретный ключ <?= tip('Нужен, если нельзя отсканировать QR-код. Храни его в надёжном месте: с ним можно восстановить 2FA на новом устройстве.') ?></span>
                <div class="secret-value"><code id="totp-secret"><?= e($secret) ?></code><button type="button" class="copy-secret" data-copy="#totp-secret">Копировать</button></div>
            </div>
            <form method="post" action="/api/enable-2fa" class="security-form">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <label for="enable-code">Код аутентификатора <?= tip('6 цифр из приложения. Код меняется каждые 30 секунд — если не подходит, подожди новый и проверь время на телефоне.') ?></label>
                <input id="enable-code" class="otp-input" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000 000" autocomplete="one-time-code" required>
                <button type="submit" class="security-submit">Подтвердить и включить 2FA</button>
            </form>
            <p class="security-foot">🔒 QR-код создаётся прямо в браузере <?= tip('Секретный ключ не отправляется на сторонние сервисы.') ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="security-divider"></div>
    <form method="post" action="/api/setup-2fa" class="security-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="security-submit">Добавить Google Authenticator</button>
    </form>
<?php endif; ?>
</section>
<section class="panel-card security-note">
    <span class="security-note-icon">!</span>
    <div>
        <h3>Важно</h3>
        <p>Сохрани секретный ключ в надёжном месте. Если потеряешь доступ к приложению или ключу, 2FA можно снести через БД <?= tip('UPDATE admins SET "2fa" = \'{"enabled":0,"secret":null}\'') ?></p>
    </div>
</section>
<?php dash_end(); ?>
