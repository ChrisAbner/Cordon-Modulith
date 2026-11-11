<?php

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Listeners\ChargeOrder;
use App\Modules\Orders\Events\OrderPlaced;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

// What nwidart generates: listeners registered in $listen.
final class EventServiceProvider extends ServiceProvider
{
    /** @var array<class-string<OrderPlaced>, list<class-string<ChargeOrder>>> */
    protected $listen = [
        OrderPlaced::class => [ChargeOrder::class],
    ];
}
