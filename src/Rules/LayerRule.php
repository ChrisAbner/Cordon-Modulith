<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;

/**
 * Layers, from the top down: a module may depend on modules of its own
 * layer and of the layers below it, never above. Modules outside every
 * layer are not checked. One violation per file and class depended on, like
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

    public function id(): string
    {
        return self::ID;
    }

    public function check(AnalysisContext $context): iterable
    {
        $reported = [];

        foreach ($context->edges as $edge) {
            $from = $this->layerOf[$edge->from->name] ?? null;
            $to = $this->layerOf[$edge->to->name] ?? null;
            $target = $edge->reference->target;
            $key = $edge->reference->sourceFile.'|'.strtolower($target);

            if ($from === null || $to === null || $to >= $from || isset($reported[$key]) || ($edge->isListener() && ! $this->countListeners)) {
                continue;
            }

            $reported[$key] = true;

            yield new Violation(
                self::ID,
                sprintf('Module [%s] (layer %d) depends on [%s] (layer %d) through %s; a layer may only depend on the layers below it.', $edge->from->name, $from, $edge->to->name, $to, $target),
                $edge->reference->sourceFile,
                $edge->reference->line,
                $edge->from->name,
                $edge->to->name,
                $target,
            );
        }
    }
}
