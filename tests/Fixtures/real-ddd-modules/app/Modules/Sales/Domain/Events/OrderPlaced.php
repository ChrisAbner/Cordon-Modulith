<?php

namespace App\Modules\Sales\Domain\Events;

final readonly class OrderPlaced
{
    public function __construct(public string $orderId) {}
}
