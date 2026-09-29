<?php

namespace App\Modules\Catalog\Support;

use Cordon\Attributes\PublicApi;

// Outside the public namespaces, but explicitly public.
#[PublicApi]
final class PriceFormatter
{
    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
