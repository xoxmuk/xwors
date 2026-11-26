# XWorsClient

PHP 8+ / PostgreSQL. Публичные страницы: `/`, `/news`, `/prices`. Вход в админку: `/admin`, панель: `/admin/dashboard/`.

## Структура
```
api/index.php        единственная serverless-функция (front controller)
lib/                 router, auth (cookie, CSRF, TOTP), actions (POST /api/*), connect (PostgreSQL)
pages/               публичные страницы и админка
styles/              CSS (отдаётся Vercel как статика через @vercel/static)
scripts/             app.js (кнопка «Копировать»)
router.php           только для локального запуска
vercel.json          vercel-php@0.7.1 + @vercel/static + маршруты + заголовки безопасности
```

## Переменные окружения
- `XWORS_DATABASE_URL` — PostgreSQL URL (`postgresql://user:pass@host:5432/db?sslmode=require`).
- `AUTH_SECRET` — минимум 32 случайных символа: `php -r 'echo bin2hex(random_bytes(32));'`

## Локальный запуск
```bash
cp .env.example .env   # заполнить значения
php -S localhost:8000 router.php
```

## Деплой на Vercel
1. Залить проект в Git-репозиторий и импортировать его в Vercel (Framework Preset: **Other**, Build/Output не задавать).
2. Project → Settings → Environment Variables: добавить `XWORS_DATABASE_URL` и `AUTH_SECRET` (Production и Preview).
3. Deploy. Либо через CLI: `npm i -g vercel && vercel --prod`.

Таблицы `admins` и `users` создаются автоматически при первом подключении к БД.

## Учётные данные администратора
`admins.login` и `admins.password` хранят bcrypt-хэши:
```bash
php -r 'echo password_hash("login", PASSWORD_BCRYPT), PHP_EOL;'
php -r 'echo password_hash("password", PASSWORD_BCRYPT), PHP_EOL;'
```
```sql
INSERT INTO public.admins (login, password) VALUES ('$2y$...', '$2y$...');
```

## Google Authenticator / Aegis (TOTP)
1. Войти в `/admin`, открыть Безопасность (`/admin/dashboard/security`).
2. «Добавить Google Authenticator», ввести секрет в приложении, подтвердить 6-значным кодом.
3. Дальше вход: логин + пароль → код на втором шаге.

Если доступ к аутентификатору потерян, сбросить `2fa` в БД: `UPDATE admins SET "2fa" = '{"enabled":0,"secret":null}'`.

## Таблица users
`uuid`, `key`, `created_at`, `expires_at` (NULL — бессрочный ключ).
