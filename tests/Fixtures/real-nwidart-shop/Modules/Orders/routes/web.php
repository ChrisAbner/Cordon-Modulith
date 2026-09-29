<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrderController;

Route::get('/orders/{order}', [OrderController::class, 'show']);
