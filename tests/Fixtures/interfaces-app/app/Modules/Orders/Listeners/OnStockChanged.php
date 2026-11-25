<?php

namespace App\Modules\Orders\Listeners;

use App\Modules\Catalog\Events\StockChanged;

final class OnStockChanged
{
    public function handle(StockChanged $event): void {}
}
