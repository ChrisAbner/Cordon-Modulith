<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Module\Module;

/**
 * A reference that crosses a module boundary.
 *
 * @api
 */
final readonly class Edge
{
    public function __construct(
        public Module $from,
        public Module $to,
        public Reference $reference,
    ) {}

    /**
     * The source module listens to an event of the target module, rather than using it.
     */
    public function isListener(): bool
    {
        return $this->reference->kind === Reference::LISTENER;
    }
}
