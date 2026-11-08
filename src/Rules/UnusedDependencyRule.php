<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;
use Cordon\Module\Module;

/**
 * A depends_on entry no reference uses: depends_on drifts from the code when
 * dependencies are removed and the config is not. Warns by default.
 *
 * @internal
 */
final class UnusedDependencyRule implements Rule
{
    public const ID = 'unused_dependency';

    /**
     * @param  bool  $countListeners  Whether listening to a module's event counts as using it (listeners.depends_on).
     */
    public function __construct(private readonly bool $countListeners = true) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(AnalysisContext $context): iterable
    {
        $used = [];

        foreach ($context->edges as $edge) {
            if (! $edge->isListener() || $this->countListeners) {
                $used[$edge->from->name][$edge->to->name] = true;
            }
        }

        foreach ($context->modules as $module) {
            // A file that does not parse may hold the use: saying "remove it" would be a guess.
            if ($this->hasParseErrors($module, $context)) {
                continue;
            }

            foreach ($module->dependsOn ?? [] as $dependency) {
                if (! isset($used[$module->name][$dependency])) {
                    yield new Violation(
                        self::ID,
                        sprintf('Module [%s] lists [%s] in depends_on, but does not use it. Remove it so depends_on shows the real dependencies.', $module->name, $dependency),
                        null,
                        null,
                        $module->name,
                        $dependency,
                        $module->name.' -> '.$dependency,
                    );
                }
            }
        }
    }

    private function hasParseErrors(Module $module, AnalysisContext $context): bool
    {
        foreach (array_keys($context->parseErrors) as $file) {
            if ($module->ownsPath($file)) {
                return true;
            }
        }

        return false;
    }
}
