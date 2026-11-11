<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Module\Module;
use Cordon\Module\ModuleMap;

/**
 * The modules a change can affect: the changed ones and, transitively, the
 * ones that depend on them (the graph of cordon:impact).
 *
 * A module that contains an affected nested module is affected too, since it
 * is what the outside sees, so whatever depends on the parent is. And every
 * affected module, whatever the reason (changed, depends_on or parent), brings
 * in all its nested modules (reason nested): they may use its internals, and a
 * nested module using its parent is not an edge, so the graph would not reach
 * them. That includes the other nested modules of a parent reached only
 * because one of them is affected: over-including a sibling is acceptable,
 * missing a test is not. The code outside the modules (host) is affected when
 * its own files change, and nothing depends on it.
 *
 * @internal
 */
final class AffectedModules
{
    public const CHANGED = 'changed';

    public const DEPENDS_ON = 'depends_on';

    public const PARENT = 'parent';

    public const NESTED = 'nested';

    /**
     * @param  list<string>  $changed  Names of the modules with changed files, and Module::HOST for the code outside them.
     * @return list<array{module: string, reason: string, through: string|null}> The changed modules first, then the others by the order they are reached.
     */
    public static function of(AnalysisContext $context, array $changed): array
    {
        $graph = new DependentGraph($context);
        $modules = $context->modules;
        $changed = array_values(array_unique($changed));
        sort($changed);

        $affected = [];
        $queue = [];
        $add = static function (string $name, string $reason, ?string $through) use (&$affected, &$queue): void {
            if (isset($affected[$name])) {
                return;
            }

            $affected[$name] = ['module' => $name, 'reason' => $reason, 'through' => $through];
            $queue[] = $name;
        };

        foreach ($changed as $name) {
            $add($name, self::CHANGED, null);
        }

        // Every affected module is taken off the queue once, however it came in (changed, depends_on or parent), and brings
        // in its nested modules not yet added: they may use its internals, which a use of the parent is not an edge to follow.
        while ($queue !== []) {
            $name = array_shift($queue);

            foreach (self::descendants($modules, $name) as $nested) {
                $add($nested, self::NESTED, $name);
            }

            foreach (self::ancestors($modules, $name) as $parent) {
                $add($parent, self::PARENT, $name);
            }

            foreach ($graph->direct($name) as $dependent) {
                $add($dependent, self::DEPENDS_ON, $name);
            }
        }

        return array_values($affected);
    }

    /**
     * @return list<string>
     */
    private static function descendants(ModuleMap $modules, string $name): array
    {
        $module = $modules->get($name);

        if ($module === null || $name === Module::HOST) {
            return [];
        }

        $names = [];

        foreach ($modules as $other) {
            if ($other->isNestedIn($module)) {
                $names[] = $other->name;
            }
        }

        return $names;
    }

    /**
     * @return list<string> The modules $name is nested in, nearest first.
     */
    private static function ancestors(ModuleMap $modules, string $name): array
    {
        $names = [];
        $module = $modules->get($name);

        while ($module !== null && ($module = $modules->parentOf($module)) !== null) {
            $names[] = $module->name;
        }

        return $names;
    }
}
