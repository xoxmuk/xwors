<?php
declare(strict_types=1);

function env_get(string $name): ?string
{
    $value = getenv($name);
    if ($value === false || $value === '') $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
    return is_string($value) && $value !== '' ? $value : null;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $url = env_get('XWORS_DATABASE_URL');
    if (!$url) throw new RuntimeException('XWORS_DATABASE_URL is not configured');
    $p = parse_url($url);
    if ($p === false || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['postgres', 'postgresql'], true)) throw new RuntimeException('XWORS_DATABASE_URL must be a PostgreSQL URL');
    parse_str($p['query'] ?? '', $query);
    $database = rawurldecode(ltrim($p['path'] ?? '', '/'));
    $dsn = 'pgsql:host=' . $p['host'] . ';port=' . ($p['port'] ?? 5432) . ';dbname=' . ($database ?: 'postgres') . ';sslmode=' . ($query['sslmode'] ?? 'require') . ';connect_timeout=5';
    $pdo = new PDO($dsn, isset($p['user']) ? rawurldecode($p['user']) : null, isset($p['pass']) ? rawurldecode($p['pass']) : null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    ensure_schema($pdo);
    return $pdo;
}

/** Expected structure: table => [CREATE TABLE statement, optional columns to add when the table exists without them]. */
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
            'CREATE TABLE IF NOT EXISTS goods (id SERIAL PRIMARY KEY, status TEXT NOT NULL DEFAULT \'undetected\', image TEXT NOT NULL DEFAULT \'\', name TEXT NOT NULL DEFAULT \'\', description TEXT NOT NULL DEFAULT \'\', price NUMERIC(12,2) NOT NULL DEFAULT 0, purchase JSONB NOT NULL DEFAULT \'{"type":"none","url":""}\'::jsonb, hidden BOOLEAN NOT NULL DEFAULT FALSE, create_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
            [
                'status' => 'TEXT NOT NULL DEFAULT \'undetected\'',
                'image' => 'TEXT NOT NULL DEFAULT \'\'',
                'name' => 'TEXT NOT NULL DEFAULT \'\'',
                'description' => 'TEXT NOT NULL DEFAULT \'\'',
                'price' => 'NUMERIC(12,2) NOT NULL DEFAULT 0',
                'purchase' => 'JSONB NOT NULL DEFAULT \'{"type":"none","url":""}\'::jsonb',
                'hidden' => 'BOOLEAN NOT NULL DEFAULT FALSE',
                'create_at' => 'TIMESTAMPTZ NOT NULL DEFAULT NOW()',
                'updated_at' => 'TIMESTAMPTZ NOT NULL DEFAULT NOW()',
            ],
        ],
    ];
}

/** One lookup in information_schema: missing tables are created, missing columns are added, everything that exists is skipped. */
function ensure_schema(PDO $pdo): void
{
    $definition = schema_definition();
    $tables = array_keys($definition);
    $stmt = $pdo->prepare('SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN (' . implode(',', array_fill(0, count($tables), '?')) . ')');
    $stmt->execute($tables);
    $existing = [];
    foreach ($stmt->fetchAll() as $row) $existing[$row['table_name']][$row['column_name']] = true;

    foreach ($definition as $table => [$create, $columns]) {
        if (!isset($existing[$table])) { $pdo->exec($create); continue; }
        foreach ($columns as $column => $type) {
            if (!isset($existing[$table][$column])) $pdo->exec('ALTER TABLE "' . $table . '" ADD COLUMN IF NOT EXISTS "' . $column . '" ' . $type);
        }
    }
}
