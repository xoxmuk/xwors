<?php
require_once __DIR__ . '/_layout.php';
public_start('news', 'Новости');
?>
<section class="news">
    <div class="news-card">
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
            <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQkjDmP1tbdaowQXiPqGHgaNqMDiFDSvH9eGcEb1_jndEKZPzF_WGP9Ij2C&s=10" alt="cat">
            <span>Чего, какой ещё кот?</span>
        </div>
    </div>
</section>
<?php public_end(); ?>
