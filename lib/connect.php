<?php
declare(strict_types=1);

function env_get(string $name): ?string
{
    $value = getenv($name);
    if ($value === false || $value === '') $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
    return is_string($value) && $value !== '' ? $value : null;
}

/* Single DB connection. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $url = env_get('XWORS_DATABASE_URL');
    if (!$url) throw new RuntimeException('XWORS_DATABASE_URL is not configured');

    $p = parse_url($url);
    if ($p === false || empty($p['host']) ||
        !in_array(strtolower($p['scheme'] ?? ''), ['postgres', 'postgresql'], true)) {
        throw new RuntimeException('XWORS_DATABASE_URL must be a PostgreSQL URL');
    }

    parse_str($p['query'] ?? '', $query);
    $database = rawurldecode(ltrim($p['path'] ?? '', '/'));
    $dsn = 'pgsql:host=' . $p['host'] .
        ';port=' . ($p['port'] ?? 5432) .
        ';dbname=' . ($database ?: 'postgres') .
        ';sslmode=' . ($query['sslmode'] ?? 'require') .
        ';connect_timeout=5';

    $pdo = new PDO(
        $dsn,
        isset($p['user']) ? rawurldecode($p['user']) : null,
        isset($p['pass']) ? rawurldecode($p['pass']) : null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    ensure_schema($pdo);
    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    // One cheap check per request instead of three DDL statements (serverless cold starts).
    $state = $pdo->query(
        "SELECT to_regclass('public.users') IS NOT NULL AS users_ok,
                EXISTS (SELECT 1 FROM information_schema.columns
                        WHERE table_schema = 'public' AND table_name = 'admins' AND column_name = '2fa') AS admins_ok"
    )->fetch();
    if (!empty($state['users_ok']) && !empty($state['admins_ok'])) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        login VARCHAR(255) NOT NULL,
        password VARCHAR(255) NOT NULL,
        \"2fa\" JSONB NOT NULL DEFAULT '{\"enabled\":0,\"secret\":null}'::jsonb
    )");

    $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS \"2fa\" JSONB NOT NULL DEFAULT '{\"enabled\":0,\"secret\":null}'::jsonb");

    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        uuid UUID PRIMARY KEY,
        "key" TEXT NOT NULL,
        expires_at TIMESTAMPTZ NULL,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )');
}
