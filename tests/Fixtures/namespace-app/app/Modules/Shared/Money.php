<?php

namespace App\Modules\Shared;

final class Money
{
    public function __construct(public readonly int $cents) {}
}
