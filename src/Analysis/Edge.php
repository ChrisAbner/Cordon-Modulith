<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Module\Module;

/**
 * A reference that crosses a module boundary.
 */
final readonly class Edge
{
    public function __construct(
        public Module $from,
        public Module $to,
        public Reference $reference,
    ) {}
}
