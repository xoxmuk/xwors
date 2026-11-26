<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
header('Cache-Control: no-store');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$action = basename(rtrim((string)$path, '/'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    http_response_code(403);
    exit('Invalid request');
}

try {
    if ($action === 'logout') {
        if (auth_user() === null) { http_response_code(403); exit('Not authenticated'); }
        auth_logout();
        redirect('/admin');
    }

    if ($action === 'authorization') {
        $login = trim((string)($_POST['login'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($login === '' || $password === '') redirect('/admin?error=1');

        $admin = admin_by_login($login);
        if (!$admin || !password_verify($password, (string)$admin['password'])) {
            redirect('/admin?error=1');
        }

        if (!empty($admin['2fa']['enabled'])) {
            auth_pending_login($login);
            redirect('/admin?step=2fa');
        }

        auth_login($login);
        redirect('/admin/dashboard/');
    }

    if ($action === 'verify-2fa') {
        $login = pending_auth_user();
        if ($login === null) redirect('/admin?error=2fa');
        $admin = admin_by_login($login);
        if (!$admin || empty($admin['2fa']['enabled']) || empty($admin['2fa']['secret']) ||
            !totp_verify((string)$admin['2fa']['secret'], (string)($_POST['code'] ?? ''))) {
            redirect('/admin?step=2fa&error=1');
        }
        auth_login($login);
        redirect('/admin/dashboard/');
    }

    if ($action === 'setup-2fa') {
        $login = require_admin();
        $admin = admin_by_login($login);
        if (!$admin) redirect('/admin');
        if (!empty($admin['2fa']['enabled'])) redirect('/admin/dashboard/security');
        if (empty($admin['2fa']['secret'])) {
            $settings = ['enabled' => 0, 'secret' => totp_generate_secret()];
            $stmt = db()->prepare('UPDATE admins SET "2fa" = CAST(:settings AS jsonb) WHERE login = :login');
            $stmt->execute([':settings' => json_encode($settings, JSON_THROW_ON_ERROR), ':login' => $admin['login']]);
        }
        redirect('/admin/dashboard/security');
    }

    if ($action === 'enable-2fa') {
        $login = require_admin();
        $admin = admin_by_login($login);
        $code = (string)($_POST['code'] ?? '');
        if (!$admin || empty($admin['2fa']['secret']) || !totp_verify((string)$admin['2fa']['secret'], $code)) {
            redirect('/admin/dashboard/security?error=code');
        }
        $settings = ['enabled' => 1, 'secret' => $admin['2fa']['secret']];
        $stmt = db()->prepare('UPDATE admins SET "2fa" = CAST(:settings AS jsonb) WHERE login = :login');
        $stmt->execute([':settings' => json_encode($settings, JSON_THROW_ON_ERROR), ':login' => $admin['login']]);
        redirect('/admin/dashboard/security?enabled=1');
    }

    if ($action === 'disable-2fa') {
        $login = require_admin();
        $admin = admin_by_login($login);
        $code = (string)($_POST['code'] ?? '');
        if (!$admin || empty($admin['2fa']['enabled']) || empty($admin['2fa']['secret']) ||
            !totp_verify((string)$admin['2fa']['secret'], $code)) {
            redirect('/admin/dashboard/security?error=code');
        }
        $settings = ['enabled' => 0, 'secret' => null];
        $stmt = db()->prepare('UPDATE admins SET "2fa" = CAST(:settings AS jsonb) WHERE login = :login');
        $stmt->execute([':settings' => json_encode($settings, JSON_THROW_ON_ERROR), ':login' => $admin['login']]);
        redirect('/admin/dashboard/security?disabled=1');
    }

    http_response_code(404);
    exit('Not found');
} catch (Throwable $e) {
    error_log('XWORS admin API error: ' . $e->getMessage());
    http_response_code(503);
    exit('Database unavailable');
}
