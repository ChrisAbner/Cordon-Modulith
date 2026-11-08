<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;

/**
 * A public class whose public signature names an internal class of its own
 * module: other modules cannot use the public class without reaching the
 * internal one. One violation per file and internal class, at the first
 * member that names it.
 *
 * @internal
 */
final class PublicApiLeakRule implements Rule
{
    public const ID = 'public_api_leak';

    public function id(): string
    {
        return self::ID;
    }

    public function check(AnalysisContext $context): iterable
    {
        $reported = [];

        foreach ($context->signatures as $reference) {
            $class = $reference->sourceClass;
            $module = $class === null ? null : $context->modules->forClass($class);
            $key = $reference->sourceFile.'|'.$reference->target;

            if ($class === null || $module === null || $reference->signature === null || isset($reported[$key])
                || $context->modules->forClass($reference->target)?->name !== $module->name
                || ! $context->policy->isPublic($class, $module, $context->symbols->get($class))
                || $context->policy->isPublic($reference->target, $module, $context->symbols->get($reference->target))) {
                continue;
            }

            $reported[$key] = true;

            yield new Violation(
                self::ID,
                sprintf('%s::%s %s, which is internal to [%s]: other modules reach it through the public API.', $class, $reference->signature, $reference->target, $module->name),
                $reference->sourceFile,
                $reference->line,
                $module->name,
                $module->name,
                $reference->target,
            );
        }
    }
}
