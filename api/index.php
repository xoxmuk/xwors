<?php
declare(strict_types=1);
require __DIR__ . '/../lib/router.php';

$target = route_resolve();
if ($target !== null) {
    require $target;
}
