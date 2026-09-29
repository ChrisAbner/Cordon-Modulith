<?php

namespace Modules\Catalog\Contracts;

use Modules\Catalog\Data\ProductData;

interface ProductCatalog
{
    public function find(int $id): ?ProductData;
}
