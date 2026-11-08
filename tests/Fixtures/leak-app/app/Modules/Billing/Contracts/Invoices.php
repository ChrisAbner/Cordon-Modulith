<?php

namespace App\Modules\Billing\Contracts;

use App\Modules\Billing\Data\InvoiceData;
use App\Modules\Billing\Models\Invoice;

interface Invoices
{
    public function find(int $id): ?Invoice;

    public function latest(): InvoiceData;
}
