<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Jobs\SyncStock;

final class PlaceOrder
{
    public function __invoke(int $orderId): void
    {
        event(new OrderPlaced($orderId));
        SyncStock::dispatch($orderId);
    }
}
