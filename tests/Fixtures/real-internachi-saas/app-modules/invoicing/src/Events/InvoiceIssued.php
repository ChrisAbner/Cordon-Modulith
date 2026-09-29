<?php

namespace Modules\Invoicing\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class InvoiceIssued
{
    use Dispatchable;

    public function __construct(public readonly int $invoiceId) {}
}
