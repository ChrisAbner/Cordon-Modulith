<?php

namespace App\Modules\Billing\Services;

use Illuminate\Support\Facades\DB;

final class InvoiceReport
{
    public function build(string $table): array
    {
        $names = collect(['a', 'b']);
        $names->join('orders');

        DB::table($table)->get();

        return DB::table('invoices as i')
            ->join('orders', 'orders.id', '=', 'i.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->get()
            ->all();
    }
}
