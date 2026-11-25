<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Module\Module;

/**
 * Checks the named interfaces of the modules against the classes they name,
 * which only the analysis knows: an interface may list public classes only
 * (an error), and a pattern that matches no class is probably a typo (a warning).
 *
 * @internal
 */
final class InterfaceAudit
{
    /**
     * @return array{0: list<string>, 1: list<string>} The errors and the warnings.
     */
    public static function run(Snapshot $snapshot): array
    {
        $context = $snapshot->context;
        $classes = [];

        foreach ($snapshot->analyses as $analysis) {
            foreach ($analysis->declarations as $declaration) {
                $classes[] = $declaration;
            }
        }

        $errors = [];
        $warnings = [];

        foreach ($context->modules as $module) {
            // A nested module is public to the modules inside its parent.
            $consumer = $context->modules->parentOf($module);
            $incomplete = self::hasParseErrors($module, $context);

            foreach ($module->interfaces as $name => $patterns) {
                foreach ($patterns as $pattern) {
                    $shown = trim($pattern, '\\');
                    $matches = array_values(array_filter($classes, static fn (ClassDeclaration $class): bool => $module->ownsClass($class->fqcn)
                        && Module::matchesPattern(strtolower($module->relativeName($class->fqcn)), $pattern)));
                    $public = array_values(array_filter($matches, static fn (ClassDeclaration $class): bool => $context->policy->isPublic(
                        $class->fqcn,
                        $context->modules->forClass($class->fqcn) ?? $module,
                        $class,
                        $consumer,
                    )));

                    if ($matches === []) {
                        // A file that does not parse may hold the class.
                        if (! $incomplete) {
                            $warnings[] = sprintf('Module [%s] interface [%s] lists %s, which matches no class.', $module->name, $name, $shown);
                        }
                    } elseif (! str_ends_with($shown, '*')) {
                        // Naming one class that is not public is a mistake. A pattern may reach internal classes: they stay internal.
                        if ($public === []) {
                            $errors[] = sprintf('Module [%s] interface [%s] lists %s, which is not public.', $module->name, $name, $shown);
                        }
                    } elseif ($public === []) {
                        $warnings[] = sprintf('Module [%s] interface [%s] lists %s, which matches no public class.', $module->name, $name, $shown);
                    }
                }
            }
        }

        return [$errors, $warnings];
    }

    private static function hasParseErrors(Module $module, AnalysisContext $context): bool
    {
        foreach (array_keys($context->parseErrors) as $file) {
            if ($module->ownsPath($file) && $context->modules->forPath($file)?->name === $module->name) {
                return true;
            }
        }

        return false;
    }
}
