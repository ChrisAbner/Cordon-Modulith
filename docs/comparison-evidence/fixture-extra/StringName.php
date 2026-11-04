<?php

namespace App\Modules\Billing\Services;

// The internal Product class appears only as a string resolved at runtime.
final class StringName
{
    public function product(): object
    {
        return app('App\Modules\Catalog\Models\Product');
    }
}
