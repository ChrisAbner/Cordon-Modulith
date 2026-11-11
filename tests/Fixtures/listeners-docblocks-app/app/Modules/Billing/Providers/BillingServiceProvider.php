<?php

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Listeners\ChargeOrder;
use App\Modules\Orders\Events\OrderPlaced;
use Illuminate\Support\Facades\Event;

final class BillingServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderPlaced::class, ChargeOrder::class);
    }
}
