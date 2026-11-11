<?php

namespace App\Modules\Orders\Events;

final class OrderPlaced
{
    public function __construct(public readonly int $orderId) {}
}
