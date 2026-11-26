<?php
require_once __DIR__ . '/_layout.php';
public_start('news', 'Новости');
?>
<section class="content">
    <div class="card">
        <div class="row center">
            <span class="label">v1.0.0</span>
            <h1>Релиз</h1>
        </div>
        <div>
            <h2>Какая то глава</h2>
            <p>Какая то информация.</p>
            <ul>
                <li><p>1</p></li>
                <li><p>2</p></li>
                <li><p>3</p></li>
            </ul>
            <p>Какая то очень большая мега большая информация которая очень интересна.</p>
            <p>Какая то информация</p>
            <img src="https://i.pinimg.com/236x/78/4a/88/784a8865e4d898b653cb2fb6f52a2b37.jpg" alt="cat">
            <span>Чего, какой ещё кот?</span>
        </div>
    </div>
</section>
<?php public_end(); ?>
