<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Billing\Contracts\Invoices;
use App\Modules\Orders\Events\OrderPlaced;

final class PlaceOrder
{
    public function __invoke(Invoices $invoices, int $orderId): void
    {
        event(new OrderPlaced($orderId));
    }
}
