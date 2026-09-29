<?php

namespace Modules\Notifications\Tests;

use Modules\Invoicing\Models\Invoice;

final class SendInvoiceEmailTest
{
    public function invoice(): Invoice
    {
        return new Invoice;
    }
}
