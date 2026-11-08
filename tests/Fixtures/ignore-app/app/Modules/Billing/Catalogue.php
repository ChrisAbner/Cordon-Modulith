<?php

namespace App\Modules\Billing;

use App\Modules\Catalog\Contracts\ProductCatalog;

final class Catalogue
{
    // @cordon-ignore internal_access (it used to read the model)
    public function __construct(private ProductCatalog $catalog) {}
}
