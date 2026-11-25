<?php

namespace App\Modules\Billing\Invoices\Contracts;

use App\Modules\Billing\Invoices\Models\Invoice;

interface InvoiceReader
{
    public function total(int $orderId): int;

    public function find(int $id): ?Invoice;
}
