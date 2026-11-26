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
            <img src="https://developer.mozilla.org/shared-assets/images/examples/grapefruit-slice.jpg" alt="cat">
            <span>Чего, какой ещё кот?</span>
        </div>
    </div>
</section>
<?php public_end(); ?>
