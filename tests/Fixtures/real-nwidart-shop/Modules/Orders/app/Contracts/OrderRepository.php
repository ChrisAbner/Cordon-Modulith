<?php

namespace Modules\Orders\Contracts;

interface OrderRepository
{
    public function markAsPaid(int $orderId): void;
}
