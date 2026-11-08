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
}
