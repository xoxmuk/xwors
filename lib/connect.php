<?php
/**
 * Подключение к базе данных (PostgreSQL) и автоматическое создание/обновление схемы.
 * Здесь же находится чтение переменных окружения.
 */
declare(strict_types=1);

/**
 * Читает переменную окружения. Пробует getenv(), затем $_ENV и $_SERVER
 * (на разных хостингах значения попадают в разные места).
 * Возвращает null, если переменная не задана или пустая.
 */
function env_get(string $name): ?string
{
    $value = getenv($name);
    if ($value === false || $value === '') $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
    return is_string($value) && $value !== '' ? $value : null;
}

/**
 * Возвращает единственное (на время запроса) подключение к PostgreSQL.
 * Строка подключения берётся из XWORS_DATABASE_URL, формат: postgresql://user:pass@host:5432/db?sslmode=require
 * При первом подключении автоматически проверяется и дорабатывается структура таблиц (ensure_schema).
 */
function db(): PDO
{
    // Подключение кэшируется в статической переменной, чтобы не открывать его повторно за один запрос
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $url = env_get('XWORS_DATABASE_URL');
    if (!$url) throw new RuntimeException('XWORS_DATABASE_URL is not configured');
    // Разбираем URL на части: схема, хост, порт, пользователь, пароль, база, параметры
    $p = parse_url($url);
    if ($p === false || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['postgres', 'postgresql'], true)) throw new RuntimeException('XWORS_DATABASE_URL must be a PostgreSQL URL');
    parse_str($p['query'] ?? '', $query);
    // Имя базы берётся из пути URL; по умолчанию — "postgres"
    $database = rawurldecode(ltrim($p['path'] ?? '', '/'));
    // Собираем DSN для PDO. SSL по умолчанию обязателен, таймаут подключения — 5 секунд
    $dsn = 'pgsql:host=' . $p['host'] . ';port=' . ($p['port'] ?? 5432) . ';dbname=' . ($database ?: 'postgres') . ';sslmode=' . ($query['sslmode'] ?? 'require') . ';connect_timeout=5';
    // Режим ошибок — исключения, выборка — ассоциативные массивы, эмуляция prepared-запросов отключена (настоящие prepared statements)
    $pdo = new PDO($dsn, isset($p['user']) ? rawurldecode($p['user']) : null, isset($p['pass']) ? rawurldecode($p['pass']) : null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    ensure_schema($pdo);
    return $pdo;
}

/**
 * Ожидаемая структура БД: таблица => [запрос CREATE TABLE, список колонок, которые нужно добавить,
 * если таблица уже существует, но создана в старой версии без этих колонок].
 *   users  — ключи доступа клиентов (uuid + 32-символьный key, необязательный срок действия)
 *   admins — администраторы (логин и пароль хранятся как bcrypt-хэши, настройки 2FA — в JSONB)
 *   news   — новости (JSONB с блоками текста и картинками, флаг скрытия)
 *   goods  — товары (статус, картинка, цены в JSONB, скидка, способ покупки, флаг скрытия)
 */
function schema_definition(): array
{
    return [
        'users' => [
            'CREATE TABLE IF NOT EXISTS users (uuid UUID PRIMARY KEY, "key" VARCHAR(32) NOT NULL UNIQUE, expires_at TIMESTAMPTZ NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
            ['expires_at' => 'TIMESTAMPTZ NULL', 'created_at' => 'TIMESTAMPTZ NOT NULL DEFAULT NOW()'],
        ],
        'admins' => [
            'CREATE TABLE IF NOT EXISTS admins (login VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, "2fa" JSONB NOT NULL DEFAULT \'{"enabled":0,"secret":null}\'::jsonb)',
            ['2fa' => 'JSONB NOT NULL DEFAULT \'{"enabled":0,"secret":null}\'::jsonb'],
        ],
        'news' => [
            'CREATE TABLE IF NOT EXISTS news (id SERIAL PRIMARY KEY, version TEXT NOT NULL DEFAULT \'\', data JSONB NOT NULL DEFAULT \'{"blocks":[],"images":[]}\'::jsonb, hidden BOOLEAN NOT NULL DEFAULT FALSE, create_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
            [
                'version' => 'TEXT NOT NULL DEFAULT \'\'',
                'data' => 'JSONB NOT NULL DEFAULT \'{"blocks":[],"images":[]}\'::jsonb',
                'hidden' => 'BOOLEAN NOT NULL DEFAULT FALSE',
                'create_at' => 'TIMESTAMPTZ NOT NULL DEFAULT NOW()',
                'updated_at' => 'TIMESTAMPTZ NOT NULL DEFAULT NOW()',
            ],
        ],
        'goods' => [
            'CREATE TABLE IF NOT EXISTS goods (id SERIAL PRIMARY KEY, status TEXT NOT NULL DEFAULT \'undetected\', image TEXT NOT NULL DEFAULT \'\', name TEXT NOT NULL DEFAULT \'\', description TEXT NOT NULL DEFAULT \'\', discount NUMERIC(5,2) NOT NULL DEFAULT 0, prices JSONB NOT NULL DEFAULT \'[{"name":"1d","price":0,"discount":0}]\'::jsonb, purchase JSONB NOT NULL DEFAULT \'{"type":"none","url":""}\'::jsonb, hidden BOOLEAN NOT NULL DEFAULT FALSE, create_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
            [
                'status' => 'TEXT NOT NULL DEFAULT \'undetected\'',
                'image' => 'TEXT NOT NULL DEFAULT \'\'',
                'name' => 'TEXT NOT NULL DEFAULT \'\'',
                'description' => 'TEXT NOT NULL DEFAULT \'\'',
                'discount' => 'NUMERIC(5,2) NOT NULL DEFAULT 0',
                'prices' => "JSONB NOT NULL DEFAULT '[{\"name\":\"1d\",\"price\":0,\"discount\":0}]'::jsonb",
                'purchase' => 'JSONB NOT NULL DEFAULT \'{"type":"none","url":""}\'::jsonb',
                'hidden' => 'BOOLEAN NOT NULL DEFAULT FALSE',
                'create_at' => 'TIMESTAMPTZ NOT NULL DEFAULT NOW()',
                'updated_at' => 'TIMESTAMPTZ NOT NULL DEFAULT NOW()',
            ],
        ],
    ];
}

/**
 * Приводит схему БД к ожидаемой: одним запросом к information_schema узнаём, что уже есть.
 * Отсутствующие таблицы создаются, отсутствующие колонки добавляются, всё существующее пропускается.
 */
function ensure_schema(PDO $pdo): void
{
    $definition = schema_definition();
    $tables = array_keys($definition);
    // Читаем из information_schema, какие колонки уже есть у наших таблиц в текущей схеме
    $stmt = $pdo->prepare('SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN (' . implode(',', array_fill(0, count($tables), '?')) . ')');
    $stmt->execute($tables);
    // Карта существующего: $existing[таблица][колонка] = true
    $existing = [];
    foreach ($stmt->fetchAll() as $row) $existing[$row['table_name']][$row['column_name']] = true;

    // Создаём недостающие таблицы и добавляем недостающие колонки
    foreach ($definition as $table => [$create, $columns]) {
        if (!isset($existing[$table])) { $pdo->exec($create); continue; }
        foreach ($columns as $column => $type) {
            if (!isset($existing[$table][$column])) $pdo->exec('ALTER TABLE "' . $table . '" ADD COLUMN IF NOT EXISTS "' . $column . '" ' . $type);
        }
    }
    // Одноразовая миграция: старая числовая колонка price превращается в структурированный JSON prices (несколько вариантов цены).
    $goodsColumns = $existing['goods'] ?? [];
    if (isset($goodsColumns['price']) && !isset($goodsColumns['prices'])) {
        $pdo->exec("ALTER TABLE goods ADD COLUMN IF NOT EXISTS prices JSONB NOT NULL DEFAULT '[{\"name\":\"1d\",\"price\":0,\"discount\":0}]'::jsonb");
        $pdo->exec("UPDATE goods SET prices = jsonb_build_array(jsonb_build_object('name','1d','price',price,'discount',0))");
        $pdo->exec('ALTER TABLE goods DROP COLUMN price');
    } elseif (isset($goodsColumns['price'])) {
        $pdo->exec('ALTER TABLE goods DROP COLUMN price');
    }
}
