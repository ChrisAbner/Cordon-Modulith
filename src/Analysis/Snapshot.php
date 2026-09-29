<?php

declare(strict_types=1);

namespace Cordon\Analysis;

/**
 * Everything extracted from the modules before rules run: the rule context,
 * the per-file analyses and the files that could not be parsed.
 */
final readonly class Snapshot
{
    /**
     * @param  list<FileAnalysis>  $analyses
     * @param  array<string, string>  $parseErrors  file => message
     */
    public function __construct(
        public AnalysisContext $context,
        public array $analyses,
        public array $parseErrors = [],
    ) {}
}
