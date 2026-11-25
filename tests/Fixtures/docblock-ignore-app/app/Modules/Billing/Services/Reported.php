<?php

namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Models\Stock;

final class Reported
{
    /**
     * @param  Stock  $stock
     */
    public function run($stock): void
    {
        new Stock;
    }
}
