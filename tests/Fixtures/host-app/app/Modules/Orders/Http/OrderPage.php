<?php

namespace App\Modules\Orders\Http;

use App\Modules\Catalog\Models\Product;

final class OrderPage
{
    public function show(Product $product): void {}
}
