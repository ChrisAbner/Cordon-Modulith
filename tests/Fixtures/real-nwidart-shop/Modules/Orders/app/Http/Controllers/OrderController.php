<?php

namespace Modules\Orders\Http\Controllers;

use Illuminate\Routing\Controller;
use Modules\Catalog\Contracts\ProductCatalog;
use Modules\Orders\Models\Order;

class OrderController extends Controller
{
    public function __construct(private ProductCatalog $catalog) {}

    public function show(Order $order): array
    {
        return ['product' => $this->catalog->find($order->product_id)];
    }
}
