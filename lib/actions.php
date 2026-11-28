<?php
/**
 * Обработчик действий /api/<действие>: вход, 2FA, управление пользователями, новостями и товарами, загрузка картинок.
 * Принимаются только POST-запросы с корректным CSRF-токеном.
 */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/content.php';

/** Сохраняет настройки 2FA администратора (JSON: enabled и secret) в колонку admins."2fa". */
function save_2fa(string $login, array $settings): void
{
    $stmt = db()->prepare('UPDATE admins SET "2fa" = CAST(:settings AS jsonb) WHERE login = :login');
    $stmt->execute([':settings' => json_encode($settings, JSON_THROW_ON_ERROR), ':login' => $login]);
}

/** Генерирует случайный ключ доступа из 32 символов (A–Z, a–z, 0–9) криптостойким генератором. */
function random_user_key(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $max = strlen($alphabet) - 1; $key = '';
    for ($i = 0; $i < 32; $i++) $key .= $alphabet[random_int(0, $max)];
    return $key;
}

/** Создаёт пользователя со случайным UUID v4 и случайным 32-символьным ключом; при совпадении UUID/ключа с существующими повторяет попытку (до 10 раз). */
function create_user(PDO $pdo, ?string $expires): array
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $uuid = content_random_uuid(); $key = random_user_key();
        $stmt = $pdo->prepare('INSERT INTO users (uuid, "key", expires_at) VALUES (CAST(:uuid AS uuid), :key, :expires) ON CONFLICT DO NOTHING RETURNING uuid');
        $stmt->execute([':uuid' => $uuid, ':key' => $key, ':expires' => $expires]);
        if ($stmt->fetchColumn() !== false) return ['uuid' => $uuid, 'key' => $key];
    }
    throw new RuntimeException('Could not generate a unique user key');
}

/** Отправляет JSON-ответ с нужным HTTP-кодом и завершает скрипт (используется для ответов на загрузку картинок). */
function upload_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Главный обработчик действий. Сначала проверяет метод (только POST) и CSRF,
 * затем в switch выполняет действие. После успеха почти всегда делает redirect с параметрами
 * (saved, deleted, error…), по которым страницы показывают уведомления.
 */
function handle_action(string $action): void
{
    // Ответы API нельзя кэшировать
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method not allowed');
    }

    try {
        // Превышен post_max_size: PHP отбрасывает всё тело запроса ($_POST и $_FILES пусты), и CSRF выглядел бы «просроченным» —
        // поэтому для загрузки картинки сначала распознаём эту ситуацию и отвечаем понятной ошибкой
        if ($action === 'upload-image' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $limit = content_ini_bytes('post_max_size');
            if ($limit > 0 && (int)$_SERVER['CONTENT_LENGTH'] > $limit) upload_json(['ok' => false, 'code' => 'server_limit', 'error' => 'Файл больше лимита сервера (' . ini_get('post_max_size') . ').'], 413);
        }
        // Защита от CSRF и перебора: без верного токена действие не выполняется
        if (!csrf_valid()) { if ($action === 'upload-image') upload_json(['ok' => false, 'code' => 'csrf', 'error' => 'Сессия истекла. Обновите страницу и повторите загрузку.'], 403); http_response_code(403); exit('Invalid request'); }

        switch ($action) {
            // Выход из админки
            case 'logout':
                if (auth_user() === null) { http_response_code(403); exit('Not authenticated'); }
                auth_logout(); redirect('/admin');

            // Шаг 1 входа: проверка логина и пароля. Если включена 2FA — переходим к вводу кода, иначе сразу входим
            case 'authorization':
                $login = trim((string)($_POST['login'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                if ($login === '' || $password === '') redirect('/admin?error=1');
                $admin = admin_by_login($login);
                if (!$admin || !password_verify($password, (string)$admin['password'])) redirect('/admin?error=1');
                if (!empty($admin['2fa']['enabled'])) { auth_pending_login($login); redirect('/admin?step=2fa'); }
                auth_login($login); redirect('/admin/dashboard/');

            // Шаг 2 входа: проверка шестизначного кода из аутентификатора
            case 'verify-2fa':
                $login = pending_auth_user();
                if ($login === null) redirect('/admin?error=expired');
                $admin = admin_by_login($login);
                if (!$admin || empty($admin['2fa']['enabled']) || empty($admin['2fa']['secret']) || !totp_verify((string)$admin['2fa']['secret'], (string)($_POST['code'] ?? ''))) redirect('/admin?step=2fa&error=1');
                auth_login($login); redirect('/admin/dashboard/');

            // Начало подключения 2FA: генерируем секрет (пока выключен — включится после подтверждения кодом)
            case 'setup-2fa':
                $login = require_admin(); $admin = admin_by_login($login);
                if (!$admin) redirect('/admin');
                if (!empty($admin['2fa']['enabled'])) redirect('/admin/dashboard/security');
                if (empty($admin['2fa']['secret'])) save_2fa($admin['login'], ['enabled' => 0, 'secret' => totp_generate_secret()]);
                redirect('/admin/dashboard/security');

            // Включение 2FA после подтверждения кодом из приложения
            case 'enable-2fa':
                require_admin(); $login = auth_user(); $admin = $login ? admin_by_login($login) : null;
                $code = (string)($_POST['code'] ?? '');
                if (!$admin || empty($admin['2fa']['secret']) || !totp_verify((string)$admin['2fa']['secret'], $code)) redirect('/admin/dashboard/security?error=code');
                save_2fa($admin['login'], ['enabled' => 1, 'secret' => $admin['2fa']['secret']]); redirect('/admin/dashboard/security?enabled=1');

            // Отключение 2FA — только при вводе текущего кода, секрет стирается
            case 'disable-2fa':
                require_admin(); $login = auth_user(); $admin = $login ? admin_by_login($login) : null;
                $code = (string)($_POST['code'] ?? '');
                if (!$admin || empty($admin['2fa']['enabled']) || empty($admin['2fa']['secret']) || !totp_verify((string)$admin['2fa']['secret'], $code)) redirect('/admin/dashboard/security?error=code');
                save_2fa($admin['login'], ['enabled' => 0, 'secret' => null]); redirect('/admin/dashboard/security?disabled=1');

            // Создание пользователя (ключа доступа); дата окончания необязательна — без неё ключ бессрочный
            case 'create-user':
                require_admin();
                $expiresRaw = trim((string)($_POST['expires_at'] ?? ''));
                $expires = null;
                if ($expiresRaw !== '') {
                    $timestamp = strtotime(str_replace('T', ' ', $expiresRaw));
                    if ($timestamp === false) redirect('/admin/dashboard/users?error=date');
                    $expires = date('Y-m-d H:i:sP', $timestamp);
                }
                $created = create_user(db(), $expires);
                redirect('/admin/dashboard/users?created=' . $created['uuid']);

            // Удаление пользователя по UUID (формат UUID проверяется регулярным выражением)
            case 'delete-user':
                require_admin();
                $uuid = (string)($_POST['uuid'] ?? '');
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
                    db()->prepare('DELETE FROM users WHERE uuid = CAST(:uuid AS uuid)')->execute([':uuid' => $uuid]);
                }
                redirect('/admin/dashboard/users?deleted=1');

            // Загрузка картинки (ответ в JSON). Лимит размера: 2 МБ для товаров, 1 МБ для остального.
            // Коды ошибок хранилища (нет прав, диск заполнен и т.п.) возвращаются со статусом 507, остальные — 422
            case 'upload-image':
                if (auth_user() === null) upload_json(['ok' => false, 'error' => 'Сессия истекла. Обновите страницу и войдите снова.'], 403);
                $folder = (string)($_POST['folder'] ?? '');
                $result = content_store_upload($_FILES['file'] ?? [], $folder, $folder === 'goods' ? 2 * 1024 * 1024 : 1024 * 1024);
                if (isset($result['path'])) upload_json(['ok' => true, 'path' => $result['path']]);
                upload_json(['ok' => false, 'code' => $result['code'] ?? 'error', 'error' => $result['error'] ?? 'Ошибка загрузки.'], ($result['code'] ?? '') === 'readonly' || in_array($result['code'] ?? '', ['mkdir', 'not_writable', 'move', 'disk_full', 'no_tmp', 'tmp_write', 'verify'], true) ? 507 : 422);

            // Создание/редактирование новости: версия, блоки (заголовок + описание) и флаг скрытия
            case 'save-news':
                require_admin();
                $id = (int)($_POST['id'] ?? 0); $version = trim((string)($_POST['version'] ?? '')); $hidden = isset($_POST['hidden']);
                // Собираем блоки из параллельных массивов полей формы, пропуская полностью пустые
                $blocks = []; $titles = $_POST['block_title'] ?? []; $descriptions = $_POST['block_description'] ?? [];
                if (is_array($titles)) foreach ($titles as $index => $title) {
                    $title = trim((string)$title); $description = trim((string)($descriptions[$index] ?? ''));
                    if ($title === '' && $description === '') continue;
                    $blocks[] = ['title' => $title, 'description' => $description];
                }
                $images = []; $oldImages = [];
                if ($id > 0) {
                    $stmt = db()->prepare('SELECT data FROM news WHERE id = :id'); $stmt->execute([':id' => $id]); $existing = $stmt->fetchColumn();
                    if ($existing === false) redirect('/admin/dashboard/news?error=notfound');
                    $oldImages = news_data((string)$existing)['images'];
                    // Редактора картинок в форме пока нет: сохранённые ссылки на картинки никогда не теряем.
                    $images = $oldImages;
                }
                // Новость без версии и без текста сохранять нельзя
                if ($version === '' && !$blocks) redirect('/admin/dashboard/news?error=empty');
                $json = json_encode(['blocks' => $blocks, 'images' => $images], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if ($id > 0) {
                    $stmt = db()->prepare('UPDATE news SET version = :version, data = CAST(:data AS jsonb), hidden = CAST(:hidden AS boolean), updated_at = NOW() WHERE id = :id');
                    $stmt->execute([':version' => $version, ':data' => $json, ':hidden' => $hidden ? 1 : 0, ':id' => $id]);
                    content_cleanup_images(db(), array_diff($oldImages, $images));
                } else {
                    $stmt = db()->prepare('INSERT INTO news (version, data, hidden) VALUES (:version, CAST(:data AS jsonb), CAST(:hidden AS boolean)) RETURNING id');
                    $stmt->execute([':version' => $version, ':data' => $json, ':hidden' => $hidden ? 1 : 0]); $id = (int)$stmt->fetchColumn();
                }
                redirect('/admin/dashboard/news?edit=' . $id . '&saved=1');

            // Удаление новости вместе с её неиспользуемыми картинками
            case 'delete-news':
                require_admin(); $id = (int)($_POST['id'] ?? 0); $pdo = db();
                $stmt = $pdo->prepare('SELECT data FROM news WHERE id = :id'); $stmt->execute([':id' => $id]); $old = $stmt->fetchColumn();
                $pdo->prepare('DELETE FROM news WHERE id = :id')->execute([':id' => $id]);
                if ($old !== false) content_cleanup_images($pdo, news_data((string)$old)['images']);
                redirect('/admin/dashboard/news?deleted=1');
            // Переключение видимости новости (скрыта/опубликована)
            case 'toggle-news':
                require_admin();
                $stmt = db()->prepare('UPDATE news SET hidden = NOT hidden, updated_at = NOW() WHERE id = :id RETURNING hidden');
                $stmt->execute([':id' => (int)($_POST['id'] ?? 0)]);
                $nowHidden = $stmt->fetchColumn();
                redirect('/admin/dashboard/news?toggled=' . ($nowHidden === false ? 'visible' : (filter_var($nowHidden, FILTER_VALIDATE_BOOLEAN) ? 'hidden' : 'visible')));

            // Создание/редактирование товара: название, описание, статус, цены, скидка, картинка, кнопка «Купить»
            case 'save-good':
                require_admin();
                $id = (int)($_POST['id'] ?? 0); $name = trim((string)($_POST['name'] ?? '')); $description = trim((string)($_POST['description'] ?? '')); $status = (string)($_POST['status'] ?? 'undetected');
                if (!isset(goods_statuses()[$status])) $status = 'undetected';
                $hidden = isset($_POST['hidden']);
                // Общая скидка товара в процентах: допускаем запятую как разделитель, диапазон 0–100
                $discountRaw = str_replace(',', '.', trim((string)($_POST['discount'] ?? '0')));
                if (!is_numeric($discountRaw) || !is_finite((float)$discountRaw) || (float)$discountRaw < 0 || (float)$discountRaw > 100) redirect('/admin/dashboard/goods?' . ($id > 0 ? 'edit=' . $id . '&' : 'new=1&') . 'error=price');
                $productDiscount = round((float)$discountRaw, 2);
                // Варианты цены приходят JSON-строкой из редактора цен; каждый вариант проверяется отдельно
                $pricesRaw = (string)($_POST['prices'] ?? '[]');
                $pricesInput = json_decode($pricesRaw, true);
                $prices = [];
                if (!is_array($pricesInput) || !$pricesInput) redirect('/admin/dashboard/goods?' . ($id > 0 ? 'edit=' . $id . '&' : 'new=1&') . 'error=price');
                foreach ($pricesInput as $priceItem) {
                    if (!is_array($priceItem)) continue;
                    // Название варианта — до 40 символов, цена 0…9 999 999 999,99, скидка 0…100%
                    $priceName = trim((string)($priceItem['name'] ?? ''));
                    $priceRaw = str_replace(',', '.', trim((string)($priceItem['price'] ?? '')));
                    $discountRaw = str_replace(',', '.', trim((string)($priceItem['discount'] ?? '0')));
                    if ($priceName === '' || mb_strlen($priceName) > 40 || !is_numeric($priceRaw) || !is_finite((float)$priceRaw) || (float)$priceRaw < 0 || (float)$priceRaw > 9999999999.99 || !is_numeric($discountRaw) || !is_finite((float)$discountRaw) || (float)$discountRaw < 0 || (float)$discountRaw > 100) {
                        redirect('/admin/dashboard/goods?' . ($id > 0 ? 'edit=' . $id . '&' : 'new=1&') . 'error=price');
                    }
                    $prices[] = ['name' => $priceName, 'price' => round((float)$priceRaw, 2), 'discount' => round((float)$discountRaw, 2)];
                }
                if (!$prices) redirect('/admin/dashboard/goods?error=price');
                $pricesJson = json_encode($prices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                // При редактировании запоминаем прежнюю картинку, чтобы после замены удалить неиспользуемый файл
                if ($id > 0) { $stmt = db()->prepare('SELECT image FROM goods WHERE id = :id'); $stmt->execute([':id' => $id]); $existing = $stmt->fetchColumn(); if ($existing === false) redirect('/admin/dashboard/goods?error=notfound'); $oldImage = (string)$existing; }
                // image: '__keep__' — оставить сохранённое значение, '' — удалить картинку, иначе принимается только загруженный локальный путь /assets/goods
                $imageInput = trim((string)($_POST['image'] ?? '__keep__'));
                if ($imageInput === '__keep__') $image = $oldImage;
                elseif ($imageInput !== '' && content_local_image($imageInput) && str_starts_with($imageInput, '/assets/goods/') && content_valid_image($imageInput)) $image = $imageInput;
                if ($name === '') redirect('/admin/dashboard/goods?error=name');
                // Проверяем, куда ведёт кнопка «Купить» (Telegram, внешняя ссылка или страница сайта)
                $buy = goods_buy_resolve((string)($_POST['buy_type'] ?? 'none'), (string)($_POST['buy_value'] ?? ''), (string)($_POST['buy_page'] ?? ''));
                if (isset($buy['error'])) redirect('/admin/dashboard/goods?' . ($id > 0 ? 'edit=' . $id . '&' : 'new=1&') . 'error=buy&msg=' . rawurlencode($buy['error']));
                $purchaseJson = json_encode(['type' => $buy['type'], 'url' => $buy['url']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if ($id > 0) {
                    $stmt = db()->prepare('UPDATE goods SET status = :status, image = :image, name = :name, description = :description, prices = CAST(:prices AS jsonb), discount = :discount, purchase = CAST(:purchase AS jsonb), hidden = CAST(:hidden AS boolean), updated_at = NOW() WHERE id = :id');
                    $stmt->execute([':status' => $status, ':image' => $image, ':name' => $name, ':description' => $description, ':prices' => $pricesJson, ':discount' => $productDiscount, ':purchase' => $purchaseJson, ':hidden' => $hidden ? 1 : 0, ':id' => $id]);
                    if ($oldImage !== $image) content_cleanup_images(db(), [$oldImage]);
                } else {
                    $stmt = db()->prepare('INSERT INTO goods (status, image, name, description, prices, discount, purchase, hidden) VALUES (:status, :image, :name, :description, CAST(:prices AS jsonb), :discount, CAST(:purchase AS jsonb), CAST(:hidden AS boolean)) RETURNING id');
                    $stmt->execute([':status' => $status, ':image' => $image, ':name' => $name, ':description' => $description, ':prices' => $pricesJson, ':discount' => $productDiscount, ':purchase' => $purchaseJson, ':hidden' => $hidden ? 1 : 0]); $id = (int)$stmt->fetchColumn();
                }
                redirect('/admin/dashboard/goods?edit=' . $id . '&saved=1');

            // Удаление товара вместе с его неиспользуемой картинкой
            case 'delete-good':
                require_admin(); $id = (int)($_POST['id'] ?? 0); $pdo = db();
                $stmt = $pdo->prepare('SELECT image FROM goods WHERE id = :id'); $stmt->execute([':id' => $id]); $old = $stmt->fetchColumn();
                $pdo->prepare('DELETE FROM goods WHERE id = :id')->execute([':id' => $id]);
                if ($old !== false) content_cleanup_images($pdo, [(string)$old]);
                redirect('/admin/dashboard/goods?deleted=1');
            // Переключение видимости товара (скрыт/опубликован)
            case 'toggle-good':
                require_admin();
                $stmt = db()->prepare('UPDATE goods SET hidden = NOT hidden, updated_at = NOW() WHERE id = :id RETURNING hidden');
                $stmt->execute([':id' => (int)($_POST['id'] ?? 0)]);
                $nowHidden = $stmt->fetchColumn();
                redirect('/admin/dashboard/goods?toggled=' . ($nowHidden === false ? 'visible' : (filter_var($nowHidden, FILTER_VALIDATE_BOOLEAN) ? 'hidden' : 'visible')));
            // Быстрая смена статуса товара (Undetected/Detected/Updating) из списка
            case 'set-good-status':
                require_admin(); $id = (int)($_POST['id'] ?? 0); $status = (string)($_POST['status'] ?? '');
                if (!isset(goods_statuses()[$status])) redirect('/admin/dashboard/goods?error=status');
                db()->prepare('UPDATE goods SET status = :status, updated_at = NOW() WHERE id = :id')->execute([':status' => $status, ':id' => $id]); redirect('/admin/dashboard/goods?toggled=1');

            // Неизвестное действие
            default: http_response_code(404); exit('Not found');
        }
    // Любая непредвиденная ошибка (например, недоступна БД): пишем в лог и отвечаем 503, не раскрывая подробностей
    } catch (Throwable $e) { error_log('XWORS admin API error: ' . $e->getMessage()); if ($action === 'upload-image') upload_json(['ok' => false, 'code' => 'exception', 'error' => 'Внутренняя ошибка сервера. Попробуйте ещё раз позже.'], 503); http_response_code(503); exit('Database unavailable'); }
}
