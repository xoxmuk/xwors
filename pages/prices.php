<?php
require_once __DIR__ . '/_layout.php';
public_start('prices', 'Покупка');
?>
<section class="content">
    <div class="card">
        <h1>Покупка</h1>
        <p>Информация о тарифах и способах приобретения доступа появится здесь.</p>
    </div>
    <div class="card">
        <h1>Доступные товары</h1>
        <div>

            <div class="col">
                <div>
                    <img src="" alt="Product Image">
                    <div class="row"><h2>Product Name</h2><span class="status" data-color="lime"><i></i> Undetected</span></div>
                </div>
                <div>
                    <div class="label">Product Information</div>
                    <div>
                        <p>Information</p>
                        <p class="tooltip" data-tooltip="In no way involved in the administration or management of the site.">Interactive Information</p>
                    </div>
                </div>
                <a href="#">Buy</a>
            </div>

            <div class="col">
                <div>
                    <img src="" alt="Product Image">
                    <div class="row"><h2>Product Name</h2><span class="status" data-color="lime"><i></i> Undetected</span></div>
                </div>
                <div>
                    <div class="label">Product Information</div>
                    <div>
                        <p>Information</p>
                        <p class="tooltip" data-tooltip="In no way involved in the administration or management of the site.">Interactive Information</p>
                    </div>
                </div>
                <a href="#">Buy</a>
            </div>

        </div>
    </div>
</section>
<?php public_end(); ?>
