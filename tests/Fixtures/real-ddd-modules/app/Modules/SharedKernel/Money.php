<?php

namespace App\Modules\SharedKernel;

final readonly class Money
{
    public function __construct(public int $amount, public string $currency = 'EUR') {}

    public static function zero(): self
    {
        return new self(0);
    }
}
