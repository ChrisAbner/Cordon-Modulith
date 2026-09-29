<?php

namespace App\Modules\Shipping\Listeners;

use App\Modules\Orders\Models\Order;
use App\Modules\Shipping\Domain\ParcelLost;
use Illuminate\Support\Facades\Event;

final class PrepareParcel
{
    public function prepare(Order $order): void
    {
        Event::dispatch(new ParcelLost);
    }
}
