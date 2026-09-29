<?php

namespace App\Modules\Catalog\Listeners;

use App\Modules\Billing\Events\InvoicePaid;

final class UpdateStockOnInvoicePaid
{
    public function handle(InvoicePaid $event): void
    {
        // Namespaced function calls are not class dependencies.
        \App\Modules\Billing\log_payment($event->invoiceId);
    }
}
