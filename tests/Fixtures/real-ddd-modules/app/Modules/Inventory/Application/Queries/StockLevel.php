<?php

namespace App\Modules\Inventory\Application\Queries;

interface StockLevel
{
    public function for(string $sku): int;
}
