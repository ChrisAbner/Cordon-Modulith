<?php

namespace App\Modules\Billing\Listeners;

use App\Modules\Orders\Events\OrderPlaced;

// A listener: Billing reacts to Orders' event, so Orders does not depend on Billing for it.
final class ChargeOrder
{
    public function handle(OrderPlaced $event): void {}
}
