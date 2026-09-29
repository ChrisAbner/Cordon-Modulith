<?php

namespace App\Modules\Sales\Domain;

use App\Modules\SharedKernel\Money;

final class Order
{
    public function __construct(public readonly string $id, public readonly Money $total) {}
}
