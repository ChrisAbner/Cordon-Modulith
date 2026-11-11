<?php

namespace App\Modules\Billing\Contracts;

interface Invoices
{
    public function open(int $orderId): void;
}
