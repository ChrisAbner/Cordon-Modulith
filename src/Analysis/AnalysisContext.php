<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Module\ModuleMap;

/**
 * Everything a rule needs. Rules must treat it as read-only.
 */
final readonly class AnalysisContext
{
    /**
     * @param  list<Edge>  $edges  Cross-module references only.
     */
    public function __construct(
        public ModuleMap $modules,
        public SymbolTable $symbols,
        public PublicApiPolicy $policy,
        public array $edges,
    ) {}
}
