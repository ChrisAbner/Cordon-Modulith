<?php

namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Contracts\LegacyCatalog;
use App\Modules\Catalog\Support\PriceFormatter;

final class InvoiceRenderer
{
    public function render(int $cents, LegacyCatalog $legacy): string
    {
        return PriceFormatter::format($cents).' '.strtoupper('eur');
    }
}
