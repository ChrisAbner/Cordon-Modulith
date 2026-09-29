<?php

namespace Modules\Orders\Tests\Feature;

use Modules\Catalog\Database\Factories\ProductFactory;
use Modules\Payments\Services\Gateway;

it('creates orders', function () {
    ProductFactory::new()->create();
    app(Gateway::class);
});
