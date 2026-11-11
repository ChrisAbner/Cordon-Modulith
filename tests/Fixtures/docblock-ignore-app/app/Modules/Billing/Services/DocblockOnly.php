<?php

namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Models\Stock;

final class DocblockOnly
{
    /**
     * @param  Stock  $stock
     */
    public function run($stock): void {}
}
