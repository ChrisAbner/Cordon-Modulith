<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;

/**
 * A module may only use classes from another module's public API.
 */
final class InternalAccessRule implements Rule
{
    public const ID = 'internal_access';

    public function id(): string
    {
        return self::ID;
    }

    public function check(AnalysisContext $context): iterable
    {
        $reported = [];

        foreach ($context->edges as $edge) {
            $target = $edge->reference->target;
            $key = $edge->reference->sourceFile.'|'.$target;

            if (isset($reported[$key]) || $context->policy->isPublic($target, $edge->to, $context->symbols->get($target))) {
                continue;
            }

            $reported[$key] = true;

            yield new Violation(
                self::ID,
                sprintf(
                    'Module [%s] uses %s, which is internal to module [%s]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).',
                    $edge->from->name,
                    $target,
                    $edge->to->name,
                ),
                $edge->reference->sourceFile,
                $edge->reference->line,
                $edge->from->name,
                $edge->to->name,
                $target,
            );
        }
    }
}
