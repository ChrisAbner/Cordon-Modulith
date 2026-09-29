<?php

namespace App\Modules\Billing\Services;

use App\Modules\Ledger\Invoices\Enums\Hidden;
use App\Modules\Ledger\Invoices\Enums\Status;
use App\Modules\Ledger\Invoices\Events\Sub\InvoicePaid;
use App\Modules\Ledger\Invoices\Models\Invoice;
use App\Modules\Ledger\Invoices\MyEnums\Kind;

final class Reporter
{
    public function run(Status $status, InvoicePaid $event, Invoice $invoice, Kind $kind, Hidden $hidden): void {}
}
