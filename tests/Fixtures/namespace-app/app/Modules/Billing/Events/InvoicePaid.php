<?php

namespace App\Modules\Billing\Events;

final class InvoicePaid
{
    public function __construct(public readonly int $invoiceId) {}
}
