<?php
declare(strict_types=1);

function news_data(?string $json): array
{
    $decoded = json_decode((string)$json, true);
    $blocks = []; $images = [];
    if (is_array($decoded)) {
        foreach ((array)($decoded['blocks'] ?? []) as $block) {
            if (!is_array($block)) continue;
            $title = trim((string)($block['title'] ?? '')); $description = trim((string)($block['description'] ?? '')); $tooltip = trim((string)($block['tooltip'] ?? ''));
            if ($title === '' && $description === '') continue;
            $blocks[] = ['title' => $title, 'description' => $description, 'tooltip' => $tooltip];
        }
        foreach ((array)($decoded['images'] ?? []) as $image) if (is_string($image) && trim($image) !== '') $images[] = trim($image);
    }
    return ['blocks' => $blocks, 'images' => $images];
}

function news_first_title(array $data): string
{
    foreach ($data['blocks'] ?? [] as $block) if (is_array($block) && trim((string)($block['title'] ?? '')) !== '') return trim((string)$block['title']);
    return '';
}

function goods_statuses(): array
{
    return ['undetected' => ['label' => 'Undetected', 'color' => 'lime'], 'detected' => ['label' => 'Detected', 'color' => 'red'], 'updating' => ['label' => 'Updating', 'color' => 'orange']];
}
function goods_status_meta(?string $status): array { $all = goods_statuses(); return $all[(string)$status] ?? $all['undetected']; }
function goods_next_status(?string $status): string { $keys = array_keys(goods_statuses()); $index = array_search((string)$status, $keys, true); return $keys[$index === false ? 0 : ($index + 1) % count($keys)]; }
function content_price(float|int|string $value): string { $number = (float)$value; if ($number <= 0) return 'Цена по запросу'; $decimals = fmod($number, 1.0) === 0.0 ? 0 : 2; return number_format($number, $decimals, ',', ' ') . ' ₽'; }
function content_valid_image(string $value): bool { $value = trim($value); if ($value === '') return false; if (str_starts_with($value, 'data:image/')) return (bool)preg_match('#^data:image/(png|jpe?g|gif|webp|avif);base64,#i', $value); if (str_starts_with($value, '/')) return true; $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME)); return in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL) !== false; }
function content_image_from_upload(array $file, int $maxBytes = 2 * 1024 * 1024): ?string { if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null; if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) return null; if ((int)($file['size'] ?? 0) > $maxBytes) return null; $info = @getimagesize((string)$file['tmp_name']); if (!$info || empty($info['mime'])) return null; $allowed = ['image/jpeg'=>'image/jpeg','image/png'=>'image/png','image/gif'=>'image/gif','image/webp'=>'image/webp','image/avif'=>'image/avif']; $mime = strtolower((string)$info['mime']); if (!isset($allowed[$mime])) return null; $bytes = file_get_contents((string)$file['tmp_name']); return $bytes === false ? null : 'data:' . $allowed[$mime] . ';base64,' . base64_encode($bytes); }
function content_uploaded_images(string $field, int $max = 3, int $maxBytes = 1024 * 1024): array { $files = $_FILES[$field] ?? null; if (!is_array($files) || !is_array($files['name'] ?? null)) return []; $result = []; foreach ($files['name'] as $index => $name) { if (count($result) >= $max) break; $data = content_image_from_upload(['name'=>$name,'tmp_name'=>$files['tmp_name'][$index]??'','error'=>$files['error'][$index]??UPLOAD_ERR_NO_FILE,'size'=>$files['size'][$index]??0], $maxBytes); if ($data !== null) $result[] = $data; } return $result; }
