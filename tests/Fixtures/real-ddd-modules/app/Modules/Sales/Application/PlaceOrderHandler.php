<?php

namespace App\Modules\Sales\Application;

use App\Modules\Inventory\Application\Queries\StockLevel;
use App\Modules\Sales\Application\Commands\PlaceOrder;
use App\Modules\Sales\Domain\Order;
use App\Modules\SharedKernel\Money;

final class PlaceOrderHandler
{
    public function __construct(private StockLevel $stock) {}

    public function __invoke(PlaceOrder $command): Order
    {
        return new Order(uniqid(), Money::zero());
    }
}
