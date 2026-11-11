<?php

namespace App\Http\Controllers;

use App\Modules\Catalog\Contracts\ProductCatalog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Http\OrderPage;

final class OrderController
{
    public function __construct(private ProductCatalog $catalog) {}

    public function show(Product $product): OrderPage
    {
        return new OrderPage;
    }
}
