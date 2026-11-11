<?php

namespace App\Modules\Billing;

use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Stock;

final class Invoice
{
    public function product(): Product // @cordon-ignore internal_access (the PDF shows the product name)
    {
        return new Product;
    }

    // @cordon-ignore internal_access, undeclared_dependency (prices are copied into the invoice)
    public function price(Price $price): void {}

    public function stock(Stock $stock): void {}
}
