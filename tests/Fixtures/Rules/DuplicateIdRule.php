<?php

namespace Cordon\Tests\Fixtures\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Contracts\Rule;

final class DuplicateIdRule implements Rule
{
    public function id(): string
    {
        return 'cycles';
    }

    public function check(AnalysisContext $context): iterable
    {
        return [];
    }
}
