<?php

declare(strict_types=1);

namespace Cordon\Contracts;

use Cordon\Analysis\Result;

interface Reporter
{
    public function render(Result $result): string;

    /**
     * Whether the output contains console formatting tags (<fg=red>...</>).
     * Machine readable formats must return false.
     */
    public function formatted(): bool;
}
