<?php

namespace App\Modules\Billing\Invoices\Services;

use App\Modules\Billing\Services\Money;

final class InvoiceTotals
{
    public function total(int $cents): Money
    {
        return new Money($cents);
    }
}
