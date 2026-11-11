<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Contracts\Rule;

/**
 * Modules must not depend on each other in a cycle (A -> B -> A).
 *
 * @internal
 */
final class CycleRule implements Rule
{
    public const ID = 'cycles';

    /**
     * @param  bool  $countListeners  Whether listening to another module's event can close a cycle.
     */
    public function __construct(private readonly bool $countListeners = false) {}

    public function id(): string
    {
        return self::ID;
    }

    /**
     * One violation per dependency inside a group of modules that reach each
     * other, so the baseline tracks dependencies: a module joining the group
     * or a new dependency inside it is reported, and removing one dependency
     * leaves the others suppressed.
     */
    public function check(AnalysisContext $context): iterable
    {
        return CycleFinder::violations($this->graph($context));
    }

    /**
     * @return array<string, list<string>>
     */
    private function graph(AnalysisContext $context): array
    {
        $targets = [];

        foreach ($context->modules->names() as $name) {
            $targets[$name] = [];
        }

        foreach ($context->edges as $edge) {
            // Two modules that talk through events are decoupled: the listener
            // depends on the publisher, but the publisher does not know it.
            if ($edge->isListener() && ! $this->countListeners) {
                continue;
            }

            $targets[$edge->from->name][$edge->to->name] = true;
        }

        $graph = [];

        foreach ($targets as $name => $neighbours) {
            $neighbours = array_map('strval', array_keys($neighbours));
            sort($neighbours);
            $graph[(string) $name] = $neighbours;
        }

        return $graph;
    }
}
