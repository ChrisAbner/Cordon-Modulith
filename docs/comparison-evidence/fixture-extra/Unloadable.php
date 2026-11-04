<?php

namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Models\Product;
use Vendor\Missing\BaseService;

// Extends a class that is not installed, so it cannot be autoloaded.
final class Unloadable extends BaseService
{
    public function product(): Product
    {
        return new Product;
    }
}
