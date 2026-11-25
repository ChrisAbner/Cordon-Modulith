<?php

namespace App\Modules\Orders\Contracts;

final class OrderPlaced
{
    public function __construct(public int $orderId) {}
}
