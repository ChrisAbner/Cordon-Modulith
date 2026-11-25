<?php

declare(strict_types=1);

namespace Cordon\Analysis;

/**
 * Who depends on whom, as cordon:impact and cordon:affected see it: an edge
 * from a module to what it uses, filed under the module the consumer sees
 * (from outside Billing, a class of Billing.Invoices is a class of Billing).
 * A nested module using its parent is not a dependency.
 *
 * @internal
 */
final class DependentGraph
{
    /** @var array<string, array<string, true>> module => the modules that depend on it */
    private array $dependents = [];

    public function __construct(AnalysisContext $context)
    {
        foreach ($context->edges as $edge) {
            if (! $edge->from->isNestedIn($edge->to)) {
                $this->dependents[$context->modules->visible($edge->to, $edge->from)->name][$edge->from->name] = true;
            }
        }
    }

    /**
     * The modules that use $module directly, by name.
     *
     * @return list<string>
     */
    public function direct(string $module): array
    {
        $names = array_map('strval', array_keys($this->dependents[$module] ?? []));
        sort($names);

        return $names;
    }

    /**
     * The modules that depend on $module, directly or through others, nearest
     * first, each with the module it was reached through (null when it is direct).
     *
     * @return list<array{module: string, distance: int, through: string|null}>
     */
    public function reach(string $module): array
    {
        $found = [];
        $queue = [[$module, 0]];
        $visited = [$module => true];

        while ($queue !== []) {
            [$name, $distance] = array_shift($queue);

            foreach ($this->direct($name) as $dependent) {
                if (! isset($visited[$dependent])) {
                    $visited[$dependent] = true;
                    $found[] = ['module' => $dependent, 'distance' => $distance + 1, 'through' => $distance === 0 ? null : $name];
                    $queue[] = [$dependent, $distance + 1];
                }
            }
        }

        return $found;
    }
}
