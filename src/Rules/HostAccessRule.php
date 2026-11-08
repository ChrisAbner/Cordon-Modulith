<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;
use Cordon\Module\Module;

/**
 * The code outside the modules (controllers in app/Http, Filament
 * resources, routes, migrations) may only use the public API of a module.
 * One violation per file and internal class, like internal_access.
 *
 * @internal
 */
final class HostAccessRule implements Rule
{
    public const ID = 'host_access';

    public function id(): string
    {
        return self::ID;
    }

    public function check(AnalysisContext $context): iterable
    {
        $reported = [];
        $relations = [];

        foreach ($context->hostEdges as $edge) {
            if ($edge->reference->relation) {
                $relations[$edge->reference->sourceFile.'|'.$edge->reference->target] = true;
            }
        }

        foreach ($context->hostEdges as $edge) {
            $target = $edge->reference->target;
            $key = $edge->reference->sourceFile.'|'.$target;

            if (isset($reported[$key]) || $context->policy->isPublic($target, $edge->to, $context->symbols->get($target))) {
                continue;
            }

            $reported[$key] = true;

            yield new Violation(
                self::ID,
                sprintf(
                    'Code outside the modules uses %s, which is internal to module [%s]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).%s',
                    $target,
                    $edge->to->name,
                    isset($relations[$key]) ? ' '.InternalAccessRule::RELATION_HINT : '',
                ),
                $edge->reference->sourceFile,
                $edge->reference->line,
                Module::HOST,
                $edge->to->name,
                $target,
            );
        }
    }
}
