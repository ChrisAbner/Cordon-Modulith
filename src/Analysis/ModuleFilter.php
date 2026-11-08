<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Rules\CycleRule;

/**
 * Narrows a result to the violations of some modules: the ones they cause
 * (source module) and the dependency cycles they take part in.
 *
 * @internal
 */
final class ModuleFilter
{
    /**
     * @param  list<string>  $modules
     */
    public static function apply(Result $result, array $modules): Result
    {
        $involved = static fn (Violation $violation): bool => self::involvesAny($violation, $modules);

        return new Result(
            $result->modules,
            array_values(array_filter($result->violations, $involved)),
            $result->filesAnalysed,
            $result->crossModuleReferences,
            $result->parseErrors,
            $result->suppressed,
            $result->basePath,
            array_values(array_filter($result->warnings, $involved)),
        );
    }

    /**
     * Only the violations of files under $paths (absolute, resolved). A
     * violation without a file, such as a cycle, stays when it involves a
     * module that has one of the paths: a pre-commit hook still learns that
     * the changed code takes part in a cycle.
     *
     * @param  list<string>  $paths
     */
    public static function applyPaths(Result $result, array $paths): Result
    {
        $touched = [];

        foreach ($result->modules as $module) {
            $directory = realpath($module->path) ?: $module->path;

            foreach ($paths as $path) {
                if (self::within($path, $directory) || self::within($directory, $path)) {
                    $touched[] = $module->name;

                    break;
                }
            }
        }

        $kept = static function (Violation $violation) use ($paths, $touched): bool {
            if ($violation->file === null) {
                return self::involvesAny($violation, $touched);
            }

            $file = realpath($violation->file) ?: $violation->file;

            foreach ($paths as $path) {
                if (self::within($file, $path)) {
                    return true;
                }
            }

            return false;
        };

        return new Result(
            $result->modules,
            array_values(array_filter($result->violations, $kept)),
            $result->filesAnalysed,
            $result->crossModuleReferences,
            $result->parseErrors,
            $result->suppressed,
            $result->basePath,
            array_values(array_filter($result->warnings, $kept)),
        );
    }

    /**
     * @param  list<string>  $modules
     */
    public static function involvesAny(Violation $violation, array $modules): bool
    {
        foreach ($modules as $module) {
            if (self::involves($violation, $module)) {
                return true;
            }
        }

        return false;
    }

    public static function involves(Violation $violation, string $module): bool
    {
        if ($violation->rule === CycleRule::ID && $violation->target !== null) {
            return in_array($module, explode(' -> ', $violation->target), true);
        }

        return $violation->sourceModule === $module;
    }

    private static function within(string $path, string $directory): bool
    {
        $directory = rtrim($directory, '/\\');

        return $path === $directory || str_starts_with($path, $directory.DIRECTORY_SEPARATOR);
    }
}
