# XWorsClient

PHP 8+ / PostgreSQL.

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
2. «Добавить Google Authenticator» → отсканировать QR-код или ввести секретный ключ вручную, подтвердить 6-значным кодом.
3. Дальше вход: логин + пароль → код на втором шаге.

QR-код генерируется локально в браузере (`scripts/qrcode.min.js`), секрет никуда не отправляется.

Если доступ к аутентификатору потерян, сбросить `2fa` в БД: `UPDATE admins SET "2fa" = '{"enabled":0,"secret":null}'`.

## Таблица users
`uuid`, `key`, `created_at`, `expires_at` (NULL — бессрочный ключ).

В `/admin/dashboard/users` создаётся пользователь: случайный UUID v4 и случайный ключ из 32 символов (A-Z, a-z, 0-9); при совпадении с существующими генерация повторяется.

## Хранение картинок
Файлы пишутся на диск сервера в `assets/news` и `assets/goods` (имя — SHA-256 содержимого, одинаковые файлы не дублируются; неиспользуемые удаляются при сохранении/удалении записи). Это работает на VPS/локально. На Vercel файловая система только для чтения — там для загрузок нужно внешнее хранилище (Vercel Blob, S3 и т.п.).

## Таблица news
`id`, `version`, `data` (JSONB: `{ "blocks": [{ "title", "description" }], "images": [...] }`), `hidden`, `create_at`, `updated_at`.

Новости создаются и редактируются в `/admin/dashboard/news`. Картинки загружаются файлом (хэшируются SHA-256 и сохраняются в `assets/news/<hash>.<ext>`) или добавляются ссылками. Видимость «Опубликован/Скрыт» меняется нажатием на неё в списке. Скрытые новости не выводятся на `/news`.

## Таблица goods
`id`, `status`, `image`, `name`, `description`, `price`, `purchase`, `hidden`, `create_at`, `updated_at`.

Товары создаются и редактируются в `/admin/dashboard/goods`. Аватарка загружается в `assets/goods/<hash>.<ext>` (перетаскиванием на текущую аватарку, замена автоматическая; «Удалить» убирает её). Статус и видимость («Опубликован/Скрыт») меняются нажатием в списке (логика видимости такая же, как у новостей), скрытые товары не выводятся на `/prices`.

Кнопка «Купить» (`purchase` — JSONB `{"type": "...", "url": "..."}`), `type`: `none` — неактивна, `telegram` (`@username` или `t.me/…`), `link` (внешняя ссылка) или `page` (страница сайта из `goods_buy_pages()`). Колонка `purchase` добавляется автоматически.

Ошибки загрузки картинки показываются под зоной загрузки: пустой/повреждённый файл, неверный формат, превышение размера или разрешения, лимиты сервера (`upload_max_filesize`/`post_max_size`), нет места или прав на запись, хранилище только для чтения (Vercel), обрыв связи, таймаут, истёкшая сессия.
