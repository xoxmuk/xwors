<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function save_2fa(string $login, array $settings): void
{
    $stmt = db()->prepare('UPDATE admins SET "2fa" = CAST(:settings AS jsonb) WHERE login = :login');
    $stmt->execute([':settings' => json_encode($settings, JSON_THROW_ON_ERROR), ':login' => $login]);
}

function handle_action(string $action): void
{
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method not allowed');
    }

    try {
        if (!csrf_valid()) {
            http_response_code(403);
            exit('Invalid request');
        }

        switch ($action) {
            case 'logout':
                if (auth_user() === null) { http_response_code(403); exit('Not authenticated'); }
                auth_logout();
                redirect('/admin');

            case 'authorization':
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

            case 'verify-2fa':
                $login = pending_auth_user();
                if ($login === null) redirect('/admin?error=expired');
                $admin = admin_by_login($login);
                if (!$admin || empty($admin['2fa']['enabled']) || empty($admin['2fa']['secret']) ||
                    !totp_verify((string)$admin['2fa']['secret'], (string)($_POST['code'] ?? ''))) {
                    redirect('/admin?step=2fa&error=1');
                }
                auth_login($login);
                redirect('/admin/dashboard/');

            case 'setup-2fa':
                $login = require_admin();
                $admin = admin_by_login($login);
                if (!$admin) redirect('/admin');
                if (!empty($admin['2fa']['enabled'])) redirect('/admin/dashboard/security');
                if (empty($admin['2fa']['secret'])) {
                    save_2fa($admin['login'], ['enabled' => 0, 'secret' => totp_generate_secret()]);
                }
                redirect('/admin/dashboard/security');

            case 'enable-2fa':
                $login = require_admin();
                $admin = admin_by_login($login);
                $code = (string)($_POST['code'] ?? '');
                if (!$admin || empty($admin['2fa']['secret']) || !totp_verify((string)$admin['2fa']['secret'], $code)) {
                    redirect('/admin/dashboard/security?error=code');
                }
                save_2fa($admin['login'], ['enabled' => 1, 'secret' => $admin['2fa']['secret']]);
                redirect('/admin/dashboard/security?enabled=1');

            case 'disable-2fa':
                $login = require_admin();
                $admin = admin_by_login($login);
                $code = (string)($_POST['code'] ?? '');
                if (!$admin || empty($admin['2fa']['enabled']) || empty($admin['2fa']['secret']) ||
                    !totp_verify((string)$admin['2fa']['secret'], $code)) {
                    redirect('/admin/dashboard/security?error=code');
                }
                save_2fa($admin['login'], ['enabled' => 0, 'secret' => null]);
                redirect('/admin/dashboard/security?disabled=1');

            default:
                http_response_code(404);
                exit('Not found');
        }
    } catch (Throwable $e) {
        error_log('XWORS admin API error: ' . $e->getMessage());
        http_response_code(503);
        exit('Database unavailable');
    }
}
