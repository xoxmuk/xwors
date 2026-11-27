<?php
declare(strict_types=1);

function news_data(?string $json): array
{
    $decoded = json_decode((string)$json, true);
    $blocks = []; $images = [];
    if (is_array($decoded)) {
        foreach ((array)($decoded['blocks'] ?? []) as $block) {
            if (!is_array($block)) continue;
            $title = trim((string)($block['title'] ?? '')); $description = trim((string)($block['description'] ?? ''));
            if ($title === '' && $description === '') continue;
            $blocks[] = ['title' => $title, 'description' => $description];
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
const CONTENT_IMAGE_FOLDERS = ['news', 'goods'];
const CONTENT_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif'];
const CONTENT_LOCAL_IMAGE = '#^/assets/(news|goods)/[a-f0-9]{64}\.(jpg|png|gif|webp|avif)$#';

function content_assets_dir(string $folder): string { return dirname(__DIR__) . '/assets/' . $folder; }

function content_local_image(string $value): bool { return (bool)preg_match(CONTENT_LOCAL_IMAGE, $value); }

function content_valid_image(string $value): bool
{
    $value = trim($value);
    if ($value === '') return false;
    if (str_starts_with($value, 'data:image/')) return (bool)preg_match('#^data:image/(png|jpe?g|gif|webp|avif);base64,#i', $value);
    if (str_starts_with($value, '/')) return content_local_image($value) && is_file(dirname(__DIR__) . $value);
    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL) !== false;
}

/**
 * Validates an uploaded image, hashes its content (SHA-256) and stores it as assets/<folder>/<hash>.<ext>.
 * Returns ['path' => '/assets/<folder>/<hash>.<ext>'] or ['error' => '<message>'].
 */
function content_store_upload(array $file, string $folder, int $maxBytes): array
{
    if (!in_array($folder, CONTENT_IMAGE_FOLDERS, true)) return ['error' => 'Неизвестная папка для загрузки.'];
    $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) return ['error' => 'Файл слишком большой.'];
    if ($code !== UPLOAD_ERR_OK) return ['error' => 'Не удалось получить файл.'];
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) return ['error' => 'Не удалось получить файл.'];
    if ((int)($file['size'] ?? 0) > $maxBytes) return ['error' => 'Файл больше ' . round($maxBytes / 1048576, 1) . ' МБ.'];
    $info = @getimagesize($tmp);
    $mime = strtolower((string)($info['mime'] ?? ''));
    if (!isset(CONTENT_IMAGE_TYPES[$mime])) return ['error' => 'Допустимы PNG, JPG, WEBP, GIF и AVIF.'];
    $hash = hash_file('sha256', $tmp);
    if ($hash === false) return ['error' => 'Не удалось обработать файл.'];
    $dir = content_assets_dir($folder);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return ['error' => 'Папка assets/' . $folder . ' недоступна для записи.'];
    $name = $hash . '.' . CONTENT_IMAGE_TYPES[$mime];
    $target = $dir . '/' . $name;
    if (!is_file($target) && !@move_uploaded_file($tmp, $target)) return ['error' => 'Не удалось сохранить файл на сервере.'];
    @chmod($target, 0644);
    return ['path' => '/assets/' . $folder . '/' . $name];
}

/** Deletes local image files that are no longer referenced by any news or good. */
function content_cleanup_images(PDO $pdo, array $paths): void
{
    foreach (array_unique($paths) as $path) {
        if (!is_string($path) || !content_local_image($path)) continue;
        $stmt = $pdo->prepare('SELECT 1 FROM goods WHERE image = :p UNION ALL SELECT 1 FROM news WHERE data::text LIKE :like LIMIT 1');
        $stmt->execute([':p' => $path, ':like' => '%' . $path . '%']);
        if ($stmt->fetchColumn() === false) @unlink(dirname(__DIR__) . $path);
    }
}

function content_random_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

const CONTENT_HTML_TAGS = [
    'a' => ['href', 'title'], 'abbr' => ['title'], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'del' => [], 'mark' => [],
    'small' => [], 'sub' => [], 'sup' => [], 'code' => [], 'pre' => [], 'kbd' => [], 'br' => [], 'hr' => [], 'p' => [], 'div' => [], 'span' => [],
    'blockquote' => [], 'ul' => [], 'ol' => [], 'li' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
    'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
    'img' => ['src', 'alt', 'width', 'height', 'title'],
];
const CONTENT_HTML_DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'svg', 'math', 'video', 'audio', 'base', 'noscript', 'template'];

function content_safe_url(string $value, bool $image): bool
{
    $value = preg_replace('/[\x00-\x20\x7f]+/', '', $value) ?? '';
    if ($value === '') return false;
    if ($value[0] === '#') return !$image;
    if ($value[0] === '/') return !str_starts_with($value, '//');
    if (preg_match('#^https?://#i', $value)) return filter_var($value, FILTER_VALIDATE_URL) !== false;
    return !$image && (bool)preg_match('#^mailto:[^\s]+$#i', $value);
}

function content_clean_node(DOMNode $node): void
{
    for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
        $child = $node->childNodes->item($i);
        if ($child instanceof DOMElement) {
            $tag = strtolower($child->tagName);
            if (!isset(CONTENT_HTML_TAGS[$tag])) {
                if (in_array($tag, CONTENT_HTML_DROP, true)) { $node->removeChild($child); continue; }
                content_clean_node($child);
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                if ($name === 'data-tooltip' || $name === 'class') continue; // handled below
                if (!in_array($name, CONTENT_HTML_TAGS[$tag], true)) { $child->removeAttribute($attr->name); continue; }
                if (($name === 'href' || $name === 'src') && !content_safe_url($attr->value, $name === 'src')) $child->removeAttribute($attr->name);
            }
            // Tooltip: data-tooltip="…" (class="tooltip" is added automatically); no other classes are allowed
            $tip = trim($child->getAttribute('data-tooltip'));
            if (preg_match('/^.{0,300}/su', $tip, $m)) $tip = $m[0];
            $child->removeAttribute('class');
            $child->removeAttribute('data-tooltip');
            if ($tip !== '' && !in_array($tag, ['img', 'br', 'hr'], true)) {
                $child->setAttribute('class', 'tooltip');
                $child->setAttribute('data-tooltip', $tip);
                $child->setAttribute('tabindex', '0');
            }
            if ($tag === 'a') { $child->setAttribute('rel', 'noopener noreferrer nofollow'); $child->setAttribute('target', '_blank'); }
            if ($tag === 'img') $child->setAttribute('loading', 'lazy');
            content_clean_node($child);
        } elseif (!($child instanceof DOMText)) {
            $node->removeChild($child);
        }
    }
}

/** Renders news text: plain text keeps line breaks, text with tags is sanitized against a whitelist of safe HTML. */
function content_render_html(string $text): string
{
    $text = trim($text);
    if ($text === '') return '';
    if (!str_contains($text, '<') || !class_exists('DOMDocument')) return nl2br(e($text));
    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $text . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $doc->getElementsByTagName('div')->item(0);
    if (!$root) return nl2br(e($text));
    content_clean_node($root);
    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    return $out;
}
