<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Contracts\DependencyExtractor;
use Cordon\Contracts\Rule;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\RuleSet;
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
        $context = self::sorted($snapshot->context);

        foreach ($this->rules as $rule) {
            $severity = $this->severities[$rule->id()] ?? Severity::Error;

            foreach ($rule->check($context) as $violation) {
                if ($severity === Severity::Warn) {
                    $warnings[] = $violation->withSeverity(Severity::Warn);
                } else {
                    // A rule in error reports errors, whatever severity its violations claim.
                    $violations[] = $violation->severity === Severity::Error ? $violation : $violation->withSeverity(Severity::Error);
                }
            }
        }

        $ignores = new IgnoreChecker($snapshot->analyses, $this->ruleIds(), $basePath);
        $violations = $ignores->filter($violations);
        $warnings = $ignores->filter($warnings);

        return new Result(
            $snapshot->context->modules,
            $violations,
            count($snapshot->analyses),
            count($snapshot->context->edges),
            $snapshot->parseErrors,
            0,
            $basePath,
            $warnings,
            null,
            count($ignores->ignored()),
            $ignores->errors(),
            array_column($ignores->unused(), 'message'),
            $ignores->ignored(),
            array_column($ignores->unused(), 'file'),
        );
    }

    /**
     * The context with its references in a fixed order (file, line, target,
     * kind), so the violations, and the line each one reports, do not
     * depend on the order the files were read in.
     */
    private static function sorted(AnalysisContext $context): AnalysisContext
    {
        // strcmp, not <=>: two numeric-looking strings must not compare as numbers.
        $compare = static fn (Reference $a, Reference $b): int => strcmp($a->sourceFile, $b->sourceFile)
            ?: $a->line <=> $b->line
            ?: strcmp($a->target, $b->target)
            ?: strcmp($a->kind, $b->kind);
        $edges = static function (array $edges) use ($compare): array {
            usort($edges, static fn (Edge $a, Edge $b): int => $compare($a->reference, $b->reference));

            return $edges;
        };
        $signatures = $context->signatures;
        usort($signatures, $compare);

        return new AnalysisContext($context->modules, $context->symbols, $context->policy, $edges($context->edges), $context->parseErrors, $edges($context->hostEdges), $signatures);
    }

    /**
     * The ids @cordon-ignore may name: every built-in rule (one that is off
     * included) and the custom rules that run.
     *
     * @return list<string>
     */
    private function ruleIds(): array
    {
        return array_values(array_unique([...RuleSet::BUILT_IN, ...array_map(static fn (Rule $rule): string => $rule->id(), $this->rules)]));
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
        $signatures = [];
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

                if ($reference->signature !== null) {
                    $signatures[] = $reference;
                }

                if ($to !== null && $to->name !== $from->name) {
                    $edges[] = new Edge($from, $to, $reference);
                }
            }
        }

        return new Snapshot(
            new AnalysisContext($modules, SymbolTable::fromAnalyses($analyses), $this->policy, $edges, $parseErrors, $hostEdges, $signatures),
            $analyses,
            $parseErrors,
        );
    }
}
