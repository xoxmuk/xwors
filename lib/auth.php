<?php
/**
 * Аутентификация администратора: подписанные cookie-сессии, CSRF-защита,
 * ограничение частоты попыток входа и двухфакторная авторизация (TOTP, RFC 6238).
 */
declare(strict_types=1);
require_once __DIR__ . '/connect.php';

const AUTH_COOKIE = 'xwors';          // имя cookie основной сессии администратора
const CSRF_COOKIE = 'xwors_csrf';     // запасная cookie с «зерном» CSRF для неавторизованных посетителей
const PREAUTH_COOKIE = 'xwors_2fa';   // временная cookie между вводом пароля и вводом кода 2FA
const AUTH_TTL = 86400;               // время жизни сессии: 24 часа
const PREAUTH_TTL = 300;              // время на ввод кода 2FA после пароля: 5 минут
const AUTH_RECHECK = 300;             // как часто (сек.) перепроверять в БД, что админ существует и настройки не менялись
// Фиктивный bcrypt-хэш: используется, чтобы время проверки не выдавало, существует ли такой логин
const AUTH_DUMMY_BCRYPT = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

/** Возвращает секрет для подписи cookie и CSRF-токенов (AUTH_SECRET, минимум 32 символа). */
function auth_secret(): string
{
    $secret = env_get('AUTH_SECRET');
    if (!$secret || strlen($secret) < 32) {
        throw new RuntimeException('AUTH_SECRET must be configured and contain at least 32 characters');
    }
    return $secret;
}

/** Экранирует строку для безопасного вывода в HTML (защита от XSS). */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Перенаправляет на указанный адрес (код 303 — после POST браузер переходит по ссылке методом GET) и завершает скрипт. */
function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/**
 * «Зерно» для CSRF-токена. Оно стабильно при обновлении подписанных cookie и между вкладками:
 * берётся из идентификатора сессии (sid), а если сессии нет — из отдельной cookie, которая создаётся при необходимости.
 */
function csrf_seed(): string
{
    foreach ([AUTH_COOKIE, PREAUTH_COOKIE] as $name) {
        $data = read_signed_cookie($name);
        if ($data && is_string($data['sid'] ?? null)) return $data['sid'];
    }
    $v = $_COOKIE[CSRF_COOKIE] ?? null;
    if (is_string($v) && $v !== '') return $v;
    $seed = bin2hex(random_bytes(32));
    if (!headers_sent()) {
        setcookie(CSRF_COOKIE, $seed, [
            'expires' => time() + 86400, 'path' => '/', 'secure' => auth_is_https(),
            'httponly' => true, 'samesite' => 'Strict',
        ]);
    }
    $_COOKIE[CSRF_COOKIE] = $seed;
    return $seed;
}

/** CSRF-токен формы: HMAC от зерна с серверным секретом. Вставляется в скрытое поле csrf каждой формы. */
function csrf_token(): string
{
    return hash_hmac('sha256', 'xwors-admin-csrf|' . csrf_seed(), auth_secret());
}

/**
 * Ограничение частоты попыток входа и ввода кода 2FA (скользящее окно 15 минут).
 * Лимиты: 10 попыток на вход, 5 — на код 2FA; считаются отдельно по IP и по логину.
 * Возвращает true, если лимит превышен.
 */
function auth_login_rate_limited(): bool
{
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    // Ограничиваем только два действия: вход по паролю и проверку кода 2FA
    if (!in_array($path, ['/api/authorization', '/api/verify-2fa'], true)) return false;
    try {
        $pdo = db();
        // Таблица попыток создаётся «на лету»; записи старше суток удаляются
        $pdo->exec('CREATE TABLE IF NOT EXISTS auth_attempts (scope_key CHAR(64) NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())');
        $pdo->exec('CREATE INDEX IF NOT EXISTS auth_attempts_created_idx ON auth_attempts (created_at)');
        $pdo->exec("DELETE FROM auth_attempts WHERE created_at < NOW() - INTERVAL '1 day'");
        // Ключи счётчиков (хэшированные, чтобы не хранить IP и логины в открытом виде): по IP и по «личности» (логин или ожидающая 2FA-сессия)
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $identity = $path === '/api/authorization'
            ? strtolower(trim((string)($_POST['login'] ?? '')))
            : (string)(pending_auth_user() ?? 'no-pending-session');
        $keys = [
            hash_hmac('sha256', 'ip|' . $ip . '|' . $path, auth_secret()),
            hash_hmac('sha256', 'identity|' . $identity . '|' . $path, auth_secret()),
        ];
        // Для кода 2FA лимит строже: у шестизначного кода меньше вариантов перебора
        $limit = $path === '/api/verify-2fa' ? 5 : 10;
        foreach ($keys as $key) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE scope_key = :key AND created_at > NOW() - INTERVAL '15 minutes'");
            $stmt->execute([':key' => $key]);
            if ((int)$stmt->fetchColumn() >= $limit) return true;
        }
        // Лимит не исчерпан — записываем текущую попытку
        $stmt = $pdo->prepare('INSERT INTO auth_attempts (scope_key) VALUES (:ip), (:identity)');
        $stmt->execute([':ip' => $keys[0], ':identity' => $keys[1]]);
        return false;
    } catch (Throwable $e) {
        error_log('Authentication rate limiter failed: ' . $e->getMessage());
        return true; // При сбое блокируем вход, а не молча отключаем защиту от перебора.
    }
}

/**
 * Проверяет CSRF-токен из POST и заодно применяет ограничение частоты попыток.
 * При превышении лимита отвечает кодом 429 и возвращает false.
 */
function csrf_valid(): bool
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) return false;
    if (auth_login_rate_limited()) {
        http_response_code(429);
        header('Retry-After: 900');
        return false;
    }
    return true;
}

/** Определяет, работает ли сайт по HTTPS (Vercel, прямое HTTPS-соединение или заголовок прокси) — для флага Secure у cookie. */
function auth_is_https(): bool
{
    return env_get('VERCEL') !== null
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * Устанавливает подписанную cookie: данные кодируются в base64url и подписываются HMAC-SHA256,
 * поэтому подделать или изменить содержимое нельзя. Cookie HttpOnly и SameSite=Strict.
 */
function set_signed_cookie(string $name, array $data, int $ttl): void
{
    $data['exp'] = (int)($data['exp'] ?? (time() + $ttl));
    $payload = rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $payload, auth_secret());
    $value = $payload . '.' . $signature;
    setcookie($name, $value, [
        'expires' => $data['exp'], 'path' => '/', 'secure' => auth_is_https(),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    // Сразу обновляем $_COOKIE, чтобы текущий запрос и генерация CSRF соответствовали Set-Cookie, отправленному браузеру.
    $_COOKIE[$name] = $value;
}

/**
 * Читает и проверяет подписанную cookie. Возвращает данные или null, если cookie нет,
 * подпись неверна, формат нарушен или срок действия истёк.
 */
function read_signed_cookie(string $name): ?array
{
    $cookie = $_COOKIE[$name] ?? '';
    if (!is_string($cookie) || !str_contains($cookie, '.')) return null;
    [$payload, $signature] = explode('.', $cookie, 2);
    if (!hash_equals(hash_hmac('sha256', $payload, auth_secret()), $signature)) return null;
    $json = base64_decode(strtr($payload, '-_', '+/'), true);
    $data = $json === false ? null : json_decode($json, true);
    if (!is_array($data) || !is_string($data['login'] ?? null) || (int)($data['exp'] ?? 0) < time()) return null;
    return $data;
}

/** Открывает сессию администратора: проверяет, что логин существует, и выдаёт подписанную cookie с «отпечатком» настроек безопасности. */
function auth_login(string $login): void
{
    $admin = admin_by_login($login);
    if (!$admin) throw new RuntimeException('Administrator no longer exists');
    set_signed_cookie(AUTH_COOKIE, [
        'login' => $login, 'purpose' => 'session', 'chk' => time(),
        'sid' => bin2hex(random_bytes(32)), 'state' => admin_auth_state($admin),
    ], AUTH_TTL);
    clear_cookie(PREAUTH_COOKIE);
}

/** Пароль подошёл, но нужен код 2FA: выдаём короткоживущую промежуточную cookie. */
function auth_pending_login(string $login): void
{
    set_signed_cookie(PREAUTH_COOKIE, ['login' => $login, 'purpose' => '2fa', 'sid' => bin2hex(random_bytes(32))], PREAUTH_TTL);
}

/** Логин пользователя, ожидающего ввода кода 2FA (или null, если такой сессии нет). */
function pending_auth_user(): ?string
{
    $data = read_signed_cookie(PREAUTH_COOKIE);
    if (!$data || ($data['purpose'] ?? '') !== '2fa') return null;
    return $data['login'];
}

/** Удаляет cookie (ставит срок действия в прошлом). */
function clear_cookie(string $name): void
{
    setcookie($name, '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => auth_is_https(),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    unset($_COOKIE[$name]);
}

/** Логин вошедшего администратора по cookie (без обращения к БД) или null. */
function auth_user(): ?string
{
    $data = read_signed_cookie(AUTH_COOKIE);
    if (!$data || ($data['purpose'] ?? '') !== 'session') return null;
    return $data['login'];
}

/**
 * Ищет администратора по логину. В таблице admins логин хранится как bcrypt-хэш,
 * поэтому прямой поиск невозможен — проверяем введённый логин по хэшу каждой записи.
 * Возвращает строку админа (с разобранными настройками 2FA) или null.
 */
function admin_by_login(string $login): ?array
{
    $admins = db()->query('SELECT login, password, "2fa" FROM admins')->fetchAll();
    foreach ($admins as &$row) {
        $settings = json_decode((string)($row['2fa'] ?? ''), true);
        $row['2fa'] = is_array($settings) ? $settings : ['enabled' => 0, 'secret' => null];
    }
    unset($row);
    // Перебираем всех админов; цикл не прерывается досрочно, чтобы время ответа не зависело от позиции записи
    $found = null;
    foreach ($admins as $admin) {
        $storedLogin = is_string($admin['login'] ?? null) ? $admin['login'] : '';
        if (password_verify($login, $storedLogin !== '' ? $storedLogin : AUTH_DUMMY_BCRYPT) && $storedLogin !== '') $found = $admin;
    }
    // Если логин не найден, всё равно выполняем bcrypt-проверку — так по времени ответа нельзя угадать существующие логины.
    if ($found === null) password_verify($login, AUTH_DUMMY_BCRYPT);
    return $found;
}

/**
 * «Отпечаток» пароля и настроек 2FA администратора. Хранится в сессии; если пароль или 2FA изменились,
 * отпечаток перестаёт совпадать, и старая сессия аннулируется.
 */
function admin_auth_state(array $admin): string
{
    return hash_hmac('sha256', (string)($admin['password'] ?? '') . "\0" . json_encode($admin['2fa'] ?? [], JSON_UNESCAPED_SLASHES), auth_secret());
}

/** Проверяет, существует ли администратор с таким логином. */
function admin_login_exists(string $login): bool
{
    return admin_by_login($login) !== null;
}

/**
 * Требует авторизации администратора: без валидной сессии перенаправляет на /admin.
 * Раз в AUTH_RECHECK секунд сверяет с БД, что админ существует и его пароль/2FA не менялись.
 * Возвращает логин администратора.
 */
function require_admin(): string
{
    $data = read_signed_cookie(AUTH_COOKIE);
    if (!$data || ($data['purpose'] ?? '') !== 'session') redirect('/admin');
    $login = $data['login'];
    if ((int)($data['chk'] ?? 0) > time() - AUTH_RECHECK) return $login;
    try {
        $admin = admin_by_login($login);
        if (!$admin || !is_string($data['state'] ?? null) || !hash_equals(admin_auth_state($admin), $data['state'])) {
            auth_logout();
            redirect('/admin');
        }
    } catch (Throwable $e) {
        error_log('Admin authentication check failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Database unavailable');
    }
    // Обновляем время последней проверки и продлеваем cookie
    $data['chk'] = time();
    if (!headers_sent()) set_signed_cookie(AUTH_COOKIE, $data, AUTH_TTL);
    return $login;
}

/** Выход: удаляет обе cookie (сессии и ожидания 2FA). */
function auth_logout(): void
{
    clear_cookie(AUTH_COOKIE);
    clear_cookie(PREAUTH_COOKIE);
}

/* ---- TOTP по RFC 6238 — совместим с Google Authenticator и Aegis ---- */

/** Кодирует байты в Base32 (алфавит A–Z, 2–7) — в таком виде секрет вводится в приложение-аутентификатор. */
function totp_base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $char) $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

/** Декодирует Base32-строку обратно в байты; возвращает false при недопустимых символах. */
function totp_base32_decode(string $value): string|false
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
    $bits = '';
    foreach (str_split($value) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) return false;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $bytes .= chr(bindec($chunk));
    return $bytes;
}

/** Генерирует новый секрет 2FA: 20 случайных байт в Base32. */
function totp_generate_secret(): string
{
    return totp_base32_encode(random_bytes(20));
}

/**
 * Вычисляет шестизначный TOTP-код для секрета на момент времени (шаг — 30 секунд, алгоритм HMAC-SHA1).
 * Возвращает false, если секрет некорректен.
 */
function totp_code(string $secret, ?int $timestamp = null): string|false
{
    $key = totp_base32_decode($secret);
    if ($key === false || $key === '') return false;
    // Счётчик — номер 30-секундного интервала; упаковываем его в 8 байт (big-endian)
    $counter = intdiv($timestamp ?? time(), 30);
    $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    // Динамическое усечение (dynamic truncation) по RFC 4226: из хэша берутся 4 байта начиная со смещения
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24) | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8) | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Проверяет введённый код и атомарно «погашает» использованный интервал времени,
 * чтобы один и тот же код нельзя было применить повторно (защита от replay-атаки).
 */
function totp_verify(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $now = time();
    // Допускаем отклонение часов на ±30 секунд: проверяем предыдущий, текущий и следующий интервалы
    $matched = null;
    foreach ([-30, 0, 30] as $offset) {
        $step = intdiv($now + $offset, 30);
        $expected = totp_code($secret, ($now + $offset));
        if (is_string($expected) && hash_equals($expected, $code)) $matched = max($matched ?? 0, $step);
    }
    if ($matched === null) return false;
    try {
        $pdo = db();
        // Журнал использованных интервалов; вставка срабатывает (rowCount = 1) только при первом применении кода
        $pdo->exec('CREATE TABLE IF NOT EXISTS used_totp_steps (secret_key CHAR(64) NOT NULL, time_step BIGINT NOT NULL, used_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), PRIMARY KEY (secret_key, time_step))');
        $key = hash_hmac('sha256', $secret, auth_secret());
        $stmt = $pdo->prepare('INSERT INTO used_totp_steps (secret_key, time_step) VALUES (:key, :step) ON CONFLICT DO NOTHING');
        $stmt->execute([':key' => $key, ':step' => $matched]);
        return $stmt->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('TOTP replay protection failed: ' . $e->getMessage());
        return false;
    }
}
