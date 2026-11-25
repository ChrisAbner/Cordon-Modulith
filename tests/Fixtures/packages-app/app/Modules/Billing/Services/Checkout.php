<?php

namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Contracts\Priced;
use App\Support\Money;
use DateTimeImmutable;
use GuzzleHttp\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Stripe\StripeClient;

final class Checkout
{
    public function __construct(private StripeClient $stripe, private Client $http, private Money $money) {}

    public function pay(Priced $item, DateTimeImmutable $at, Model $model): string
    {
        return Str::slug($item::class).(new \PDO('sqlite::memory:'))->getAttribute(0);
    }
}
