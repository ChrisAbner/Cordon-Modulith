<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;

/**
 * When a module declares depends_on, it may only depend on the listed modules.
 * Modules without a depends_on declaration are not checked. A nested module
 * is named with its parent (Billing.Invoices) and is seen as part of its parent
 * from outside it; a parent and its children need no declaration between them.
 *
 * @internal
 */
final class UndeclaredDependencyRule implements Rule
{
    public const ID = 'undeclared_dependency';

    /**
     * @param  bool  $countListeners  Whether listening to another module's event needs a depends_on entry.
     */
    public function __construct(private readonly bool $countListeners = true) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(AnalysisContext $context): iterable
    {
        $reported = [];

        foreach ($context->edges as $edge) {
            $declared = $edge->from->dependsOn;
            // A nested module exists only inside its parent: from outside, the dependency is on the parent.
            $to = $context->modules->visible($edge->to, $edge->from);

            // A parent uses its children, and a child its parent, without declaring it.
            if ($declared === null || in_array($to->name, $declared, true) || ($edge->isListener() && ! $this->countListeners)
                || $to->isNestedIn($edge->from) || $edge->from->isNestedIn($to)) {
                continue;
            }

            $key = $edge->reference->sourceFile.'|'.$to->name;

            if (isset($reported[$key])) {
                continue;
            }

            $reported[$key] = true;

            yield new Violation(
                self::ID,
                sprintf(
                    'Module [%s] depends on module [%s] (via %s), but [%s] is not listed in its depends_on.',
                    $edge->from->name,
                    $to->name,
                    $edge->reference->target,
                    $to->name,
                ),
                $edge->reference->sourceFile,
                $edge->reference->line,
                $edge->from->name,
                $to->name,
                $to->name,
            );
        }
    }
}
