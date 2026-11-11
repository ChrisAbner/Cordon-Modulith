<?php

use App\Http\Controllers\OrderController;
use App\Modules\Catalog\Models\Product;

Route::get('/orders/{product}', [OrderController::class, 'show']);
Route::bind('product', fn (string $id) => Product::find($id));
