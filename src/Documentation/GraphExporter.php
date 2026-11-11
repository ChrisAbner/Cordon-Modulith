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
        $lines = ['flowchart LR', ...self::mermaidNodes($nodes)];
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
        $lines = ['flowchart LR', ...self::mermaidNodes($model['modules'])];
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
        $lines = ['digraph cordon {', '    rankdir=LR;', '    node [shape=box];', ...self::dotNodes($model['modules'])];

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

    /**
     * The nodes of a Mermaid flowchart. A nested module (Billing.Invoices)
     * is drawn inside a subgraph of its parent, next to the parent's own node.
     *
     * @param  list<string>  $nodes
     * @return list<string>
     */
    private static function mermaidNodes(array $nodes): array
    {
        $ids = array_flip($nodes);
        [$roots, $children] = self::hierarchy($nodes);
        $subgraph = 0;
        $lines = [];
        $draw = function (string $node, string $indent) use (&$draw, &$lines, &$subgraph, $ids, $children): void {
            $line = sprintf('m%d["%s"]', $ids[$node], str_replace('"', '#quot;', $node));

            if (! isset($children[$node])) {
                $lines[] = $indent.$line;

                return;
            }

            $lines[] = sprintf('%ssubgraph s%d["%s"]', $indent, $subgraph++, str_replace('"', '#quot;', $node));
            $lines[] = $indent.'    '.$line;

            foreach ($children[$node] as $child) {
                $draw($child, $indent.'    ');
            }

            $lines[] = $indent.'end';
        };

        foreach ($roots as $root) {
            $draw($root, '    ');
        }

        return $lines;
    }

    /**
     * The nodes of a DOT graph, a nested module inside a cluster of its parent.
     *
     * @param  list<string>  $nodes
     * @return list<string>
     */
    private static function dotNodes(array $nodes): array
    {
        [$roots, $children] = self::hierarchy($nodes);
        $lines = [];
        $draw = function (string $node, string $indent) use (&$draw, &$lines, $children): void {
            if (! isset($children[$node])) {
                $lines[] = sprintf('%s"%s";', $indent, addslashes($node));

                return;
            }

            $lines[] = sprintf('%ssubgraph "cluster_%s" {', $indent, addslashes($node));
            $lines[] = sprintf('%s    label="%s";', $indent, addslashes($node));
            $lines[] = sprintf('%s    "%s";', $indent, addslashes($node));

            foreach ($children[$node] as $child) {
                $draw($child, $indent.'    ');
            }

            $lines[] = $indent.'}';
        };

        foreach ($roots as $root) {
            $draw($root, '    ');
        }

        return $lines;
    }

    /**
     * Which of the nodes are nested in another: a name whose longest prefix
     * before a dot is itself a node is that node's child.
     *
     * @param  list<string>  $nodes
     * @return array{0: list<string>, 1: array<string, list<string>>} The top-level nodes, and the children of each parent.
     */
    private static function hierarchy(array $nodes): array
    {
        $known = array_flip($nodes);
        $roots = [];
        $children = [];

        foreach ($nodes as $node) {
            $parent = null;
            $parts = explode('.', $node);

            for ($length = count($parts) - 1; $length >= 1 && $parent === null; $length--) {
                $prefix = implode('.', array_slice($parts, 0, $length));
                $parent = isset($known[$prefix]) ? $prefix : null;
            }

            if ($parent === null) {
                $roots[] = $node;
            } else {
                $children[$parent][] = $node;
            }
        }

        return [$roots, $children];
    }
}
