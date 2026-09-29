<?php

namespace App\Modules\Sales\Application\Commands;

final readonly class PlaceOrder
{
    public function __construct(public string $customerId, public array $lines) {}
}
