<?php

namespace App\Modules\Billing\Providers;

use App\Modules\Orders\Events\OrderPlaced;
use Illuminate\Support\Facades\Event;

final class ClosureListeners
{
    public function boot(): void
    {
        /** @var OrderPlaced $last */
        Event::listen(function (OrderPlaced $event): void {});
        Event::listen(fn (OrderPlaced $event) => null);
    }
}
