<?php

namespace App\Modules\Orders\Events;

final readonly class OrderPlaced
{
    public function __construct(public int $orderId) {}
}
