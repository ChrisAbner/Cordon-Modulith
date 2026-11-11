<?php

namespace App\Modules\Billing\Listeners;

use App\Modules\Orders\Events\OrderPlaced;
use Throwable;

// A queued listener: failed() receives the same event. It comes first on purpose.
final class ChargeOrderQueued
{
    /** @param OrderPlaced $event */
    public function failed(OrderPlaced $event, Throwable $exception): void {}

    /** @return list<OrderPlaced> */
    public function handle(OrderPlaced $event): void {}
}
