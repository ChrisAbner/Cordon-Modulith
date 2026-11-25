<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

final class ReportController
{
    public function index(): array
    {
        return DB::table('invoices')->join('orders', 'orders.id', '=', 'invoices.order_id')->get()->all();
    }
}
