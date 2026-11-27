<?php
declare(strict_types=1);
require_once __DIR__ . '/connect.php';

const AUTH_COOKIE = 'xwors';
const CSRF_COOKIE = 'xwors_csrf';
const PREAUTH_COOKIE = 'xwors_2fa';
const AUTH_TTL = 86400;
const PREAUTH_TTL = 300;
const AUTH_RECHECK = 300;
const AUTH_DUMMY_BCRYPT = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

function auth_secret(): string
{
    $secret = env_get('AUTH_SECRET');
    if (!$secret || strlen($secret) < 32) {
        throw new RuntimeException('AUTH_SECRET must be configured and contain at least 32 characters');
    }
    return $secret;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Stable CSRF seed across signed-cookie refreshes and multiple tabs. */
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

function csrf_token(): string
{
    return hash_hmac('sha256', 'xwors-admin-csrf|' . csrf_seed(), auth_secret());
}

/** Apply a bounded sliding-window limit to login and second-factor submissions. */
function auth_login_rate_limited(): bool
{
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    if (!in_array($path, ['/api/authorization', '/api/verify-2fa'], true)) return false;
    try {
        $pdo = db();
        $pdo->exec('CREATE TABLE IF NOT EXISTS auth_attempts (scope_key CHAR(64) NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())');
        $pdo->exec('CREATE INDEX IF NOT EXISTS auth_attempts_created_idx ON auth_attempts (created_at)');
        $pdo->exec("DELETE FROM auth_attempts WHERE created_at < NOW() - INTERVAL '1 day'");
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $identity = $path === '/api/authorization'
            ? strtolower(trim((string)($_POST['login'] ?? '')))
            : (string)(pending_auth_user() ?? 'no-pending-session');
        $keys = [
            hash_hmac('sha256', 'ip|' . $ip . '|' . $path, auth_secret()),
            hash_hmac('sha256', 'identity|' . $identity . '|' . $path, auth_secret()),
        ];
        $limit = $path === '/api/verify-2fa' ? 5 : 10;
        foreach ($keys as $key) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE scope_key = :key AND created_at > NOW() - INTERVAL '15 minutes'");
            $stmt->execute([':key' => $key]);
            if ((int)$stmt->fetchColumn() >= $limit) return true;
        }
        $stmt = $pdo->prepare('INSERT INTO auth_attempts (scope_key) VALUES (:ip), (:identity)');
        $stmt->execute([':ip' => $keys[0], ':identity' => $keys[1]]);
        return false;
    } catch (Throwable $e) {
        error_log('Authentication rate limiter failed: ' . $e->getMessage());
        return true; // Fail closed rather than silently disabling login protection.
    }
}

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

function auth_is_https(): bool
{
    return env_get('VERCEL') !== null
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

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
    // Keep this request and CSRF generation consistent with the Set-Cookie sent to the browser.
    $_COOKIE[$name] = $value;
}

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

function auth_pending_login(string $login): void
{
    set_signed_cookie(PREAUTH_COOKIE, ['login' => $login, 'purpose' => '2fa', 'sid' => bin2hex(random_bytes(32))], PREAUTH_TTL);
}

function pending_auth_user(): ?string
{
    $data = read_signed_cookie(PREAUTH_COOKIE);
    if (!$data || ($data['purpose'] ?? '') !== '2fa') return null;
    return $data['login'];
}

function clear_cookie(string $name): void
{
    setcookie($name, '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => auth_is_https(),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    unset($_COOKIE[$name]);
}

function auth_user(): ?string
{
    $data = read_signed_cookie(AUTH_COOKIE);
    if (!$data || ($data['purpose'] ?? '') !== 'session') return null;
    return $data['login'];
}

/** Find an administrator by verifying the bcrypt-hashed login stored in admins.login. */
function admin_by_login(string $login): ?array
{
    $admins = db()->query('SELECT login, password, "2fa" FROM admins')->fetchAll();
    foreach ($admins as &$row) {
        $settings = json_decode((string)($row['2fa'] ?? ''), true);
        $row['2fa'] = is_array($settings) ? $settings : ['enabled' => 0, 'secret' => null];
    }
    unset($row);
    $found = null;
    foreach ($admins as $admin) {
        $storedLogin = is_string($admin['login'] ?? null) ? $admin['login'] : '';
        if (password_verify($login, $storedLogin !== '' ? $storedLogin : AUTH_DUMMY_BCRYPT) && $storedLogin !== '') $found = $admin;
    }
    // Always perform a bcrypt operation when no login matched to reduce the username timing oracle.
    if ($found === null) password_verify($login, AUTH_DUMMY_BCRYPT);
    return $found;
}

function admin_auth_state(array $admin): string
{
    return hash_hmac('sha256', (string)($admin['password'] ?? '') . "\0" . json_encode($admin['2fa'] ?? [], JSON_UNESCAPED_SLASHES), auth_secret());
}

function admin_login_exists(string $login): bool
{
    return admin_by_login($login) !== null;
}

/** Recheck administrator existence and security settings at the bounded refresh interval. */
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
    $data['chk'] = time();
    if (!headers_sent()) set_signed_cookie(AUTH_COOKIE, $data, AUTH_TTL);
    return $login;
}

function auth_logout(): void
{
    clear_cookie(AUTH_COOKIE);
    clear_cookie(PREAUTH_COOKIE);
}

/* RFC 6238 TOTP, compatible with Google Authenticator and Aegis. */
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

function totp_generate_secret(): string
{
    return totp_base32_encode(random_bytes(20));
}

function totp_code(string $secret, ?int $timestamp = null): string|false
{
    $key = totp_base32_decode($secret);
    if ($key === false || $key === '') return false;
    $counter = intdiv($timestamp ?? time(), 30);
    $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24) | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8) | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Validate and atomically consume a TOTP time step, preventing replay within the accepted window. */
function totp_verify(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $now = time();
    $matched = null;
    foreach ([-30, 0, 30] as $offset) {
        $step = intdiv($now + $offset, 30);
        $expected = totp_code($secret, ($now + $offset));
        if (is_string($expected) && hash_equals($expected, $code)) $matched = max($matched ?? 0, $step);
    }
    if ($matched === null) return false;
    try {
        $pdo = db();
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
