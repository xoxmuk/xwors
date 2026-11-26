# XWorsClient

PHP 8+ / PostgreSQL. Public pages: `/`, `/news`, `/prices`. Admin login: `/admin`; dashboard: `/admin/dashboard/`.

## Environment variables
- `XWORS_DATABASE_URL` — PostgreSQL connection URL.
- `AUTH_SECRET` is temporarily hardcoded in `lib/auth.php`; replace it with an environment variable before deployment.

The app creates `admins` and `users` tables and adds a `2fa` JSONB column to `admins`. The old `panel_login_attempts` table is no longer used and is dropped automatically by the application.

## Admin credentials
Both `admins.login` and `admins.password` store bcrypt hashes. Generate them:
```bash
php -r 'echo password_hash("hashed login", PASSWORD_BCRYPT), PHP_EOL;'
php -r 'echo password_hash("hashed password", PASSWORD_BCRYPT), PHP_EOL;'
```
Insert the hashes:
```sql
INSERT INTO public.admins (login, password) VALUES ('$2y$...', '$2y$...');
```

## Google Authenticator / Aegis (TOTP)
1. Sign in to `/admin` using login and password.
2. Open Dashboard → Безопасность.
3. Click “Добавить Google Authenticator”.
4. In Google Authenticator choose to add an account using a setup key. Enter the displayed secret.
5. Enter the current 6-digit code to enable 2FA.
6. Next logins require password and the rotating 6-digit code.

TOTP is RFC 6238 compatible: SHA1, 6 digits, 30-second interval. Keep the setup secret safe. If the authenticator is lost, reset the administrator’s `2fa` JSONB value to `{"enabled":0,"secret":null}` in the database after verifying identity.

## Users table
`users`: `uuid`, `key`, `created_at`, `expires_at`. A NULL `expires_at` means the key does not expire.
