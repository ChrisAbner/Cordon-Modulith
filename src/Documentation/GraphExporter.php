<?php

declare(strict_types=1);

namespace Cordon\Documentation;

use Cordon\Analysis\Edge;
use Cordon\Analysis\Reference;
use Cordon\Analysis\Violation;

/**
 * The module graph, for cordon:graph (Mermaid, DOT, JSON: labels count
 * references) and the diagrams of cordon:docs (labels count the classes used,
 * as its tables list them).
 *
 * @internal
 */
final class GraphExporter
{
    /**
     * Module => module it depends on => classes it uses there, sorted.
     *
     * @param  list<Edge>  $edges
     * @return array<string, array<string, array<string, true>>>
     */
    public static function dependencies(array $edges): array
    {
        $dependencies = [];

        foreach ($edges as $edge) {
            $dependencies[$edge->from->name][$edge->to->name][$edge->reference->target] = true;
        }

        ksort($dependencies);

        foreach ($dependencies as &$targets) {
            ksort($targets);

            foreach ($targets as &$classes) {
                ksort($classes);
            }
        }

        return $dependencies;
    }

    /**
     * A Mermaid flowchart, without the code fence: one node per module, one
     * arrow per dependency labelled with the number of classes used, in red
     * for the dependencies in $marked ("From|To").
     *
     * @param  list<string>  $nodes
     * @param  array<string, array<string, array<string, true>>>  $dependencies
     * @param  array<string, true>  $marked
     * @return list<string>
     */
    public static function mermaid(array $nodes, array $dependencies, array $marked, ?string $highlight = null): array
    {
        $ids = array_flip($nodes);
        $lines = ['flowchart LR'];

        foreach ($nodes as $index => $node) {
            $lines[] = sprintf('    m%d["%s"]', $index, str_replace('"', '#quot;', $node));
        }

        $red = [];
        $link = 0;

        foreach ($dependencies as $from => $targets) {
            foreach ($targets as $to => $classes) {
                if (! isset($ids[$from], $ids[$to])) {
                    continue;
                }

                $lines[] = sprintf('    m%d -->|%d| m%d', $ids[$from], count($classes), $ids[$to]);

                if (isset($marked[$from.'|'.$to])) {
                    $red[] = $link;
                }

                $link++;
            }
        }

        if ($red !== []) {
            $lines[] = '    linkStyle '.implode(',', $red).' stroke:#d33,stroke-width:2px';
        }

        if ($highlight !== null && isset($ids[$highlight])) {
            $lines[] = sprintf('    style m%d stroke-width:3px', $ids[$highlight]);
        }

        return $lines;
    }

    /**
     * The graph cordon:graph exports: modules, and per dependency the number
     * of references, their kinds and the violations on it. With $focus, only
     * that module and its neighbours.
     *
     * @param  list<string>  $modules
     * @param  list<Edge>  $edges
     * @param  list<Violation>  $violations
     * @return array{modules: list<string>, edges: list<array{from: string, to: string, references: int, kinds: list<string>, violations: int}>}
     */
    public static function model(array $modules, array $edges, array $violations, ?string $focus = null): array
    {
        $pairs = [];

        foreach ($edges as $edge) {
            $key = $edge->from->name.'|'.$edge->to->name;
            $pairs[$key] ??= ['from' => $edge->from->name, 'to' => $edge->to->name, 'references' => 0, 'kinds' => [], 'violations' => 0];
            $pairs[$key]['references']++;
            $pairs[$key]['kinds'][$edge->reference->kind] = true;
        }

        foreach ($violations as $violation) {
            $key = $violation->sourceModule.'|'.$violation->targetModule;

            if (isset($pairs[$key])) {
                $pairs[$key]['violations']++;
            }
        }

        if ($focus !== null) {
            $kept = [$focus => true];

            foreach ($pairs as $pair) {
                if ($pair['from'] === $focus || $pair['to'] === $focus) {
                    $kept[$pair['from']] = $kept[$pair['to']] = true;
                }
            }

            $modules = array_values(array_filter($modules, static fn (string $module): bool => isset($kept[$module])));
            $pairs = array_filter($pairs, static fn (array $pair): bool => isset($kept[$pair['from']], $kept[$pair['to']]));
        }

        sort($modules);
        ksort($pairs);

        return [
            'modules' => $modules,
            'edges' => array_values(array_map(static function (array $pair): array {
                $kinds = array_keys($pair['kinds']);
                sort($kinds);

                return [...$pair, 'kinds' => $kinds];
            }, $pairs)),
        ];
    }

    /**
     * The Mermaid flowchart of cordon:graph, without the code fence: like
     * DOT, each arrow is labelled with its number of references, red when it
     * has violations and dotted when it is made only of listeners.
     *
     * @param  array{modules: list<string>, edges: list<array{from: string, to: string, references: int, kinds: list<string>, violations: int}>}  $model
     */
    public static function flowchart(array $model): string
    {
        $ids = array_flip($model['modules']);
        $lines = ['flowchart LR'];

        foreach ($model['modules'] as $index => $module) {
            $lines[] = sprintf('    m%d["%s"]', $index, str_replace('"', '#quot;', $module));
        }

        $red = [];

        foreach ($model['edges'] as $link => $edge) {
            $arrow = $edge['kinds'] === [Reference::LISTENER] ? '-.->' : '-->';
            $lines[] = sprintf('    m%d %s|%d| m%d', $ids[$edge['from']], $arrow, $edge['references'], $ids[$edge['to']]);

            if ($edge['violations'] > 0) {
                $red[] = $link;
            }
        }

        if ($red !== []) {
            $lines[] = '    linkStyle '.implode(',', $red).' stroke:#d33,stroke-width:2px';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @param  array{modules: list<string>, edges: list<array{from: string, to: string, references: int, kinds: list<string>, violations: int}>}  $model
     */
    public static function dot(array $model): string
    {
        $lines = ['digraph cordon {', '    rankdir=LR;', '    node [shape=box];'];

        foreach ($model['modules'] as $module) {
            $lines[] = sprintf('    "%s";', addslashes($module));
        }

        foreach ($model['edges'] as $edge) {
            $attributes = [sprintf('label="%d"', $edge['references'])];

            if ($edge['violations'] > 0) {
                $attributes[] = 'color="#dd3333"';
            }

            if ($edge['kinds'] === [Reference::LISTENER]) {
                $attributes[] = 'style="dashed"';
            }

            $lines[] = sprintf('    "%s" -> "%s" [%s];', addslashes($edge['from']), addslashes($edge['to']), implode(', ', $attributes));
        }

        $lines[] = '}';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
