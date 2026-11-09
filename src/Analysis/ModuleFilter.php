<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Closure;
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
            array_values(array_intersect($result->checkedModules ?? $modules, $modules)),
            ...self::ignores($result, static fn (Violation $violation): bool => self::involvesAny($violation, $modules), static fn (string $file): bool => in_array($result->modules->forPath($file)?->name, $modules, true)),
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
            array_values(array_intersect($result->checkedModules ?? $touched, $touched)),
            ...self::ignores($result, $kept, static function (string $file) use ($paths): bool {
                foreach ($paths as $path) {
                    if (self::within(realpath($file) ?: $file, $path)) {
                        return true;
                    }
                }

                return false;
            }),
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

    /**
     * The @cordon-ignore fields of a filtered result: what was ignored and the
     * comments that match nothing, only for the violations and files kept.
     *
     * @param  Closure(Violation): bool  $keepViolation
     * @param  Closure(string): bool  $keepFile
     * @return array{0: int, 1: list<string>, 2: list<string>, 3: list<Violation>, 4: list<string>}
     */
    private static function ignores(Result $result, Closure $keepViolation, Closure $keepFile): array
    {
        $ignored = array_values(array_filter($result->ignoredViolations, $keepViolation));
        $warnings = [];
        $files = [];

        foreach ($result->ignoreWarnings as $index => $warning) {
            $file = $result->ignoreWarningFiles[$index] ?? null;

            if ($file !== null && $keepFile($file)) {
                $warnings[] = $warning;
                $files[] = $file;
            }
        }

        return [count($ignored), $result->ignoreErrors, $warnings, $ignored, $files];
    }

    private static function within(string $path, string $directory): bool
    {
        $directory = rtrim($directory, '/\\');

        return $path === $directory || str_starts_with($path, $directory.DIRECTORY_SEPARATOR);
    }
}
