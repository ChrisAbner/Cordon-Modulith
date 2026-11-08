<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Module\ModuleMap;

/**
 * Everything a rule needs. Rules must treat it as read-only.
 *
 * @api
 */
final readonly class AnalysisContext
{
    /**
     * @param  list<Edge>  $edges  Cross-module references only.
     * @param  array<string, string>  $parseErrors  Files that could not be parsed (file => message): their references are missing from $edges.
     */
    public function __construct(
        public ModuleMap $modules,
        public SymbolTable $symbols,
        public PublicApiPolicy $policy,
        public array $edges,
        public array $parseErrors = [],
    ) {}
}
