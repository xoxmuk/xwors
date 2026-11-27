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
function goods_buy_types(): array
{
    return [
        'none' => 'Не активна',
        'telegram' => 'Telegram',
        'link' => 'Внешняя ссылка',
        'page' => 'Страница сайта',
    ];
}
/** Decodes goods.purchase (JSONB {"type","url"}) into a safe ['type','url'] pair. */
function goods_purchase(?string $json): array
{
    $d = json_decode((string)$json, true);
    $type = is_array($d) ? (string)($d['type'] ?? 'none') : 'none';
    $url = is_array($d) ? trim((string)($d['url'] ?? '')) : '';
    if (!isset(goods_buy_types()[$type]) || ($type !== 'none' && $url === '')) return ['type' => 'none', 'url' => ''];
    return ['type' => $type, 'url' => $url];
}
function goods_buy_pages(): array { return ['/' => 'Главная', '/news' => 'Новости']; }

/** Validates the «Купить» target. Returns ['type' => ..., 'url' => ...] or ['error' => message]. */
function goods_buy_resolve(string $type, string $value, string $page): array
{
    $value = trim($value);
    switch ($type) {
        case 'none': return ['type' => 'none', 'url' => ''];
        case 'telegram':
            if ($value === '') return ['error' => 'Укажите Telegram: @username или ссылку t.me/…'];
            if (preg_match('#^(?:https?://)?(?:t(?:elegram)?\.me/)?@?([A-Za-z][A-Za-z0-9_]{4,31})/?$#i', $value, $m)) return ['type' => 'telegram', 'url' => 'https://t.me/' . $m[1]];
            if (preg_match('#^https?://t\.me/\+[A-Za-z0-9_-]+$#i', $value) || preg_match('#^https?://t\.me/joinchat/[A-Za-z0-9_-]+$#i', $value)) return ['type' => 'telegram', 'url' => $value];
            return ['error' => 'Некорректный Telegram: нужен @username (5–32 символа: латиница, цифры, _) или ссылка t.me/…'];
        case 'link':
            if ($value === '') return ['error' => 'Укажите ссылку для кнопки «Купить».'];
            if (!preg_match('#^https?://#i', $value)) $value = 'https://' . $value;
            if (filter_var($value, FILTER_VALIDATE_URL) === false || strlen($value) > 500) return ['error' => 'Некорректная ссылка. Пример: https://example.com/buy'];
            return ['type' => 'link', 'url' => $value];
        case 'page':
            if (!isset(goods_buy_pages()[$page])) return ['error' => 'Выберите страницу сайта из списка.'];
            return ['type' => 'page', 'url' => $page];
    }
    return ['error' => 'Неизвестный способ покупки.'];
}

function content_price(float|int|string $value): string { $number = (float)$value; if ($number <= 0) return 'Цена по запросу'; $decimals = fmod($number, 1.0) === 0.0 ? 0 : 2; return number_format($number, $decimals, ',', ' ') . ' ₽'; }
const CONTENT_IMAGE_FOLDERS = ['news', 'goods'];
const CONTENT_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif'];
const CONTENT_LOCAL_IMAGE = '#^/assets/(news|goods)/[a-f0-9]{64}\.(jpg|png|gif|webp|avif)$#';

function content_assets_dir(string $folder): string { return dirname(__DIR__) . '/public/assets/' . $folder; }

function content_local_image(string $value): bool { return (bool)preg_match(CONTENT_LOCAL_IMAGE, $value); }

function content_valid_image(string $value): bool
{
    $value = trim($value);
    if ($value === '') return false;
    if (str_starts_with($value, 'data:image/')) return strlen($value) <= 2 * 1024 * 1024 && (bool)preg_match('#^data:image/(png|jpe?g|gif|webp|avif);base64,[A-Za-z0-9+/]+=*$#i', $value);
    if (str_starts_with($value, '/')) {
        $defaultIcon = in_array($value, ['/assets/goods/default_64.png', '/assets/goods/default_1254.png'], true);
        return ($defaultIcon || content_local_image($value)) && is_file(dirname(__DIR__) . '/public' . $value);
    }
    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    return $scheme === 'https' && filter_var($value, FILTER_VALIDATE_URL) !== false;
}

const CONTENT_MAX_PIXELS = 8000;

function content_ini_bytes(string $name): int
{
    $value = trim((string)ini_get($name));
    if ($value === '') return 0;
    $number = (float)$value;
    return (int)match (strtolower(substr($value, -1))) { 'g' => $number * 1073741824, 'm' => $number * 1048576, 'k' => $number * 1024, default => $number };
}

function content_format_mb(int $bytes): string { return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',') . ' МБ'; }

/** True when the app cannot write to its own folder (Vercel and other read-only hosts). */
function content_storage_readonly(string $dir): bool
{
    if (env_get('VERCEL') !== null || env_get('AWS_LAMBDA_FUNCTION_NAME') !== null) return true;
    return is_dir($dir) ? !is_writable($dir) : !is_writable(dirname($dir));
}

/**
 * Validates an uploaded image, hashes its content (SHA-256) and stores it as public/assets/<folder>/<hash>.<ext>.
 * Returns ['path' => '/assets/<folder>/<hash>.<ext>'] or ['error' => '<message>', 'code' => '<code>'].
 */
function content_store_upload(array $file, string $folder, int $maxBytes): array
{
    $fail = static fn(string $code, string $message): array => ['error' => $message, 'code' => $code];
    if (!in_array($folder, CONTENT_IMAGE_FOLDERS, true)) return $fail('folder', 'Неизвестная папка для загрузки.');

    $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    switch ($code) {
        case UPLOAD_ERR_OK: break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return $fail('server_limit', 'Файл больше лимита сервера (' . (ini_get('upload_max_filesize') ?: '—') . ').');
        case UPLOAD_ERR_PARTIAL: return $fail('partial', 'Файл загружен не полностью. Проверьте соединение и повторите попытку.');
        case UPLOAD_ERR_NO_FILE: return $fail('no_file', 'Файл не выбран или не дошёл до сервера.');
        case UPLOAD_ERR_NO_TMP_DIR: return $fail('no_tmp', 'На сервере нет временной папки для загрузок. Обратитесь к администратору хостинга.');
        case UPLOAD_ERR_CANT_WRITE: return $fail('tmp_write', 'Сервер не смог записать файл на диск. Возможно, закончилось место.');
        case UPLOAD_ERR_EXTENSION: return $fail('php_ext', 'Загрузка остановлена расширением PHP на сервере.');
        default: return $fail('unknown', 'Не удалось получить файл (код ' . $code . ').');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return $fail('not_uploaded', 'Не удалось получить файл.');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) return $fail('empty', 'Файл пустой (0 байт).');
    if ($size > $maxBytes) return $fail('too_big', 'Файл весит ' . content_format_mb($size) . ', допустимо не больше ' . content_format_mb($maxBytes) . '.');

    $info = @getimagesize($tmp);
    if ($info === false) return $fail('not_image', 'Файл повреждён или не является изображением.');
    $mime = strtolower((string)($info['mime'] ?? ''));
    if (!isset(CONTENT_IMAGE_TYPES[$mime])) return $fail('bad_type', 'Формат «' . ($mime !== '' ? $mime : 'неизвестный') . '» не поддерживается. Допустимы PNG, JPG, WEBP, GIF и AVIF.');
    if ((int)$info[0] < 1 || (int)$info[1] < 1) return $fail('bad_size', 'Не удалось определить размер изображения.');
    if ((int)$info[0] > CONTENT_MAX_PIXELS || (int)$info[1] > CONTENT_MAX_PIXELS) return $fail('too_large_px', 'Разрешение ' . (int)$info[0] . '×' . (int)$info[1] . ' слишком большое, максимум ' . CONTENT_MAX_PIXELS . '×' . CONTENT_MAX_PIXELS . '.');

    $hash = hash_file('sha256', $tmp);
    if ($hash === false) return $fail('hash', 'Не удалось обработать файл.');

    // На Vercel используем постоянное внешнее хранилище Vercel Blob.
    // Поддерживаем пользовательский префикс переменных из Vercel Advanced Options.
    $blobToken = trim((string)(env_get('PUBLIC_BLOB_READ_WRITE_TOKEN') ?: env_get('BLOB_READ_WRITE_TOKEN')));
    $blobStoreId = trim((string)(env_get('PUBLIC_BLOB_STORE_ID') ?: env_get('BLOB_STORE_ID')));
    if ($blobToken !== '') {
        if (!function_exists('curl_init')) return $fail('blob_curl', 'Для загрузки в Vercel Blob на сервере требуется PHP cURL.');

        // Read/Write token обычно уже содержит Store ID. Явная переменная
        // PUBLIC_BLOB_STORE_ID имеет приоритет и поддерживает формат store_<id>.
        if ($blobStoreId === '') {
            $tokenParts = explode('_', $blobToken);
            $blobStoreId = (string)($tokenParts[3] ?? '');
        }
        if (str_starts_with($blobStoreId, 'store_')) $blobStoreId = substr($blobStoreId, 6);
        if ($blobStoreId === '') return $fail('blob_store_id', 'Не задан PUBLIC_BLOB_STORE_ID и не удалось определить ID из Read/Write Token.');

        $name = $hash . '.' . CONTENT_IMAGE_TYPES[$mime];
        $pathname = 'xwors/' . $folder . '/' . $name;
        // Совместимый с актуальным @vercel/blob API v13 server-side PUT.
        $url = 'https://vercel.com/api/blob/?pathname=' . rawurlencode($pathname);
        $payload = file_get_contents($tmp);
        if ($payload === false) return $fail('read', 'Не удалось прочитать загружаемый файл.');

        $requestId = $blobStoreId . ':' . (string)time() . ':' . bin2hex(random_bytes(6));
        $blob = curl_init($url);
        curl_setopt_array($blob, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $blobToken,
                'x-vercel-blob-store-id: ' . $blobStoreId,
                'x-api-version: 13',
                'x-api-blob-request-id: ' . $requestId,
                'x-api-blob-request-attempt: 0',
                'x-content-length: ' . strlen($payload),
                'x-vercel-blob-access: public',
                'x-content-type: ' . $mime,
                'x-cache-control-max-age: 31536000',
                'x-allow-overwrite: 1',
                'Content-Length: ' . strlen($payload),
            ],
        ]);
        $response = curl_exec($blob);
        $httpCode = (int)curl_getinfo($blob, CURLINFO_HTTP_CODE);
        $curlError = curl_error($blob);
        curl_close($blob);
        $blobData = is_string($response) ? json_decode($response, true) : null;
        if ($httpCode < 200 || $httpCode >= 300 || !is_array($blobData) || empty($blobData['url'])) {
            $apiMessage = is_array($blobData) ? (string)($blobData['error']['message'] ?? $blobData['message'] ?? '') : '';
            error_log('Vercel Blob upload failed: HTTP ' . $httpCode . ' ' . $curlError . ' ' . (is_string($response) ? substr($response, 0, 1000) : ''));
            $details = $apiMessage !== '' ? ' Ответ Vercel: ' . $apiMessage : ($curlError !== '' ? ' cURL: ' . $curlError : ' HTTP ' . $httpCode . '.');
            return $fail('blob_upload', 'Не удалось сохранить изображение в Vercel Blob.' . $details);
        }
        return ['path' => (string)$blobData['url']];
    }

    $dir = content_assets_dir($folder);
    if (content_storage_readonly($dir)) return $fail('readonly', 'Сервер работает в режиме «только чтение». Для Vercel добавьте PUBLIC_BLOB_READ_WRITE_TOKEN (Vercel Blob) или укажите URL изображения.');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return $fail('mkdir', 'Не удалось создать папку public/assets/' . $folder . '. Проверьте права на запись.');
    if (!is_writable($dir)) return $fail('not_writable', 'Нет прав на запись в папку public/assets/' . $folder . '.');

    $name = $hash . '.' . CONTENT_IMAGE_TYPES[$mime];
    $target = $dir . '/' . $name;
    if (!is_file($target)) {
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $size + 1048576) return $fail('disk_full', 'На сервере закончилось свободное место.');
        if (!@move_uploaded_file($tmp, $target)) return $fail('move', 'Не удалось сохранить файл на сервере. Проверьте права на запись в public/assets/' . $folder . '.');
    }
    if (!is_file($target) || (int)@filesize($target) <= 0) return $fail('verify', 'Файл сохранён с ошибкой. Повторите загрузку.');
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
    if (preg_match('#^https?://#i', $value)) {
        if ($image && !str_starts_with(strtolower($value), 'https://')) return false;
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
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
                if (in_array($name, ['tooltip-text', 'tooltip-position', 'class'], true)) continue; // handled below
                if ($tag === 'div' && $name === 'devider-text') continue;
                if (!in_array($name, CONTENT_HTML_TAGS[$tag], true)) { $child->removeAttribute($attr->name); continue; }
                if (($name === 'href' || $name === 'src') && !content_safe_url($attr->value, $name === 'src')) $child->removeAttribute($attr->name);
            }
            // Tooltip: tooltip-text="…" (class="tooltip" is added automatically); no other classes are allowed
            $tip = trim($child->getAttribute('tooltip-text'));
            $tipPosition = trim($child->getAttribute('tooltip-position'));
            $validTipPositions = ['left-up', 'up', 'right-up', 'left', 'right', 'left-bottom', 'bottom', 'right-bottom'];
            if (!in_array($tipPosition, $validTipPositions, true)) $tipPosition = 'up';
            if (preg_match('/^.{0,300}/su', $tip, $m)) $tip = $m[0];
            $requestedClasses = preg_split('/\\s+/', trim($child->getAttribute('class'))) ?: [];
            $safeDivClass = '';
            $deviderText = '';
            if ($tag === 'div') {
                // Keep only the supported layout classes. A divider may be aligned with
                // "devider left" or "devider right"; arbitrary classes are discarded.
                $allowedDivClasses = ['center', 'left', 'right', 'devider'];
                $safeClasses = array_values(array_intersect($requestedClasses, $allowedDivClasses));
                if (in_array('devider', $safeClasses, true)) {
                    $alignment = in_array('left', $safeClasses, true) ? 'left'
                        : (in_array('right', $safeClasses, true) ? 'right' : '');
                    $safeDivClass = 'devider' . ($alignment !== '' ? ' ' . $alignment : '');
                    $deviderText = mb_substr(trim($child->getAttribute('devider-text')), 0, 160);
                } elseif (count($safeClasses) === 1) {
                    $safeDivClass = $safeClasses[0];
                }
            }
            $child->removeAttribute('class');
            $child->removeAttribute('tooltip-text');
            $child->removeAttribute('tooltip-position');
            $child->removeAttribute('devider-text');
            if ($safeDivClass !== '') $child->setAttribute('class', $safeDivClass);
            if (str_starts_with($safeDivClass, 'devider') && $deviderText !== '') {
                $child->setAttribute('devider-text', $deviderText);
                // The divider is authored as an empty div. Put the label into the DOM
                // between the two CSS-generated horizontal rules.
                if (trim($child->textContent) === '') $child->appendChild($child->ownerDocument->createTextNode($deviderText));
            }
            if ($tip !== '' && !in_array($tag, ['img', 'br', 'hr'], true)) {
                $child->setAttribute('class', 'tooltip');
                $child->setAttribute('tooltip-text', $tip);
                $child->setAttribute('tooltip-position', $tipPosition);
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
    $doc->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body>' . $text . '</body></html>', LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $doc->getElementsByTagName('body')->item(0);
    if (!$root) return nl2br(e($text));
    content_clean_node($root);
    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    return $out;
}
