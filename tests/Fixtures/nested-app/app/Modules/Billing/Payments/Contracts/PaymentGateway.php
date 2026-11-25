<?php

namespace App\Modules\Billing\Payments\Contracts;

interface PaymentGateway
{
    public function pay(int $amount): void;
}
