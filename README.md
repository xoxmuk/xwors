# XWorsClient

PHP 8+ / PostgreSQL.

## Переменные окружения
- `XWORS_DATABASE_URL` — PostgreSQL URL (`postgresql://user:pass@host:5432/db?sslmode=require`).
- `PUBLIC_BLOB_READ_WRITE_TOKEN` — Read/Write Token Vercel Blob; `PUBLIC_BLOB_STORE_ID` — ID хранилища. Оба читаются только PHP на сервере. `PUBLIC_BLOB_WEBHOOK_PUBLIC_KEY` нужен только при настройке проверки подписей webhook и не требуется для обычной загрузки.
- `AUTH_SECRET` — минимум 32 случайных символа: `php -r 'echo bin2hex(random_bytes(32));'`

## Tooltip в HTML новостей

Для подсказки используйте `tooltip-text`, а сторону задавайте через `tooltip-position`:

```html
<span tooltip-text="Текст подсказки" tooltip-position="right">Наведи на меня</span>
```

Допустимые значения `tooltip-position`: `left-up`, `up` (по умолчанию), `right-up`, `left`, `right`, `left-bottom`, `bottom`, `right-bottom`.

## Развёртывание на VPS

Рекомендуется указывать корнем сайта каталог `public/` (если вы создаёте отдельную публичную сборку) либо настроить веб-сервер так, чтобы PHP-файлы проекта, `.env`, `lib/`, `pages/` и `README.md` не обслуживались напрямую. Для текущей структуры используйте `router.php` как front controller и запретите прямой доступ к скрытым/служебным файлам и каталогам `lib`, `pages` и `api` (кроме единой точки входа `api/index.php`). Никогда не размещайте `.env` в публичном docroot.

Пример `.env.example`:

```dotenv
XWORS_DATABASE_URL=postgresql://user:password@localhost:5432/database?sslmode=require
AUTH_SECRET=replace-with-at-least-32-random-characters
```

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
На VPS/локально файлы пишутся в `public/assets/news` и `public/assets/goods`. На Vercel при наличии `PUBLIC_BLOB_READ_WRITE_TOKEN` (либо стандартного `BLOB_READ_WRITE_TOKEN`) приложение автоматически сохраняет картинки в Vercel Blob (путь `xwors/news` или `xwors/goods`), а в базе хранит постоянный публичный URL. Добавьте `PUBLIC_BLOB_READ_WRITE_TOKEN` и `PUBLIC_BLOB_STORE_ID` в Project → Settings → Environment Variables после подключения Public Blob Store. `PUBLIC_BLOB_WEBHOOK_PUBLIC_KEY` оставьте заданным только если используете Blob Webhooks. Если токен не задан, загрузка на Vercel вернёт понятную ошибку; вместо файла можно использовать HTTPS URL.

## Таблица news
`id`, `version`, `data` (JSONB: `{ "blocks": [{ "title", "description" }], "images": [...] }`), `hidden`, `create_at`, `updated_at`.

Новости создаются и редактируются в `/admin/dashboard/news`. Картинки загружаются файлом (на VPS — в `public/assets/news/<hash>.<ext>`, на Vercel — в Blob) или добавляются ссылками. Видимость «Опубликован/Скрыт» меняется нажатием на неё в списке. Скрытые новости не выводятся на `/news`.

## Таблица goods
`id`, `status`, `image`, `name`, `description`, `price`, `purchase`, `hidden`, `create_at`, `updated_at`.

Товары создаются и редактируются в `/admin/dashboard/goods`. Иконка товара загружается в `public/assets/goods/<hash>.<ext>` (на Vercel — в Blob), если иконка не задана, используется встроенная Default-иконка из `public/assets/goods`; альтернативно можно задать HTTPS URL через кнопку «URL». Статус и видимость («Опубликован/Скрыт») меняются нажатием в списке (логика видимости такая же, как у новостей), скрытые товары не выводятся на `/prices`.

Кнопка «Купить» (`purchase` — JSONB `{"type": "...", "url": "..."}`), `type`: `none` — неактивна, `telegram` (`@username` или `t.me/…`), `link` (внешняя ссылка) или `page` (страница сайта из `goods_buy_pages()`). Колонка `purchase` добавляется автоматически.

Ошибки загрузки картинки показываются под зоной загрузки: пустой/повреждённый файл, неверный формат, превышение размера или разрешения, лимиты сервера (`upload_max_filesize`/`post_max_size`), нет места или прав на запись, хранилище только для чтения (Vercel), обрыв связи, таймаут, истёкшая сессия.
