<?php

namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Contracts\PriceList;
use App\Modules\Catalog\Contracts\Stock;
use App\Modules\Catalog\Contracts\Unlisted;
use App\Modules\Catalog\Data\Price;

final class Invoicer
{
    public function __construct(PriceList $prices, Stock $stock, Unlisted $unlisted, Price $price) {}
}
