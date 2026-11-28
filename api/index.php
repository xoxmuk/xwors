<?php
/** Единая точка входа (front controller): через неё проходят все запросы на Vercel и на VPS. */
declare(strict_types=1);
require __DIR__ . '/../lib/router.php';

// Определяем страницу по URL; null означает, что ответ уже сформирован (API-действие или 404)
$target = route_resolve();
if ($target !== null) {
    require $target;
}
