<?php

namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Contracts\Inventory;
use App\Modules\Catalog\Contracts\PriceList;
use App\Modules\Catalog\Models\Stock;
use App\Modules\Orders\Data\OrderData;

/**
 * Billing uses Catalog only in docblocks, and Orders in code.
 */
final class Invoicer
{
    /**
     * @param  Stock  $stock
     * @param  PriceList|null  $prices
     * @return list<Inventory>
     */
    public function invoice(OrderData $order, $stock, $prices = null): array
    {
        return [];
    }
}
