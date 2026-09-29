<?php

namespace Modules\Orders;

use Modules\Billing\Contracts\ChargesCustomers;

final class PlaceOrder
{
    public function __construct(private ChargesCustomers $billing) {}
}
