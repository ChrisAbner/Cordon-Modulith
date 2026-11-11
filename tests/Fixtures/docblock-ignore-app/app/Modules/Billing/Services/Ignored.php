<?php

namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Models\Stock;

final class Ignored
{
    /**
     * @param  Stock  $stock
     */
    public function run($stock): void
    {
        new Stock; // @cordon-ignore internal_access, undeclared_dependency (legacy)
    }
}
