<?php
require_once __DIR__ . '/_layout.php';
public_start('prices', 'Покупка');
?>
<section class="content">
    <div class="card">
        <h1>Покупка</h1>
        <p>Информация о тарифах и способах приобретения доступа появится здесь.</p>
    </div>
    <div>
        <h1 class="center">Доступные товары</h1>
        <div class="products">

            <div class="card col product">
                <div>
                    <img src="https://i.imgur.com/gPOklv3.png" alt="Product Image">
                    <div class="product-head"><h2>Product Name</h2><span class="status" data-color="lime"><i></i> Undetected</span></div>
                </div>
                <div>
                    <div class="label">Product Information</div>
                    <div>
                        <p>Information</p>
                        <p class="tooltip" data-tooltip="In no way involved in the administration or management of the site.">Interactive Information</p>
                    </div>
                </div>
                <a class="btn" href="#">Buy</a>
            </div>

            <div class="card col product">
                <div>
                    <img src="https://i.imgur.com/gPOklv3.png" alt="Product Image">
                    <div class="product-head"><h2>Product Name</h2><span class="status" data-color="lime"><i></i> Undetected</span></div>
                </div>
                <div>
                    <div class="label">Product Information</div>
                    <div>
                        <p>Information</p>
                        <p class="tooltip" data-tooltip="In no way involved in the administration or management of the site.">Interactive Information</p>
                    </div>
                </div>
                <a class="btn" href="#">Buy</a>
            </div>

        </div>
    </div>
</section>
<?php public_end(); ?>
