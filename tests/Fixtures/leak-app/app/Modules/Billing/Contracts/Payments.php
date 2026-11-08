<?php

namespace App\Modules\Billing\Contracts;

use App\Modules\Billing\Data\InvoiceData;
use App\Modules\Billing\Models\Customer;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Status;

final class Payments
{
    public const Status DEFAULT_STATUS = Status::Open;

    public Payment|InvoiceData $last;

    private Invoice $cached;

    public function __construct(public Customer $customer, private Invoice $draft) {}

    public function pay(InvoiceData $data, Invoice $invoice): void {}

    protected function audit(Invoice $invoice): void {}

    private function store(Payment $payment): void
    {
        $anonymous = new class
        {
            public function leak(Invoice $invoice): void {}
        };
    }
}
