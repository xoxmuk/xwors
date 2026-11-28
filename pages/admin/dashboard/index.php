<?php
/** Главная страница админки: сводная статистика (пользователи, активные ключи, новости, товары). */
require_once __DIR__ . '/_layout.php';
dash_start('index', 'Главная');

// Считаем все показатели одним запросом; «активный» ключ — бессрочный или ещё не истёкший
try {
    $pdo = db();
    $counts = $pdo->query('SELECT (SELECT COUNT(*) FROM users) AS total, (SELECT COUNT(*) FROM users WHERE expires_at IS NULL OR expires_at > NOW()) AS active, (SELECT COUNT(*) FROM news) AS news, (SELECT COUNT(*) FROM goods) AS goods')->fetch();
    $total = (int)$counts['total']; $active = (int)$counts['active']; $newsCount = (int)$counts['news']; $goodsCount = (int)$counts['goods'];
} catch (Throwable $e) {
    error_log('Dashboard stats error: ' . $e->getMessage());
    // При ошибке БД вместо чисел будет показан прочерк
    $total = $active = $newsCount = $goodsCount = null;
}
?>
<?php /* Плитки со статистикой */ ?>
<section class="stats-grid">
    <article class="panel-card">
        <span class="label">ВСЕГО ПОЛЬЗОВАТЕЛЕЙ</span>
        <strong><?= $total === null ? '—' : number_format($total, 0, ',', ' ') ?></strong>
    </article>
    <article class="panel-card">
        <span class="label">АКТИВНЫЕ КЛЮЧИ</span>
        <strong><?= $active === null ? '—' : number_format($active, 0, ',', ' ') ?></strong>
    </article>
    <article class="panel-card">
        <span class="label">НОВОСТИ</span>
        <strong><?= $newsCount === null ? '—' : number_format($newsCount, 0, ',', ' ') ?></strong>
    </article>
    <article class="panel-card">
        <span class="label">ТОВАРЫ</span>
        <strong><?= $goodsCount === null ? '—' : number_format($goodsCount, 0, ',', ' ') ?></strong>
    </article>
</section>
<section class="panel-card">
    <h2>Панель управления</h2>
    <p>Выберите раздел в боковом меню для просмотра пользователей, публикации новостей и управления товарами.</p>
</section>
<?php dash_end(); ?>
