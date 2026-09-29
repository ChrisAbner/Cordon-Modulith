<?php

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Application\Queries\StockLevel;
use App\Modules\Sales\Domain\Events\OrderPlaced;

final class EloquentStockLevel implements StockLevel
{
    public function for(string $sku): int
    {
        return 0;
    }

    public function onOrderPlaced(OrderPlaced $event): void {}
}
