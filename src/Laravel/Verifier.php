<?php

declare(strict_types=1);

namespace Cordon\Laravel;

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\Result;
use Cordon\Baseline\Baseline;
use Cordon\Contracts\ModuleResolver;
use Cordon\Module\ModuleMap;
use Cordon\Support\Paths;
use Illuminate\Contracts\Config\Repository;

/**
 * The verification flow shared by cordon:verify and the Pest expectation:
 * resolve and configure modules, analyse them and apply the baseline.
 */
final class Verifier
{
    private ?ModuleMap $modules = null;

    public function __construct(
        private readonly ModuleResolver $resolver,
        private readonly Analyzer $analyzer,
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
        return $this->modules ??= $this->resolver->resolve()->configure($this->moduleConfig());
    }

    /**
     * Problems in the per-module config, such as unknown module names.
     *
     * @return list<string>
     */
    public function configurationWarnings(): array
    {
        return $this->resolver->resolve()->configurationWarnings($this->moduleConfig());
    }

    /**
     * Every violation, ignoring the baseline.
     */
    public function analyze(): Result
    {
        return $this->analyzer->analyze($this->modules(), $this->basePath);
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
        return $this->baseline()?->staleEntries($result->violations, $this->basePath) ?? 0;
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
        $pruned = $baseline->prune($result->violations, $this->basePath);
        $pruned->save($this->baselineFile());

        [$new] = $pruned->filter($result->violations, $this->basePath);

        return [$baseline->staleEntries($result->violations, $this->basePath), $pruned->count(), count($new)];
    }

    public function baselineFile(): string
    {
        $file = $this->config->get('cordon.baseline', 'cordon-baseline.json');

        return Paths::join($this->basePath, is_string($file) && $file !== '' ? $file : 'cordon-baseline.json');
    }

    private function baseline(): ?Baseline
    {
        $file = $this->baselineFile();

        return is_file($file) ? Baseline::load($file) : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function moduleConfig(): array
    {
        return (array) $this->config->get('cordon.modules', []);
    }
}
