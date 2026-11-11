<?php

namespace App\Modules\Billing\Payments\Services;

use App\Modules\Billing\Payments\Contracts\PaymentGateway;
use App\Modules\Billing\Payments\Models\Payment;

final class PaymentService implements PaymentGateway
{
    public function pay(int $amount): void
    {
        new Payment;
    }
}
