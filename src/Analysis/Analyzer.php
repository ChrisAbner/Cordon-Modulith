<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Contracts\DependencyExtractor;
use Cordon\Contracts\Rule;
use Cordon\Module\ModuleMap;

/**
 * Pipeline: collect files -> extract declarations/references -> build cross-module edges -> run rules.
 */
final readonly class Analyzer
{
    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(
        private DependencyExtractor $extractor,
        private FileCollector $files,
        private PublicApiPolicy $policy,
        private array $rules,
    ) {}

    public function analyze(ModuleMap $modules, ?string $basePath = null): Result
    {
        return $this->check($this->snapshot($modules), $basePath);
    }

    /**
     * Run the rules on an existing snapshot.
     */
    public function check(Snapshot $snapshot, ?string $basePath = null): Result
    {
        $violations = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->check($snapshot->context) as $violation) {
                $violations[] = $violation;
            }
        }

        return new Result(
            $snapshot->context->modules,
            $violations,
            count($snapshot->analyses),
            count($snapshot->context->edges),
            $snapshot->parseErrors,
            0,
            $basePath,
        );
    }

    /**
     * Extract every module file and build the cross-module edges, without running rules.
     */
    public function snapshot(ModuleMap $modules): Snapshot
    {
        $analyses = [];
        $parseErrors = [];
        $seen = [];

        foreach ($modules as $module) {
            foreach ($this->files->collect($module->path) as $file) {
                if (isset($seen[$file])) {
                    continue;
                }

                $seen[$file] = true;
                $analysis = $this->extractor->extract($file);

                if ($analysis->error !== null) {
                    $parseErrors[$file] = $analysis->error;
                }

                $analyses[] = $analysis;
            }
        }

        $edges = [];

        foreach ($analyses as $analysis) {
            $from = $modules->forPath($analysis->file);

            if ($from === null) {
                continue;
            }

            foreach ($analysis->references as $reference) {
                $to = $modules->forClass($reference->target);

                if ($to !== null && $to->name !== $from->name) {
                    $edges[] = new Edge($from, $to, $reference);
                }
            }
        }

        return new Snapshot(
            new AnalysisContext($modules, SymbolTable::fromAnalyses($analyses), $this->policy, $edges),
            $analyses,
            $parseErrors,
        );
    }
}
