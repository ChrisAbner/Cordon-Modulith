<?php

declare(strict_types=1);

namespace Cordon\Laravel;

use Closure;
use Cordon\Analysis\Analyzer;
use Cordon\Analysis\Result;
use Cordon\Analysis\Snapshot;
use Cordon\Analysis\Violation;
use Cordon\Baseline\Baseline;
use Cordon\Contracts\ModuleResolver;
use Cordon\Module\ModuleMap;
use Cordon\Support\ConfigValidator;
use Cordon\Support\HostPaths;
use Cordon\Support\Paths;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * The verification flow shared by cordon:verify and the Pest expectation:
 * resolve and configure modules, analyse them and apply the baseline.
 *
 * @internal
 */
final class Verifier
{
    private ?ModuleMap $resolved = null;

    private ?ModuleMap $modules = null;

    /** The loaded baseline: unset until first read, null when there is no file. */
    private ?Baseline $baseline = null;

    private bool $baselineLoaded = false;

    private ?Analyzer $builtAnalyzer = null;

    /**
     * @param  Analyzer|Closure(): Analyzer  $analyzer  A closure builds it on first use.
     */
    public function __construct(
        private readonly ModuleResolver $resolver,
        private readonly Analyzer|Closure $analyzer,
        private readonly Repository $config,
        private readonly string $basePath,
    ) {}

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Modules with their per-module settings applied.
     */
    public function modules(): ModuleMap
    {
        return $this->modules ??= $this->resolvedModules()->configure($this->moduleConfig());
    }

    /**
     * The existing directories of the code outside the modules (host.paths).
     *
     * @return list<string>
     */
    public function hostPaths(): array
    {
        return HostPaths::absolute((array) $this->config->get('cordon', []), $this->basePath);
    }

    /**
     * Problems in config/cordon.php, such as unknown keys or module names.
     *
     * @return list<string>
     */
    public function configurationErrors(): array
    {
        $errors = ConfigValidator::errors((array) $this->config->get('cordon', []), $this->resolvedModules());

        // Building the rules is the only check of custom rule ids (two rules with one id).
        if ($errors === []) {
            try {
                $this->analyzer();
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return $errors;
    }

    /**
     * The modules' declarations and cross-module references, without running the rules.
     */
    public function snapshot(): Snapshot
    {
        return $this->analyzer()->snapshot($this->modules(), $this->hostPaths());
    }

    /**
     * The violations of a snapshot, with the baseline applied unless $baseline is false.
     */
    public function check(Snapshot $snapshot, bool $baseline = true): Result
    {
        $result = $this->analyzer()->check($snapshot, $this->basePath);

        return $baseline ? $this->applyBaseline($result) : $result;
    }

    /**
     * Every violation, ignoring the baseline.
     */
    public function analyze(): Result
    {
        return $this->analyzer()->analyze($this->modules(), $this->basePath, $this->hostPaths());
    }

    /**
     * Violations that are not recorded in the baseline file, when there is one.
     */
    public function verify(): Result
    {
        return $this->applyBaseline($this->analyze());
    }

    public function applyBaseline(Result $result): Result
    {
        $baseline = $this->baseline();

        return $baseline !== null ? $result->withBaseline($baseline) : $result;
    }

    /**
     * Problems with the baseline file, such as entries an older version wrote.
     *
     * @return list<string>
     */
    public function baselineWarnings(): array
    {
        return $this->baseline()?->warnings() ?? [];
    }

    public function hasBaseline(): bool
    {
        return is_file($this->baselineFile());
    }

    /**
     * Baseline entries that record more occurrences than $result contains.
     */
    public function staleBaselineEntries(Result $result): int
    {
        return $this->baseline()?->staleEntries($this->found($result), $this->basePath) ?? 0;
    }

    /**
     * The stale baseline entries, with the occurrences each records and the ones $result contains.
     *
     * @return list<array{rule: string, file: string|null, target: string|null, expected: int, found: int}>
     */
    public function staleBaselineDetails(Result $result): array
    {
        return $this->baseline()?->staleDetails($this->found($result), $this->basePath) ?? [];
    }

    /**
     * Rewrites the baseline file without the occurrences $result no longer
     * contains. New violations are never added.
     *
     * @return array{0: int, 1: int, 2: int} Entries pruned, entries left, and violations not in the baseline.
     */
    public function pruneBaseline(Result $result): array
    {
        $baseline = $this->baseline() ?? Baseline::empty();
        $pruned = $baseline->prune($this->found($result), $this->basePath);
        $pruned->save($this->baselineFile());
        $this->baseline = $pruned;

        [$new] = $pruned->filter($result->violations, $this->basePath);

        return [$baseline->staleEntries($this->found($result), $this->basePath), $pruned->count(), count($new)];
    }

    public function baselineFile(): string
    {
        $file = $this->config->get('cordon.baseline', 'cordon-baseline.json');

        return Paths::join($this->basePath, is_string($file) && $file !== '' ? $file : 'cordon-baseline.json');
    }

    /**
     * Every violation still in the code, warnings included: moving a rule to
     * warn does not fix its violations, so their baseline entries still match.
     *
     * @return list<Violation>
     */
    private function found(Result $result): array
    {
        return [...$result->violations, ...$result->warnings];
    }

    private function analyzer(): Analyzer
    {
        return $this->builtAnalyzer ??= $this->analyzer instanceof Closure ? ($this->analyzer)() : $this->analyzer;
    }

    /**
     * Modules as the resolver found them, before the per-module settings.
     * Resolvers scan the disk, so this runs once per Verifier.
     */
    private function resolvedModules(): ModuleMap
    {
        return $this->resolved ??= $this->resolver->resolve();
    }

    private function baseline(): ?Baseline
    {
        if (! $this->baselineLoaded) {
            $file = $this->baselineFile();
            $this->baseline = is_file($file) ? Baseline::load($file) : null;
            $this->baselineLoaded = true;
        }

        return $this->baseline;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function moduleConfig(): array
    {
        return (array) $this->config->get('cordon.modules', []);
    }
}
