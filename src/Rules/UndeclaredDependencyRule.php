<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;

/**
 * When a module declares depends_on, it may only depend on the listed modules.
 * Modules without a depends_on declaration are not checked.
 */
final class UndeclaredDependencyRule implements Rule
{
    public const ID = 'undeclared_dependency';

    public function id(): string
    {
        return self::ID;
    }

    public function check(AnalysisContext $context): iterable
    {
        $reported = [];

        foreach ($context->edges as $edge) {
            $declared = $edge->from->dependsOn;

            if ($declared === null || in_array($edge->to->name, $declared, true)) {
                continue;
            }

            $key = $edge->reference->sourceFile.'|'.$edge->to->name;

            if (isset($reported[$key])) {
                continue;
            }

            $reported[$key] = true;

            yield new Violation(
                self::ID,
                sprintf(
                    'Module [%s] depends on module [%s] (via %s), but [%s] is not listed in its depends_on.',
                    $edge->from->name,
                    $edge->to->name,
                    $edge->reference->target,
                    $edge->to->name,
                ),
                $edge->reference->sourceFile,
                $edge->reference->line,
                $edge->from->name,
                $edge->to->name,
                $edge->to->name,
            );
        }
    }
}
