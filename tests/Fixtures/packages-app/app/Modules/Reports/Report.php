<?php

namespace App\Modules\Reports;

use GuzzleHttp\Client;
use Stripe\StripeClient;

final class Report
{
    public function build(Client $http, StripeClient $stripe): void {}
}
