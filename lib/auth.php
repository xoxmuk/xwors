<?php
declare(strict_types=1);
require_once __DIR__ . '/connect.php';

const AUTH_COOKIE = 'xwors';
const CSRF_COOKIE = 'xwors_csrf';
const PREAUTH_COOKIE = 'xwors_2fa';
const AUTH_TTL = 86400;
const PREAUTH_TTL = 300;

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

function csrf_seed(): string
{
    foreach ([AUTH_COOKIE, PREAUTH_COOKIE, CSRF_COOKIE] as $name) {
        $v = $_COOKIE[$name] ?? null;
        if (is_string($v) && $v !== '') return $v;
    }
    // Anonymous visitor (login page): per-browser random seed, set before any output.
    $seed = bin2hex(random_bytes(16));
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

function csrf_valid(): bool
{
    $token = $_POST['csrf'] ?? '';
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function auth_is_https(): bool
{
    return env_get('VERCEL') !== null
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function set_signed_cookie(string $name, array $data, int $ttl): void
{
    $data['exp'] = time() + $ttl;
    $payload = rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $payload, auth_secret());
    setcookie($name, $payload . '.' . $signature, [
        'expires' => time() + $ttl, 'path' => '/', 'secure' => auth_is_https(),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
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
    set_signed_cookie(AUTH_COOKIE, ['login' => $login, 'purpose' => 'session'], AUTH_TTL);
    clear_cookie(PREAUTH_COOKIE);
}

function auth_pending_login(string $login): void
{
    set_signed_cookie(PREAUTH_COOKIE, ['login' => $login, 'purpose' => '2fa'], PREAUTH_TTL);
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
    foreach ($admins as $admin) {
        if (is_string($admin['login'] ?? null) && password_verify($login, $admin['login'])) return $admin;
    }
    return null;
}

function admin_login_exists(string $login): bool
{
    return admin_by_login($login) !== null;
}

function require_admin(): string
{
    $login = auth_user();
    if ($login === null) redirect('/admin');
    try {
        if (!admin_login_exists($login)) {
            auth_logout();
            redirect('/admin');
        }
    } catch (Throwable $e) {
        error_log('Admin authentication check failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Database unavailable');
    }
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
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) $bytes .= chr(bindec($chunk));
    }
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
    $high = intdiv($counter, 4294967296);
    $low = $counter % 4294967296;
    $binaryCounter = pack('N2', $high, $low);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function totp_verify(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $now = time();
    foreach ([-30, 0, 30] as $offset) {
        $expected = totp_code($secret, $now + $offset);
        if (is_string($expected) && hash_equals($expected, $code)) return true;
    }
    return false;
}
