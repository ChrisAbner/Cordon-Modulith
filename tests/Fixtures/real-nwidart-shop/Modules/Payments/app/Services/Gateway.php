<?php

namespace Modules\Payments\Services;

use Modules\Orders\Contracts\OrderRepository;
use Modules\Payments\Events\PaymentCaptured;

class Gateway
{
    public function __construct(private OrderRepository $orders) {}

    public function capture(int $orderId): void
    {
        event(new PaymentCaptured($orderId));
    }
}
