<?php

namespace App\Modules\Shipping\Providers;

use App\Modules\Billing\Events\PaymentFailed;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Shipping\Listeners\PrepareParcel;
use Illuminate\Support\Facades\Event;

final class ShippingServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderPlaced::class, [PrepareParcel::class, 'handle']);
        Event::listen([PaymentFailed::class], fn () => null);
    }
}
