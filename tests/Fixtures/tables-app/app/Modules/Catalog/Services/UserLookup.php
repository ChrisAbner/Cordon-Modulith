<?php

namespace App\Modules\Catalog\Services;

use Illuminate\Support\Facades\DB;

final class UserLookup
{
    public function find(int $id): ?object
    {
        return DB::table('users')->where('id', $id)->first();
    }
}
