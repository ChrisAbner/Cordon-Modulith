<?php

namespace App\Modules\Billing\Tests;

use App\Modules\Catalog\Models\Product;

// Excluded by default ("tests" directory): must never produce a violation.
final class CheckoutServiceFixture
{
    public function product(): Product
    {
        return new Product;
    }
}
