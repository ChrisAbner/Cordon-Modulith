<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Invoices\Models\Invoice;

final class ReportBuilder
{
    public function build(Invoice $invoice): string
    {
        return (string) $invoice->number;
    }
}
