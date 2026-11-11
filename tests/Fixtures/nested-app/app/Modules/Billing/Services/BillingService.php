<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Invoices\Contracts\InvoiceReader;
use App\Modules\Billing\Payments\Contracts\PaymentGateway;

final class BillingService implements BillingGateway
{
    public function __construct(private InvoiceReader $invoices, private PaymentGateway $payments) {}

    public function charge(int $orderId): void
    {
        $this->payments->pay($this->invoices->total($orderId));
    }
}
