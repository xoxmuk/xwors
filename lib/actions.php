<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/content.php';

function save_2fa(string $login, array $settings): void
{
    $stmt = db()->prepare('UPDATE admins SET "2fa" = CAST(:settings AS jsonb) WHERE login = :login');
    $stmt->execute([':settings' => json_encode($settings, JSON_THROW_ON_ERROR), ':login' => $login]);
}

function create_user_key(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';
    do {
        $key = '';
        for ($i = 0; $i < 32; $i++) $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE "key" = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
    } while ($stmt->fetchColumn() !== false);
    return $key;
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
        if (!csrf_valid()) { http_response_code(403); exit('Invalid request'); }

        switch ($action) {
            case 'logout':
                if (auth_user() === null) { http_response_code(403); exit('Not authenticated'); }
                auth_logout(); redirect('/admin');

            case 'authorization':
                $login = trim((string)($_POST['login'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                if ($login === '' || $password === '') redirect('/admin?error=1');
                $admin = admin_by_login($login);
                if (!$admin || !password_verify($password, (string)$admin['password'])) redirect('/admin?error=1');
                if (!empty($admin['2fa']['enabled'])) { auth_pending_login($login); redirect('/admin?step=2fa'); }
                auth_login($login); redirect('/admin/dashboard/');

            case 'verify-2fa':
                $login = pending_auth_user();
                if ($login === null) redirect('/admin?error=expired');
                $admin = admin_by_login($login);
                if (!$admin || empty($admin['2fa']['enabled']) || empty($admin['2fa']['secret']) || !totp_verify((string)$admin['2fa']['secret'], (string)($_POST['code'] ?? ''))) redirect('/admin?step=2fa&error=1');
                auth_login($login); redirect('/admin/dashboard/');

            case 'setup-2fa':
                $login = require_admin(); $admin = admin_by_login($login);
                if (!$admin) redirect('/admin');
                if (!empty($admin['2fa']['enabled'])) redirect('/admin/dashboard/security');
                if (empty($admin['2fa']['secret'])) save_2fa($admin['login'], ['enabled' => 0, 'secret' => totp_generate_secret()]);
                redirect('/admin/dashboard/security');

            case 'enable-2fa':
                require_admin(); $login = auth_user(); $admin = $login ? admin_by_login($login) : null;
                $code = (string)($_POST['code'] ?? '');
                if (!$admin || empty($admin['2fa']['secret']) || !totp_verify((string)$admin['2fa']['secret'], $code)) redirect('/admin/dashboard/security?error=code');
                save_2fa($admin['login'], ['enabled' => 1, 'secret' => $admin['2fa']['secret']]); redirect('/admin/dashboard/security?enabled=1');

            case 'disable-2fa':
                require_admin(); $login = auth_user(); $admin = $login ? admin_by_login($login) : null;
                $code = (string)($_POST['code'] ?? '');
                if (!$admin || empty($admin['2fa']['enabled']) || empty($admin['2fa']['secret']) || !totp_verify((string)$admin['2fa']['secret'], $code)) redirect('/admin/dashboard/security?error=code');
                save_2fa($admin['login'], ['enabled' => 0, 'secret' => null]); redirect('/admin/dashboard/security?disabled=1');

            case 'create-user':
                require_admin();
                $pdo = db();
                $expiresRaw = trim((string)($_POST['expires_at'] ?? ''));
                $expires = null;
                if ($expiresRaw !== '') {
                    $timestamp = strtotime(str_replace('T', ' ', $expiresRaw));
                    if ($timestamp === false) redirect('/admin/dashboard/users?error=date');
                    $expires = date('Y-m-d H:i:sP', $timestamp);
                }
                $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int(0, 65535), random_int(0, 65535), random_int(0, 65535), random_int(16384, 20479), random_int(32768, 49151), random_int(0, 65535), random_int(0, 65535), random_int(0, 65535));
                $pdo->beginTransaction();
                try {
                    $key = create_user_key($pdo);
                    $stmt = $pdo->prepare('INSERT INTO users (uuid, "key", expires_at) VALUES (CAST(:uuid AS uuid), :key, :expires)');
                    $stmt->execute([':uuid' => $uuid, ':key' => $key, ':expires' => $expires]);
                    $pdo->commit();
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
                redirect('/admin/dashboard/users?created=1');

            case 'delete-user':
                require_admin();
                $stmt = db()->prepare('DELETE FROM users WHERE uuid = CAST(:uuid AS uuid)');
                $stmt->execute([':uuid' => (string)($_POST['uuid'] ?? '')]);
                redirect('/admin/dashboard/users?deleted=1');

            case 'save-news':
                require_admin();
                $id = (int)($_POST['id'] ?? 0); $version = trim((string)($_POST['version'] ?? '')); $hidden = isset($_POST['hidden']);
                $formToken = trim((string)($_POST['form_token'] ?? ''));
                if ($formToken !== '') {
                    $pdo = db(); $stmt = $pdo->prepare("SELECT 1 FROM submitted_forms WHERE token = :token LIMIT 1");
                    $stmt->execute([':token' => $formToken]);
                    if ($stmt->fetchColumn() !== false) redirect($id > 0 ? '/admin/dashboard/news?edit=' . $id . '&saved=1' : '/admin/dashboard/news?saved=1');
                    $pdo->prepare('INSERT INTO submitted_forms (token) VALUES (:token) ON CONFLICT (token) DO NOTHING')->execute([':token' => $formToken]);
                }
                $blocks = []; $titles = $_POST['block_title'] ?? []; $descriptions = $_POST['block_description'] ?? [];
                $tooltips = $_POST['block_tooltip'] ?? [];
                if (is_array($titles)) foreach ($titles as $index => $title) {
                    $title = trim((string)$title); $description = trim((string)($descriptions[$index] ?? '')); $tooltip = trim((string)($tooltips[$index] ?? ''));
                    if ($title === '' && $description === '') continue;
                    $blocks[] = ['title' => $title, 'description' => $description, 'tooltip' => $tooltip];
                }
                $images = [];
                if ($id > 0) {
                    $stmt = db()->prepare('SELECT data FROM news WHERE id = :id'); $stmt->execute([':id' => $id]); $existing = $stmt->fetchColumn();
                    if ($existing === false) redirect('/admin/dashboard/news?error=notfound');
                    $existingData = news_data((string)$existing); $remove = array_map('intval', (array)($_POST['remove_image'] ?? []));
                    foreach ($existingData['images'] as $index => $image) if (!in_array($index, $remove, true)) $images[] = $image;
                }
                $urls = preg_split('/\r\n|\r|\n/', (string)($_POST['image_urls'] ?? '')) ?: [];
                foreach ($urls as $url) { $url = trim($url); if ($url !== '' && content_valid_image($url)) $images[] = $url; }
                foreach (content_uploaded_images('images', 3, 1024 * 1024) as $image) $images[] = $image;
                $images = array_slice(array_values(array_unique($images)), 0, 12);
                if ($version === '' && !$blocks && !$images) redirect('/admin/dashboard/news?error=empty');
                $json = json_encode(['blocks' => $blocks, 'images' => $images], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if ($id > 0) {
                    $stmt = db()->prepare('UPDATE news SET version = :version, data = CAST(:data AS jsonb), hidden = CAST(:hidden AS boolean), updated_at = NOW() WHERE id = :id');
                    $stmt->execute([':version' => $version, ':data' => $json, ':hidden' => $hidden ? 1 : 0, ':id' => $id]);
                } else {
                    $stmt = db()->prepare('INSERT INTO news (version, data, hidden) VALUES (:version, CAST(:data AS jsonb), CAST(:hidden AS boolean)) RETURNING id');
                    $stmt->execute([':version' => $version, ':data' => $json, ':hidden' => $hidden ? 1 : 0]); $id = (int)$stmt->fetchColumn();
                }
                redirect('/admin/dashboard/news?edit=' . $id . '&saved=1');

            case 'delete-news': require_admin(); db()->prepare('DELETE FROM news WHERE id = :id')->execute([':id' => (int)($_POST['id'] ?? 0)]); redirect('/admin/dashboard/news?deleted=1');
            case 'toggle-news': require_admin(); db()->prepare('UPDATE news SET hidden = NOT hidden, updated_at = NOW() WHERE id = :id')->execute([':id' => (int)($_POST['id'] ?? 0)]); redirect('/admin/dashboard/news?toggled=1');

            case 'save-good':
                require_admin();
                $id = (int)($_POST['id'] ?? 0); $name = trim((string)($_POST['name'] ?? '')); $description = trim((string)($_POST['description'] ?? '')); $status = (string)($_POST['status'] ?? 'undetected');
                $formToken = trim((string)($_POST['form_token'] ?? ''));
                if ($formToken !== '') {
                    $pdo = db(); $stmt = $pdo->prepare('SELECT 1 FROM submitted_forms WHERE token = :token LIMIT 1'); $stmt->execute([':token' => $formToken]);
                    if ($stmt->fetchColumn() !== false) redirect($id > 0 ? '/admin/dashboard/goods?edit=' . $id . '&saved=1' : '/admin/dashboard/goods?saved=1');
                    $pdo->prepare('INSERT INTO submitted_forms (token) VALUES (:token) ON CONFLICT (token) DO NOTHING')->execute([':token' => $formToken]);
                }
                if (!isset(goods_statuses()[$status])) $status = 'undetected';
                $hidden = isset($_POST['hidden']); $priceRaw = str_replace(',', '.', trim((string)($_POST['price'] ?? '0'))); $price = is_numeric($priceRaw) ? max(0.0, (float)$priceRaw) : 0.0;
                $image = '';
                if ($id > 0) { $stmt = db()->prepare('SELECT image FROM goods WHERE id = :id'); $stmt->execute([':id' => $id]); $existing = $stmt->fetchColumn(); if ($existing === false) redirect('/admin/dashboard/goods?error=notfound'); $image = (string)$existing; }
                if (isset($_POST['remove_image'])) $image = '';
                $imageInput = trim((string)($_POST['image'] ?? '')); if ($imageInput !== '' && content_valid_image($imageInput)) $image = $imageInput;
                $uploaded = content_image_from_upload($_FILES['image_file'] ?? []); if ($uploaded !== null) $image = $uploaded;
                if ($name === '') redirect('/admin/dashboard/goods?error=name');
                if ($id > 0) {
                    $stmt = db()->prepare('UPDATE goods SET status = :status, image = :image, name = :name, description = :description, price = :price, hidden = CAST(:hidden AS boolean), updated_at = NOW() WHERE id = :id');
                    $stmt->execute([':status' => $status, ':image' => $image, ':name' => $name, ':description' => $description, ':price' => $price, ':hidden' => $hidden ? 1 : 0, ':id' => $id]);
                } else {
                    $stmt = db()->prepare('INSERT INTO goods (status, image, name, description, price, hidden) VALUES (:status, :image, :name, :description, :price, CAST(:hidden AS boolean)) RETURNING id');
                    $stmt->execute([':status' => $status, ':image' => $image, ':name' => $name, ':description' => $description, ':price' => $price, ':hidden' => $hidden ? 1 : 0]); $id = (int)$stmt->fetchColumn();
                }
                redirect('/admin/dashboard/goods?edit=' . $id . '&saved=1');

            case 'delete-good':
                require_admin(); db()->prepare('DELETE FROM goods WHERE id = :id')->execute([':id' => (int)($_POST['id'] ?? 0)]); redirect('/admin/dashboard/goods?deleted=1');
            case 'toggle-good': require_admin(); db()->prepare('UPDATE goods SET hidden = NOT hidden, updated_at = NOW() WHERE id = :id')->execute([':id' => (int)($_POST['id'] ?? 0)]); redirect('/admin/dashboard/goods?toggled=1');
            case 'set-good-status':
                require_admin(); $id = (int)($_POST['id'] ?? 0); $status = (string)($_POST['status'] ?? '');
                if (!isset(goods_statuses()[$status])) redirect('/admin/dashboard/goods?error=status');
                db()->prepare('UPDATE goods SET status = :status, updated_at = NOW() WHERE id = :id')->execute([':status' => $status, ':id' => $id]); redirect('/admin/dashboard/goods?toggled=1');

            default: http_response_code(404); exit('Not found');
        }
    } catch (Throwable $e) { error_log('XWORS admin API error: ' . $e->getMessage()); http_response_code(503); exit('Database unavailable'); }
}
