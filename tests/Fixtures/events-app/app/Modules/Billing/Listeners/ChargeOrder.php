<?php

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Events\PaymentFailed;
use App\Modules\Orders\Events\OrderPlaced;

final class ChargeOrder
{
    public function handle(OrderPlaced $event): void
    {
        PaymentFailed::dispatch($event->orderId);
    }
}
