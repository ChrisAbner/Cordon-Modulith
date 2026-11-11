<?php

namespace App\Modules\Orders\Services;

use Illuminate\Support\Facades\DB;

final class OrderAudit
{
    public function record(string $event): void
    {
        DB::table('audit_log')->insert(['event' => $event]);
    }
}
