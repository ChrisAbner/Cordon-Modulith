<?php

namespace Modules\Orders\Listeners;

use Modules\Orders\Contracts\OrderRepository;
use Modules\Payments\Events\PaymentCaptured;

class MarkOrderAsPaid
{
    public function __construct(private OrderRepository $orders) {}

    public function handle(PaymentCaptured $event): void
    {
        $this->orders->markAsPaid($event->orderId);
    }
}
