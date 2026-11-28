<?php
/**
 * Контент сайта: новости и товары (разбор данных, статусы, цены, кнопка «Купить»),
 * загрузка и проверка картинок, а также безопасный вывод HTML из описаний (санитайзер по белому списку).
 */
declare(strict_types=1);

/**
 * Разбирает JSON поля news.data в безопасную структуру ['blocks' => [{title, description}], 'images' => [...]].
 * Пустые блоки и некорректные значения отбрасываются.
 */
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

/** Заголовок первого блока новости, у которого он не пуст (пустая строка, если такого нет). */
function news_first_title(array $data): string
{
    foreach ($data['blocks'] ?? [] as $block) if (is_array($block) && trim((string)($block['title'] ?? '')) !== '') return trim((string)$block['title']);
    return '';
}


/**
 * Разбирает goods.prices (JSONB): список вариантов цены [{name, price, discount}].
 * Некорректные элементы пропускаются; если ничего не осталось, возвращается вариант по умолчанию «1d» с нулевой ценой.
 */
function goods_prices(?string $json): array
{
    $decoded = json_decode((string)$json, true);
    // Совместимость со старым форматом (одно число вместо JSON) на время миграции.
    if (!is_array($decoded)) {
        $legacy = is_numeric($json) ? (float)$json : 0;
        return [['name' => '1d', 'price' => max(0, $legacy), 'discount' => 0]];
    }
    $items = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) continue;
        $name = trim((string)($item['name'] ?? ''));
        $price = $item['price'] ?? null;
        $discount = $item['discount'] ?? 0;
        if ($name === '' || !is_numeric($price) || !is_numeric($discount)) continue;
        $price = (float)$price; $discount = (float)$discount;
        if (!is_finite($price) || $price < 0 || $price > 9999999999.99) continue;
        if (!is_finite($discount) || $discount < 0 || $discount > 100) $discount = 0;
        $items[] = ['name' => mb_substr($name, 0, 40), 'price' => round($price, 2), 'discount' => round($discount, 2)];
    }
    return $items ?: [['name' => '1d', 'price' => 0, 'discount' => 0]];
}

/** Допустимые статусы товара: ключ => подпись и цвет индикатора. */
function goods_statuses(): array
{
    return ['undetected' => ['label' => 'Undetected', 'color' => 'lime'], 'detected' => ['label' => 'Detected', 'color' => 'red'], 'updating' => ['label' => 'Updating', 'color' => 'orange']];
}
/** Подпись и цвет статуса; для неизвестного статуса возвращается «Undetected». */
function goods_status_meta(?string $status): array { $all = goods_statuses(); return $all[(string)$status] ?? $all['undetected']; }
/** Следующий статус по кругу (Undetected → Detected → Updating → …). */
function goods_next_status(?string $status): string { $keys = array_keys(goods_statuses()); $index = array_search((string)$status, $keys, true); return $keys[$index === false ? 0 : ($index + 1) % count($keys)]; }
/** Варианты поведения кнопки «Купить» в редакторе товара. */
function goods_buy_types(): array
{
    return [
        'none' => 'Не активна',
        'telegram' => 'Telegram',
        'link' => 'Внешняя ссылка',
        'page' => 'Страница сайта',
    ];
}
/** Разбирает goods.purchase (JSONB {"type","url"}) в безопасную пару ['type','url']; при ошибке — кнопка неактивна. */
function goods_purchase(?string $json): array
{
    $d = json_decode((string)$json, true);
    $type = is_array($d) ? (string)($d['type'] ?? 'none') : 'none';
    $url = is_array($d) ? trim((string)($d['url'] ?? '')) : '';
    if (!isset(goods_buy_types()[$type]) || ($type !== 'none' && $url === '')) return ['type' => 'none', 'url' => ''];
    return ['type' => $type, 'url' => $url];
}
/** Страницы сайта, на которые может вести кнопка «Купить» (тип «Страница сайта»). */
function goods_buy_pages(): array { return ['/' => 'Главная', '/news' => 'Новости']; }

/**
 * Проверяет назначение кнопки «Купить» и нормализует его.
 * Возвращает ['type' => ..., 'url' => ...] или ['error' => сообщение для администратора].
 */
function goods_buy_resolve(string $type, string $value, string $page): array
{
    $value = trim($value);
    switch ($type) {
        case 'none': return ['type' => 'none', 'url' => ''];
        // Telegram: принимаем @username, username, t.me/username и ссылки-приглашения; приводим к виду https://t.me/…
        case 'telegram':
            if ($value === '') return ['error' => 'Укажите Telegram: @username или ссылку t.me/…'];
            if (preg_match('#^(?:https?://)?(?:t(?:elegram)?\.me/)?@?([A-Za-z][A-Za-z0-9_]{4,31})/?$#i', $value, $m)) return ['type' => 'telegram', 'url' => 'https://t.me/' . $m[1]];
            if (preg_match('#^https?://t\.me/\+[A-Za-z0-9_-]+$#i', $value) || preg_match('#^https?://t\.me/joinchat/[A-Za-z0-9_-]+$#i', $value)) return ['type' => 'telegram', 'url' => $value];
            return ['error' => 'Некорректный Telegram: нужен @username (5–32 символа: латиница, цифры, _) или ссылка t.me/…'];
        // Внешняя ссылка: при отсутствии схемы добавляем https://, затем проверяем формат и длину (до 500 символов)
        case 'link':
            if ($value === '') return ['error' => 'Укажите ссылку для кнопки «Купить».'];
            if (!preg_match('#^https?://#i', $value)) $value = 'https://' . $value;
            if (filter_var($value, FILTER_VALIDATE_URL) === false || strlen($value) > 500) return ['error' => 'Некорректная ссылка. Пример: https://example.com/buy'];
            return ['type' => 'link', 'url' => $value];
        // Страница сайта: допускается только значение из белого списка goods_buy_pages()
        case 'page':
            if (!isset(goods_buy_pages()[$page])) return ['error' => 'Выберите страницу сайта из списка.'];
            return ['type' => 'page', 'url' => $page];
    }
    return ['error' => 'Неизвестный способ покупки.'];
}

/** Форматирует цену для показа: «1 234,50 ₽»; нулевая и отрицательная цена выводится как «Цена по запросу». */
function content_price(float|int|string $value): string { $number = (float)$value; if ($number <= 0) return 'Цена по запросу'; $decimals = fmod($number, 1.0) === 0.0 ? 0 : 2; return number_format($number, $decimals, ',', ' ') . ' ₽'; }
const CONTENT_IMAGE_FOLDERS = ['news', 'goods'];   // папки, в которые разрешена загрузка картинок
// Допустимые MIME-типы картинок и соответствующие расширения файлов
const CONTENT_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif'];
// Шаблон локального пути картинки: /assets/<папка>/<sha256-хэш>.<расширение>
const CONTENT_LOCAL_IMAGE = '#^/assets/(news|goods)/[a-f0-9]{64}\.(jpg|png|gif|webp|avif)$#';

/** Каталог на диске, где хранятся загруженные картинки указанной папки. */
function content_assets_dir(string $folder): string { return dirname(__DIR__) . '/public/assets/' . $folder; }

/** Проверяет, что значение — путь к картинке, загруженной через админку (по шаблону CONTENT_LOCAL_IMAGE). */
function content_local_image(string $value): bool { return (bool)preg_match(CONTENT_LOCAL_IMAGE, $value); }

/**
 * Проверяет, что значение можно безопасно выводить как картинку:
 * data:-URI (до 2 МБ), существующий локальный файл или HTTPS-ссылка.
 */
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

const CONTENT_MAX_PIXELS = 8000;   // максимальная ширина/высота загружаемой картинки в пикселях

/** Переводит значение из php.ini вида «8M», «2G», «512K» в байты. */
function content_ini_bytes(string $name): int
{
    $value = trim((string)ini_get($name));
    if ($value === '') return 0;
    $number = (float)$value;
    return (int)match (strtolower(substr($value, -1))) { 'g' => $number * 1073741824, 'm' => $number * 1048576, 'k' => $number * 1024, default => $number };
}

/** Форматирует размер в мегабайтах для сообщений об ошибках, например «1,5 МБ». */
function content_format_mb(int $bytes): string { return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',') . ' МБ'; }

/** Возвращает true, если приложение не может писать в свою папку (Vercel и другие хостинги «только для чтения»). */
function content_storage_readonly(string $dir): bool
{
    if (env_get('VERCEL') !== null || env_get('AWS_LAMBDA_FUNCTION_NAME') !== null) return true;
    return is_dir($dir) ? !is_writable($dir) : !is_writable(dirname($dir));
}

/**
 * Проверяет загруженную картинку, считает хэш её содержимого (SHA-256) и сохраняет как public/assets/<папка>/<хэш>.<расширение>.
 * Одинаковые файлы получают одно имя, поэтому дубликаты не создаются.
 * Возвращает ['path' => '/assets/<папка>/<хэш>.<расширение>'] или ['error' => 'сообщение', 'code' => 'код'].
 */
function content_store_upload(array $file, string $folder, int $maxBytes): array
{
    // Короткая функция-помощник для формирования ответа с ошибкой
    $fail = static fn(string $code, string $message): array => ['error' => $message, 'code' => $code];
    if (!in_array($folder, CONTENT_IMAGE_FOLDERS, true)) return $fail('folder', 'Неизвестная папка для загрузки.');

    // Расшифровываем код ошибки загрузки PHP в понятное сообщение
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

    // Проверяем, что файл действительно пришёл через HTTP-загрузку, и его размер
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return $fail('not_uploaded', 'Не удалось получить файл.');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) return $fail('empty', 'Файл пустой (0 байт).');
    if ($size > $maxBytes) return $fail('too_big', 'Файл весит ' . content_format_mb($size) . ', допустимо не больше ' . content_format_mb($maxBytes) . '.');

    // Определяем реальный тип и размеры по содержимому (а не по имени файла) и ограничиваем разрешение
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

    // Локальное хранение (VPS): проверяем каталог, права и свободное место
    $dir = content_assets_dir($folder);
    if (content_storage_readonly($dir)) return $fail('readonly', 'Сервер работает в режиме «только чтение». Для Vercel добавьте PUBLIC_BLOB_READ_WRITE_TOKEN (Vercel Blob) или укажите URL изображения.');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return $fail('mkdir', 'Не удалось создать папку public/assets/' . $folder . '. Проверьте права на запись.');
    if (!is_writable($dir)) return $fail('not_writable', 'Нет прав на запись в папку public/assets/' . $folder . '.');

    // Файл с таким хэшем уже может существовать — тогда повторно не сохраняем
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

/** Удаляет локальные файлы картинок, на которые больше не ссылается ни одна новость и ни один товар. */
function content_cleanup_images(PDO $pdo, array $paths): void
{
    foreach (array_unique($paths) as $path) {
        if (!is_string($path) || !content_local_image($path)) continue;
        $stmt = $pdo->prepare('SELECT 1 FROM goods WHERE image = :p UNION ALL SELECT 1 FROM news WHERE data::text LIKE :like LIMIT 1');
        $stmt->execute([':p' => $path, ':like' => '%' . $path . '%']);
        if ($stmt->fetchColumn() === false) @unlink(dirname(__DIR__) . $path);
    }
}

/** Генерирует случайный UUID версии 4 (с правильными битами версии и варианта). */
function content_random_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

// Белый список HTML-тегов для новостей и описаний: тег => разрешённые атрибуты
const CONTENT_HTML_TAGS = [
    'a' => ['href', 'title'], 'abbr' => ['title'], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'del' => [], 'mark' => [],
    'small' => [], 'sub' => [], 'sup' => [], 'code' => [], 'pre' => [], 'kbd' => [], 'br' => [], 'hr' => [], 'p' => [], 'div' => [], 'span' => [],
    'blockquote' => [], 'ul' => [], 'ol' => [], 'li' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
    'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
    'img' => ['src', 'alt', 'width', 'height', 'title'],
];
// Теги, которые вырезаются вместе с содержимым (остальные неразрешённые теги заменяются своим текстом)
const CONTENT_HTML_DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'svg', 'math', 'video', 'audio', 'base', 'noscript', 'template'];

/** Проверяет безопасность ссылки: для картинок только https/локальные пути, для ссылок ещё #якорь и mailto:. Протоколы вроде javascript: отклоняются. */
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

/**
 * Рекурсивно очищает DOM-узел: удаляет запрещённые теги и атрибуты, проверяет ссылки,
 * фильтрует inline-стили, обрабатывает специальные элементы (tooltip и разделитель devider)
 * и принудительно добавляет безопасные атрибуты ссылкам и картинкам.
 */
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
            // Оставляем только разрешённые атрибуты; в href/src пропускаем лишь безопасные адреса
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                if (in_array($name, ['tooltip-text', 'tooltip-position', 'class', 'style'], true)) continue; // эти атрибуты обрабатываются ниже
                if ($tag === 'div' && $name === 'devider-text') continue;
                if (!in_array($name, CONTENT_HTML_TAGS[$tag], true)) { $child->removeAttribute($attr->name); continue; }
                if (($name === 'href' || $name === 'src') && !content_safe_url($attr->value, $name === 'src')) $child->removeAttribute($attr->name);
            }
            // Подсказка (tooltip): tooltip-text="…" (class="tooltip" добавляется автоматически); других классов тут не допускается
            $tip = trim($child->getAttribute('tooltip-text'));
            $tipPosition = trim($child->getAttribute('tooltip-position'));
            $validTipPositions = ['left-up', 'up', 'right-up', 'left', 'right', 'left-bottom', 'bottom', 'right-bottom'];
            if (!in_array($tipPosition, $validTipPositions, true)) $tipPosition = 'up';
            if (preg_match('/^.{0,300}/su', $tip, $m)) $tip = $m[0];
            $requestedClasses = preg_split('/\\s+/', trim($child->getAttribute('class'))) ?: [];
            $safeDivClass = '';
            $deviderText = '';
            if ($tag !== 'div') $safeDivClass = implode(' ', array_slice(array_filter($requestedClasses, static fn($c) => (bool)preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,40}$/', $c)), 0, 12));
            if ($tag === 'div') {
                // Сохраняем только поддерживаемые классы оформления. Разделитель можно выровнять
                // классами "devider left" или "devider right"; произвольные классы отбрасываются.
                $safeClasses = array_values(array_filter($requestedClasses, static fn($c) => (bool)preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,40}$/', $c)));
                if (in_array('devider', $safeClasses, true)) {
                    $alignment = in_array('left', $safeClasses, true) ? 'left'
                        : (in_array('right', $safeClasses, true) ? 'right' : '');
                    $safeDivClass = 'devider' . ($alignment !== '' ? ' ' . $alignment : '');
                    $deviderText = mb_substr(trim($child->getAttribute('devider-text')), 0, 160);
                } else { $safeDivClass = implode(' ', array_slice($safeClasses, 0, 12)); }
            }
            $child->removeAttribute('class');
            $child->removeAttribute('tooltip-text');
            $child->removeAttribute('tooltip-position');
            $child->removeAttribute('devider-text');
            if ($safeDivClass !== '') $child->setAttribute('class', $safeDivClass);
            // Безопасные inline-стили: разрешены только свойства оформления; ссылки, expression и разметка отклоняются.
            $style = $child->getAttribute('style');
            $safeStyles = [];
            $allowedStyles = ['color','background-color','border','border-color','border-radius','padding','padding-top','padding-right','padding-bottom','padding-left','margin','margin-top','margin-bottom','font-size','font-weight','font-style','text-align','text-decoration','display','gap','width','max-width','opacity','box-shadow'];
            foreach (explode(';', $style) as $declaration) {
                if (!str_contains($declaration, ':')) continue;
                [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                if (in_array(strtolower($property), $allowedStyles, true) && $value !== '' && !preg_match('/url\s*\(|expression|[<>]|javascript/i', $value) && strlen($value) <= 120) $safeStyles[] = strtolower($property) . ':' . $value;
            }
            if ($safeStyles) $child->setAttribute('style', implode(';', $safeStyles));

            if (str_starts_with($safeDivClass, 'devider') && $deviderText !== '') {
                $child->setAttribute('devider-text', $deviderText);
                // Разделитель пишется как пустой div. Помещаем подпись в DOM
                // между двумя линиями, которые рисует CSS.
                if (trim($child->textContent) === '') $child->appendChild($child->ownerDocument->createTextNode($deviderText));
            }
            if ($tip !== '' && !in_array($tag, ['img', 'br', 'hr'], true)) {
                $child->setAttribute('class', 'tooltip');
                $child->setAttribute('tooltip-text', $tip);
                $child->setAttribute('tooltip-position', $tipPosition);
                $child->setAttribute('tabindex', '0');
            }
            // Внешние ссылки открываются в новой вкладке без передачи referrer и без влияния на SEO; картинки грузятся лениво
            if ($tag === 'a') { $child->setAttribute('rel', 'noopener noreferrer nofollow'); $child->setAttribute('target', '_blank'); }
            if ($tag === 'img') $child->setAttribute('loading', 'lazy');
            content_clean_node($child);
        } elseif (!($child instanceof DOMText)) {
            $node->removeChild($child);
        }
    }
}

/** Выводит текст новости: простой текст сохраняет переносы строк, текст с тегами очищается по белому списку безопасного HTML. */
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

/**
 * Готовит описание товара для content_render_html():
 *  - подряд идущие строки, начинающиеся с «∘» (или «◦»), превращаются в один <ul> с пунктами <li>;
 *  - остальные строки обычного текста становятся блоками <p> (чтобы переносы строк сохранялись в HTML-режиме);
 *  - строки, начинающиеся с тега (<div class="devider …">, <a>, <ul>…), остаются как написал автор;
 *  - строки внутри незакрытого блочного тега (многострочный <ul>…</ul>) не трогаются.
 * После маркера допускается inline-HTML: "∘ <p tooltip-text="…">Подсказка</p>".
 */
function content_description_blocks(string $text): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if ($text === '') return '';

    // Список блочных тегов — по ним отслеживаем вложенность (depth), чтобы не ломать многострочные блоки
    $blockTags = 'ul|ol|li|div|p|blockquote|table|thead|tbody|tr|th|td|pre|h3|h4|h5|h6';
    $out = [];
    $items = [];
    $depth = 0;
    // Сбрасывает накопленные пункты списка в один блок <ul>
    $flush = static function () use (&$out, &$items): void {
        if ($items) $out[] = '<ul>' . implode('', $items) . '</ul>';
        $items = [];
    };

    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '') { if ($depth === 0) $flush(); continue; }

        $isItem = $depth === 0 && preg_match('/^[\x{2218}\x{25E6}]\s*(.*)$/su', $line, $m) === 1;
        $content = $isItem ? trim($m[1]) : $line;
        if ($isItem && $content === '') continue;

        if ($isItem) {
            $items[] = '<li>' . $content . '</li>';
        } else {
            if ($depth === 0) {
                $flush();
                $out[] = $line[0] === '<' ? $line : '<p>' . $line . '</p>';
            } else {
                $out[] = $line;
            }
        }

        // Пересчитываем глубину вложенности по числу открывающих и закрывающих блочных тегов в строке
        $opens = preg_match_all('#<(?:' . $blockTags . ')(?=[\s>/])[^>]*(?<!/)>#i', $content);
        $closes = preg_match_all('#</(?:' . $blockTags . ')\s*>#i', $content);
        $depth = max(0, $depth + (int)$opens - (int)$closes);
    }
    $flush();
    return implode("\n", $out);
}

/** Выводит описание товара: строки с «∘» → пункты списка, затем тот же безопасный HTML, что и в новостях (devider, tooltip, ссылки). */
function content_render_description(string $text): string
{
    return content_render_html(content_description_blocks($text));
}

