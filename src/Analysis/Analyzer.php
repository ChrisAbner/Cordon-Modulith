<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Contracts\DependencyExtractor;
use Cordon\Contracts\Rule;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\Severity;

/**
 * Pipeline: collect files -> extract declarations/references -> build cross-module edges -> run rules.
 *
 * @internal
 */
final readonly class Analyzer
{
    /**
     * @param  list<Rule>  $rules
     * @param  array<string, Severity>  $severities  By rule id; a rule missing here reports errors.
     */
    public function __construct(
        private DependencyExtractor $extractor,
        private FileCollector $files,
        private PublicApiPolicy $policy,
        private array $rules,
        private array $severities = [],
    ) {}

    /**
     * @param  list<string>  $hostPaths  Absolute directories of the code outside the modules (host.paths).
     */
    public function analyze(ModuleMap $modules, ?string $basePath = null, array $hostPaths = []): Result
    {
        return $this->check($this->snapshot($modules, $hostPaths), $basePath);
    }

    /**
     * Run the rules on an existing snapshot.
     */
    public function check(Snapshot $snapshot, ?string $basePath = null): Result
    {
        $violations = [];
        $warnings = [];

        foreach ($this->rules as $rule) {
            $severity = $this->severities[$rule->id()] ?? Severity::Error;

            foreach ($rule->check($snapshot->context) as $violation) {
                if ($severity === Severity::Warn) {
                    $warnings[] = $violation->withSeverity(Severity::Warn);
                } else {
                    $violations[] = $violation;
                }
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
            $warnings,
        );
    }

    /**
     * Extract every module file and build the cross-module edges, without running rules.
     * Files under $hostPaths that no module owns are the code outside the
     * modules: their references to a module become host edges.
     *
     * @param  list<string>  $hostPaths  Absolute directories (host.paths).
     */
    public function snapshot(ModuleMap $modules, array $hostPaths = []): Snapshot
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

        $host = [];

        foreach ($hostPaths as $path) {
            foreach ($this->files->collect($path) as $file) {
                if (isset($seen[$file]) || $modules->forPath($file) !== null) {
                    continue;
                }

                $seen[$file] = $host[$file] = true;
                $analysis = $this->extractor->extract($file);

                if ($analysis->error !== null) {
                    $parseErrors[$file] = $analysis->error;
                }

                $analyses[] = $analysis;
            }
        }

        $edges = [];
        $hostEdges = [];
        $hostModule = Module::host();

        foreach ($analyses as $analysis) {
            if (isset($host[$analysis->file])) {
                foreach ($analysis->references as $reference) {
                    $to = $modules->forClass($reference->target);

                    if ($to !== null) {
                        $hostEdges[] = new Edge($hostModule, $to, $reference);
                    }
                }

                continue;
            }

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
            new AnalysisContext($modules, SymbolTable::fromAnalyses($analyses), $this->policy, $edges, $parseErrors, $hostEdges),
            $analyses,
            $parseErrors,
        );
    }
}
