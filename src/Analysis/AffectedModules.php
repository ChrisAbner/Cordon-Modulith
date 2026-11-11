<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Module\Module;
use Cordon\Module\ModuleMap;

/**
 * The modules a change can affect: the changed ones and, transitively, the
 * ones that depend on them (the graph of cordon:impact).
 *
 * A module that contains a changed nested module is affected too, since it is
 * what the outside sees, so whatever depends on the parent is. A change in a
 * parent also affects its nested modules, which may use its internals. The
 * code outside the modules (host) is affected when its own files change, and
 * nothing depends on it.
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
            if (! isset($affected[$name])) {
                $affected[$name] = ['module' => $name, 'reason' => $reason, 'through' => $through];
                $queue[] = $name;
            }
        };

        foreach ($changed as $name) {
            $add($name, self::CHANGED, null);
        }

        // The nested modules of a changed module: they may use its internals.
        foreach ($changed as $name) {
            foreach (self::descendants($modules, $name) as $nested) {
                $add($nested, self::NESTED, $name);
            }
        }

        while ($queue !== []) {
            $name = array_shift($queue);

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
