<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;

/**
 * Modules must not depend on each other in a cycle (A -> B -> A).
 */
final class CycleRule implements Rule
{
    public const ID = 'cycles';

    public function id(): string
    {
        return self::ID;
    }

    public function check(AnalysisContext $context): iterable
    {
        $graph = $this->graph($context);

        foreach ($this->stronglyConnectedComponents($graph) as $component) {
            if (count($component) < 2) {
                continue;
            }

            sort($component);
            $path = implode(' -> ', $this->shortestCycle($component[0], $graph, array_flip($component)));

            yield new Violation(
                self::ID,
                sprintf(
                    'Modules [%s] form a dependency cycle: %s. Break it by inverting one dependency, for example with an event or a contract.',
                    implode(', ', $component),
                    $path,
                ),
                null,
                null,
                $component[0],
                null,
                $path,
            );
        }
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

    /**
     * Tarjan's algorithm.
     *
     * @param  array<string, list<string>>  $graph
     * @return list<list<string>>
     */
    private function stronglyConnectedComponents(array $graph): array
    {
        $index = 0;
        $stack = [];
        $onStack = [];
        $indices = [];
        $lowLinks = [];
        $components = [];

        $connect = function (string $node) use (&$connect, &$index, &$stack, &$onStack, &$indices, &$lowLinks, &$components, $graph): void {
            $indices[$node] = $index;
            $lowLinks[$node] = $index;
            $index++;
            $stack[] = $node;
            $onStack[$node] = true;

            foreach ($graph[$node] ?? [] as $next) {
                if (! isset($indices[$next])) {
                    $connect($next);
                    $lowLinks[$node] = min($lowLinks[$node], $lowLinks[$next]);
                } elseif (isset($onStack[$next])) {
                    $lowLinks[$node] = min($lowLinks[$node], $indices[$next]);
                }
            }

            if ($lowLinks[$node] === $indices[$node]) {
                $component = [];

                do {
                    $member = (string) array_pop($stack);
                    unset($onStack[$member]);
                    $component[] = $member;
                } while ($member !== $node);

                $components[] = $component;
            }
        };

        foreach (array_keys($graph) as $node) {
            if (! isset($indices[(string) $node])) {
                $connect((string) $node);
            }
        }

        return $components;
    }

    /**
     * Shortest path from $start back to itself inside one component (breadth first).
     *
     * @param  array<string, list<string>>  $graph
     * @param  array<string, int>  $members
     * @return list<string>
     */
    private function shortestCycle(string $start, array $graph, array $members): array
    {
        $queue = [[$start]];
        $visited = [$start => true];

        while ($queue !== []) {
            $path = array_shift($queue);
            $node = $path[array_key_last($path)];

            foreach ($graph[$node] ?? [] as $next) {
                if (! isset($members[$next])) {
                    continue;
                }

                if ($next === $start) {
                    return [...$path, $start];
                }

                if (! isset($visited[$next])) {
                    $visited[$next] = true;
                    $queue[] = [...$path, $next];
                }
            }
        }

        return [...array_map('strval', array_keys($members)), $start];
    }
}
