<?php

namespace App\Modules\Orders\Services;

use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Invoices\Contracts\InvoiceReader;
use App\Modules\Billing\Payments\Models\Payment;

final class OrderService
{
    public function __construct(private BillingGateway $billing, private InvoiceReader $invoices) {}

    public function place(int $orderId): Payment
    {
        $this->billing->charge($orderId);

        return new Payment;
    }
}
