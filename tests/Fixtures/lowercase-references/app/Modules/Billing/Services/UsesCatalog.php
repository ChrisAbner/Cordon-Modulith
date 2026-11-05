<?php

namespace App\Modules\Billing\Services;

use app\modules\catalog\Models\Product;
use app\modules\catalog\contracts\Api;

final class UsesCatalog
{
    public function handle(Product $product, Api $api): void {}
}
