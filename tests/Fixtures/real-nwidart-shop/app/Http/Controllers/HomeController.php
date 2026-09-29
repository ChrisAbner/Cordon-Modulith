<?php

namespace App\Http\Controllers;

use Modules\Catalog\Models\Product;

class HomeController
{
    public function __invoke(): array
    {
        return Product::all()->all();
    }
}
