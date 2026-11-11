<?php

declare(strict_types=1);

namespace Cordon\Documentation;

use Cordon\Analysis\Edge;
use Cordon\Analysis\Reference;
use Cordon\Analysis\Snapshot;
use Cordon\Analysis\Violation;
use Cordon\Module\ModuleMap;

/**
 * The module graph, for cordon:graph (Mermaid, DOT, JSON, C4: labels count
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
     * The arrows are the raw edges: Orders points at Billing.Invoices when it
     * uses a class of it, while the rules see Billing there. Only the
     * interfaces of an arrow are looked up through the module the source sees
     * ($map, to resolve it: without it the target itself).
     *
     * @param  list<string>  $modules
     * @param  list<Edge>  $edges
     * @param  list<Violation>  $violations
     * @return array{modules: list<string>, edges: list<array{from: string, to: string, references: int, kinds: list<string>, violations: int, interfaces?: list<string>}>}
     */
    public static function model(array $modules, array $edges, array $violations, ?string $focus = null, ?ModuleMap $map = null): array
    {
        $pairs = [];

        foreach ($edges as $edge) {
            $key = $edge->from->name.'|'.$edge->to->name;
            $pairs[$key] ??= ['from' => $edge->from->name, 'to' => $edge->to->name, 'references' => 0, 'kinds' => [], 'violations' => 0, 'interfaces' => self::declaredInterfaces($edge, $map)];
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

                $interfaces = $pair['interfaces'];
                unset($pair['interfaces']);

                // Only a dependency limited to named interfaces says which: the others are unchanged.
                return [...$pair, 'kinds' => $kinds, ...($interfaces === [] ? [] : ['interfaces' => $interfaces])];
            }, $pairs)),
        ];
    }

    /**
     * The Mermaid flowchart of cordon:graph, without the code fence: like
     * DOT, each arrow is labelled with its number of references, red when it
     * has violations and dotted when it is made only of listeners.
     *
     * @param  array{modules: list<string>, edges: list<array{from: string, to: string, references: int, kinds: list<string>, violations: int, interfaces?: list<string>}>}  $model
     */
    public static function flowchart(array $model): string
    {
        $ids = array_flip($model['modules']);
        $lines = ['flowchart LR', ...self::mermaidNodes($model['modules'])];
        $red = [];

        foreach ($model['edges'] as $link => $edge) {
            $arrow = $edge['kinds'] === [Reference::LISTENER] ? '-.->' : '-->';
            $label = self::label($edge);
            $lines[] = sprintf('    m%d %s|%s| m%d', $ids[$edge['from']], $arrow, isset($edge['interfaces']) ? '"'.str_replace('"', '#quot;', $label).'"' : $label, $ids[$edge['to']]);

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
     * @param  array{modules: list<string>, edges: list<array{from: string, to: string, references: int, kinds: list<string>, violations: int, interfaces?: list<string>}>}  $model
     */
    public static function dot(array $model): string
    {
        $lines = ['digraph cordon {', '    rankdir=LR;', '    node [shape=box];', ...self::dotNodes($model['modules'])];

        foreach ($model['edges'] as $edge) {
            $attributes = [sprintf('label="%s"', addslashes(self::label($edge)))];

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
     * The C4-PlantUML diagram of cordon:graph --format=c4 and of cordon:docs
     * --diagrams=c4: one container boundary for the application, a component
     * per module with the description of its metadata, and a relationship per
     * dependency like the arrows of the other formats (number of references,
     * named interfaces), in red with violations. A nested module is drawn
     * inside a boundary of its parent, next to the parent's own component.
     * With $focus, only that module and its neighbours.
     *
     * @param  list<Violation>  $violations
     */
    public static function c4(Snapshot $snapshot, array $violations, ?string $focus = null): string
    {
        $modules = $snapshot->context->modules;
        $model = self::model($modules->names(), $snapshot->context->edges, $violations, $focus);
        $ids = array_flip($model['modules']);
        [$roots, $children] = self::hierarchy($model['modules']);
        $boundary = 0;
        $lines = ['@startuml', '!include <C4/C4_Component>', 'LAYOUT_LEFT_RIGHT()'];

        // A tag, not UpdateRelStyle: PlantUML ignores a style updated after its relationship, and one updated before it styles every later one.
        if (array_filter($model['edges'], static fn (array $edge): bool => $edge['violations'] > 0) !== []) {
            $lines[] = 'AddRelTag("violation", $textColor="#d33", $lineColor="#d33")';
        }

        $lines[] = 'Container_Boundary(app, "Application") {';
        $component = static function (string $name) use ($modules, $ids): string {
            $description = $modules->get($name)?->metadata['description'] ?? null;
            $description = is_string($description) ? trim($description) : '';

            return sprintf('Component(m%d, %s, "Module"%s)', $ids[$name], self::plantuml($name), $description === '' ? '' : ', '.self::plantuml($description));
        };
        $draw = function (string $node, string $indent) use (&$draw, &$lines, &$boundary, $children, $component): void {
            if (! isset($children[$node])) {
                $lines[] = $indent.$component($node);

                return;
            }

            $lines[] = sprintf('%sBoundary(b%d, %s) {', $indent, $boundary++, self::plantuml($node));
            $lines[] = $indent.'    '.$component($node);

            foreach ($children[$node] as $child) {
                $draw($child, $indent.'    ');
            }

            $lines[] = $indent.'}';
        };

        foreach ($roots as $root) {
            $draw($root, '    ');
        }

        $lines[] = '}';

        foreach ($model['edges'] as $edge) {
            $from = 'm'.$ids[$edge['from']];
            $to = 'm'.$ids[$edge['to']];
            $lines[] = sprintf('Rel(%s, %s, %s%s)', $from, $to, self::plantuml(self::label($edge)), $edge['violations'] > 0 ? ', $tags="violation"' : '');
        }

        $lines[] = '@enduml';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * A PlantUML string literal. PlantUML has no escape for a double quote
     * inside a string, so quotes and percent signs (which start its %chr()
     * functions) become %chr() calls and a line break becomes the \n PlantUML
     * draws as one. A backslash is doubled and followed by a zero-width space
     * (%chr(8203)): drawn alone, the "\t" of "App\tenant" would be a tab.
     */
    private static function plantuml(string $text): string
    {
        // The percent signs first: the functions the other escapes add have some.
        $text = str_replace('%', '%chr(37)', $text);
        $text = str_replace(['\\', '"'], ['\\\\%chr(8203)', '%chr(34)'], $text);

        return '"'.str_replace(["\r\n", "\r", "\n"], '\\n', $text).'"';
    }

    /**
     * What an arrow of cordon:graph says: the number of references, and the
     * named interfaces the dependency is limited to ("3 Catalog::Pricing").
     *
     * @param  array{references: int, interfaces?: list<string>}  $edge
     */
    private static function label(array $edge): string
    {
        return implode(' ', [(string) $edge['references'], ...(isset($edge['interfaces']) ? [implode(', ', $edge['interfaces'])] : [])]);
    }

    /**
     * The named interfaces ("Catalog::Pricing") the source module lists for
     * the target of an edge, when it does not also list the whole module. The
     * target is the module the source sees, as in the rules: outside a parent
     * a nested module is the parent, so the parent's entries apply, and
     * inside it the entries of the nested module itself do.
     *
     * @return list<string>
     */
    private static function declaredInterfaces(Edge $edge, ?ModuleMap $map): array
    {
        if ($edge->from->dependsOn === null) {
            return [];
        }

        $name = $map?->visible($edge->to, $edge->from)->name ?? $edge->to->name;

        if ($edge->from->declaresWhole($name)) {
            return [];
        }

        return array_map(static fn (string $interface): string => $name.'::'.$interface, $edge->from->declaredInterfaces($name));
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
