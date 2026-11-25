<?php

namespace App\Modules\Billing\Invoices\Services;

use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Invoices\Models\Invoice;
use App\Modules\Billing\Payments\Contracts\PaymentGateway;
use App\Modules\Billing\Payments\Models\Payment;

final class InvoiceService
{
    public function __construct(private PaymentGateway $payments, private BillingGateway $billing) {}

    public function settle(Payment $payment): void
    {
        $this->billing->charge($payment->orderId);
    }

    public function latest(): Invoice
    {
        return new Invoice;
    }
}
