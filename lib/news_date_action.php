<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/content.php';

function handle_save_news_with_date(): never
{
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Method not allowed'); }
    if (!csrf_valid()) { http_response_code(403); exit('Invalid request'); }
    require_admin();

    $pdo = db(); $id = max(0, (int)($_POST['id'] ?? 0)); $version = trim((string)($_POST['version'] ?? '')); $hidden = isset($_POST['hidden']);
    $dateInput = trim((string)($_POST['create_at'] ?? '')); $dateTimestamp = $dateInput !== '' ? strtotime($dateInput) : false;
    if ($dateTimestamp === false) redirect('/admin/dashboard/news?error=date');
    $createAt = date('Y-m-d H:i:sP', $dateTimestamp);
    $blocks = []; $titles = $_POST['block_title'] ?? []; $descriptions = $_POST['block_description'] ?? []; $tooltips = $_POST['block_tooltip'] ?? [];
    if (is_array($titles)) foreach ($titles as $index => $title) {
        $title = trim((string)$title); $description = trim((string)($descriptions[$index] ?? '')); $tooltip = trim((string)($tooltips[$index] ?? ''));
        if ($title === '' && $description === '') continue;
        $blocks[] = ['title' => $title, 'description' => $description, 'tooltip' => $tooltip];
    }
    $images = [];
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT data FROM news WHERE id = :id'); $stmt->execute([':id' => $id]); $existing = $stmt->fetchColumn();
        if ($existing === false) redirect('/admin/dashboard/news?error=notfound');
        $oldData = news_data((string)$existing); $remove = array_map('intval', (array)($_POST['remove_image'] ?? []));
        foreach ($oldData['images'] as $index => $image) if (!in_array($index, $remove, true)) $images[] = $image;
    }
    $urls = preg_split('/\r\n|\r|\n/', (string)($_POST['image_urls'] ?? '')) ?: [];
    foreach ($urls as $url) { $url = trim($url); if ($url !== '' && content_valid_image($url)) $images[] = $url; }
    foreach (content_uploaded_images('images', 3, 1024 * 1024) as $image) $images[] = $image;
    $images = array_slice(array_values(array_unique($images)), 0, 12);
    if ($version === '' && !$blocks && !$images) redirect('/admin/dashboard/news?error=empty');
    $json = json_encode(['blocks' => $blocks, 'images' => $images], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE news SET version = :version, data = CAST(:data AS jsonb), hidden = CAST(:hidden AS boolean), create_at = CAST(:create_at AS timestamptz), updated_at = NOW() WHERE id = :id');
        $stmt->execute([':version' => $version, ':data' => $json, ':hidden' => $hidden ? 1 : 0, ':create_at' => $createAt, ':id' => $id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO news (version, data, hidden, create_at) VALUES (:version, CAST(:data AS jsonb), CAST(:hidden AS boolean), CAST(:create_at AS timestamptz)) RETURNING id');
        $stmt->execute([':version' => $version, ':data' => $json, ':hidden' => $hidden ? 1 : 0, ':create_at' => $createAt]); $id = (int)$stmt->fetchColumn();
    }
    redirect('/admin/dashboard/news?edit=' . $id . '&saved=1');
}
