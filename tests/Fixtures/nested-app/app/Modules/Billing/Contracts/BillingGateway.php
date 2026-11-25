<?php

namespace App\Modules\Billing\Contracts;

interface BillingGateway
{
    public function charge(int $orderId): void;
}
