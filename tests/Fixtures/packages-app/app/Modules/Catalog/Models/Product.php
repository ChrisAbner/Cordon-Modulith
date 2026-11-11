<?php

namespace App\Modules\Catalog\Models;

use GuzzleHttp\Client;
use Stripe\StripeClient;

final class Product
{
    public function sync(Client $http, StripeClient $stripe): void {}
}
