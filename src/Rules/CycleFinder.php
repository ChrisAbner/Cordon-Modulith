<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\Violation;

/**
 * Finds the dependency cycles of a module graph: one violation per dependency
 * inside a group of modules that reach each other. Shared by the cycles rule
 * of cordon:verify and the one of PHPStan, which builds the graph from
 * collected data.
 *
 * @internal
 */
final class CycleFinder
{
    /**
     * One violation per dependency inside a group of modules that reach each
     * other, so the baseline tracks dependencies: a module joining the group
     * or a new dependency inside it is reported, and removing one dependency
     * leaves the others suppressed.
     *
     * @param  array<string, list<string>>  $graph  Module => the modules it depends on, sorted.
     * @return list<Violation>
     */
    public static function violations(array $graph): array
    {
        $violations = [];

        foreach (self::stronglyConnectedComponents($graph) as $component) {
            if (count($component) < 2) {
                continue;
            }

            sort($component);
            $members = array_flip($component);
            $dependencies = [];

            foreach ($component as $from) {
                foreach ($graph[$from] as $to) {
                    if (isset($members[$to])) {
                        $dependencies[] = [$from, $to];
                    }
                }
            }

            // A plain ring has exactly one dependency per module; anything more is several cycles.
            $tangled = count($dependencies) > count($component);

            foreach ($dependencies as [$from, $to]) {
                $cycle = [$from, ...self::shortestPath($to, $from, $graph, $members)];

                $message = sprintf(
                    'Module %s depends on %s, which closes the dependency cycle %s. ',
                    $from,
                    $to,
                    implode(' -> ', $cycle),
                );

                $message .= $tangled
                    ? sprintf('Modules [%s] are tangled in several cycles: break them one dependency at a time, for example with an event or a contract.', implode(', ', $component))
                    : 'Break it by inverting one dependency, for example with an event or a contract.';

                $violations[] = new Violation(
                    CycleRule::ID,
                    $message,
                    null,
                    null,
                    $from,
                    $to,
                    $from.' -> '.$to,
                );
            }
        }

        return $violations;
    }

    /**
     * Tarjan's algorithm.
     *
     * @param  array<string, list<string>>  $graph
     * @return list<list<string>>
     */
    private static function stronglyConnectedComponents(array $graph): array
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
     * Shortest path from $start to $end inside one component (breadth first).
     * Both are members of the component, so a path always exists.
     *
     * @param  array<string, list<string>>  $graph
     * @param  array<string, int>  $members
     * @return list<string>
     */
    private static function shortestPath(string $start, string $end, array $graph, array $members): array
    {
        $queue = [[$start]];
        $visited = [$start => true];

        while ($queue !== []) {
            $path = array_shift($queue);
            $node = $path[array_key_last($path)];

            if ($node === $end) {
                return $path;
            }

            foreach ($graph[$node] ?? [] as $next) {
                if (isset($members[$next]) && ! isset($visited[$next])) {
                    $visited[$next] = true;
                    $queue[] = [...$path, $next];
                }
            }
        }

        return [$start, $end];
    }
}
