<?php

namespace Edge\Source;

use Edge\Target\OnlyImported;
use Edge\Target\OnlyInDocblock;

use function Edge\Target\helper;
use function Edge\Target\otherHelper;

use const Edge\Target\SOME_CONSTANT;

/**
 * Nothing here may be reported as a class dependency.
 *
 * @see OnlyInDocblock
 */
class NotReferences extends \stdClass
{
    /** @var OnlyInDocblock */
    private mixed $documented = null;

    public function run(int|string|null $value, callable $callback, iterable $items): static
    {
        helper();
        \Edge\Target\qualifiedHelper();
        $callable = otherHelper(...);
        $constant = SOME_CONSTANT;
        $qualified = \Edge\Target\QUALIFIED_CONSTANT;
        $name = 'Edge\\Target\\StringClassName';
        $self = new self;
        $static = new static;

        return $this;
    }

    public function parent(): void
    {
        parent::__construct();
    }
}
