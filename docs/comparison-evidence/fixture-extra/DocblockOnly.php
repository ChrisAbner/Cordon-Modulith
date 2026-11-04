<?php

namespace App\Modules\Billing\Services;

// The internal Product class appears only in a docblock.
final class DocblockOnly
{
    /** @param \App\Modules\Catalog\Models\Product $product */
    public function price($product): int
    {
        return $product->price;
    }
}
