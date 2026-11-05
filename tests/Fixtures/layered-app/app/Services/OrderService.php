<?php

namespace App\Services;

use App\Models\Order;

class OrderService
{
    public function place(): Order
    {
        return new Order;
    }
}
