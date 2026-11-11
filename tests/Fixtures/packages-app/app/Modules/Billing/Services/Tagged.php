<?php

namespace App\Modules\Billing\Services;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Tagged implements \Stringable
{
    public function __toString(): string
    {
        return 'tagged';
    }
}
