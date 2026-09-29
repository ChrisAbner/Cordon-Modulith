<?php

namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Contracts\ProductCatalog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Shared\Money;

final class CheckoutService
{
    public function __construct(private ProductCatalog $catalog) {}

    public function total(Product $product): Money
    {
        return new Money($product->price);
    }
}
