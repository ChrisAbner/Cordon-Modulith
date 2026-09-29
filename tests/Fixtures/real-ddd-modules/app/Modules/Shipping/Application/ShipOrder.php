<?php

namespace App\Modules\Shipping\Application;

use App\Modules\Sales\Domain\Order;

final class ShipOrder
{
    public function __invoke(Order $order): void {}
}
