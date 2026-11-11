<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;

/**
 * Layers, from the top down: a module may depend on modules of its own
 * layer and of the layers below it, never above. Modules outside every
 * layer are not checked. A nested module that layers does not name takes the
 * layer of its nearest ancestor that it names, as source and as target. One violation per file and class depended on, like
 * internal_access, so the baseline tracks classes and not modules.
 *
 * @internal
 */
final class LayerRule implements Rule
{
    public const ID = 'layers';

    /** @var array<string, int> Module => its layer, from 1 at the top. */
    private array $layerOf = [];

    /**
     * @param  list<list<string>>  $layers  The layers config, from the top down.
     * @param  bool  $countListeners  Whether listening to a module's event counts (listeners.depends_on).
     */
    public function __construct(array $layers, private readonly bool $countListeners = true)
    {
        foreach ($layers as $index => $modules) {
            foreach ($modules as $module) {
                $this->layerOf[$module] ??= $index + 1;
            }
        }
    }

    /**
     * The layer of a module: its own, or the one of its nearest ancestor that
     * layers names when it is a nested module that layers does not name.
     */
    private function layerFor(Module $module, ModuleMap $modules): ?int
    {
        for ($current = $module; $current !== null; $current = $modules->parentOf($current)) {
            if (isset($this->layerOf[$current->name])) {
                return $this->layerOf[$current->name];
            }
        }

        return null;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function check(AnalysisContext $context): iterable
    {
        $reported = [];

        foreach ($context->edges as $edge) {
            $target = $context->modules->visible($edge->to, $edge->from);
            $from = $this->layerFor($edge->from, $context->modules);
            $to = $this->layerFor($target, $context->modules);
            $class = $edge->reference->target;
            $key = $edge->reference->sourceFile.'|'.strtolower($class);

            if ($from === null || $to === null || $to >= $from || isset($reported[$key]) || ($edge->isListener() && ! $this->countListeners)
                || $edge->from->isNestedIn($target) || $target->isNestedIn($edge->from)) {
                continue;
            }

            $reported[$key] = true;

            yield new Violation(
                self::ID,
                sprintf('Module [%s] (layer %d) depends on [%s] (layer %d) through %s; a layer may only depend on the layers below it.', $edge->from->name, $from, $target->name, $to, $class),
                $edge->reference->sourceFile,
                $edge->reference->line,
                $edge->from->name,
                $target->name,
                $class,
            );
        }
    }
}
