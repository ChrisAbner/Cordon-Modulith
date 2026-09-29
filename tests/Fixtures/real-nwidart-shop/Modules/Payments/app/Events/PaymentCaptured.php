<?php

namespace Modules\Payments\Events;

final readonly class PaymentCaptured
{
    public function __construct(public int $orderId) {}
}
