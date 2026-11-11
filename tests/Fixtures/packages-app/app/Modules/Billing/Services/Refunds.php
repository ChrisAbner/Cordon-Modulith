<?php

namespace App\Modules\Billing\Services;

use Stripe\StripeClient;

final class Refunds
{
    public function refund(): void
    {
        $first = new StripeClient('a');
        $second = new StripeClient('b');
    }
}
