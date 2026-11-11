<?php

namespace App\Modules\Billing\Services;

final class Money
{
    public function __construct(public int $cents) {}
}
