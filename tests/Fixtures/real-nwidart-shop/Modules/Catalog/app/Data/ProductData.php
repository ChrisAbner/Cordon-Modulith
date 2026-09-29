<?php

namespace Modules\Catalog\Data;

final readonly class ProductData
{
    public function __construct(public int $id, public string $name, public int $priceInCents) {}
}
