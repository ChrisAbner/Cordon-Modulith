<?php

namespace Cordon\Tests\Fixtures\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Contracts\Rule;

/**
 * What a container decorator returns for another rule: a different class with the same id.
 */
final class DecoratedRule implements Rule
{
    public function __construct(private readonly Rule $inner) {}

    public function id(): string
    {
        return $this->inner->id();
    }

    public function check(AnalysisContext $context): iterable
    {
        return $this->inner->check($context);
    }
}
