<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;

class OrderController
{
    public function store(OrderService $orders): Order
    {
        return $orders->place();
    }
}
