<?php

namespace App\Modules\Billing\Events;

final class PaymentFailed
{
    public function __construct(public int $orderId) {}
}
