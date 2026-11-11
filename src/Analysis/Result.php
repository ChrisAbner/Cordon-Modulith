<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Baseline\Baseline;
use Cordon\Module\ModuleMap;

/**
 * @internal
 */
final readonly class Result
{
    /**
     * @param  list<Violation>  $violations
     * @param  array<string, string>  $parseErrors  file => message
     * @param  list<Violation>  $warnings  Violations of rules set to warn: reported, never failing.
     * @param  list<string>|null  $checkedModules  The modules a --module or paths filter kept; null when every module was checked.
     * @param  int  $ignored  Violations and warnings a @cordon-ignore comment accepted.
     * @param  list<string>  $ignoreErrors  Invalid @cordon-ignore comments (no reason, unknown rule): they fail like config errors.
     * @param  list<string>  $ignoreWarnings  @cordon-ignore comments that no longer match a violation.
     * @param  list<Violation>  $ignoredViolations  What $ignored counts, so a --module or paths filter can narrow it.
     * @param  list<string>  $ignoreWarningFiles  The file of each of $ignoreWarnings, in the same order.
     */
    public function __construct(
        public ModuleMap $modules,
        public array $violations,
        public int $filesAnalysed,
        public int $crossModuleReferences,
        public array $parseErrors = [],
        public int $suppressed = 0,
        public ?string $basePath = null,
        public array $warnings = [],
        public ?array $checkedModules = null,
        public int $ignored = 0,
        public array $ignoreErrors = [],
        public array $ignoreWarnings = [],
        public array $ignoredViolations = [],
        public array $ignoreWarningFiles = [],
    ) {}

    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    public function withBaseline(Baseline $baseline): self
    {
        [$remaining, $suppressed] = $baseline->filter($this->violations, $this->basePath, $this->modules);

        return new self(
            $this->modules,
            $remaining,
            $this->filesAnalysed,
            $this->crossModuleReferences,
            $this->parseErrors,
            $this->suppressed + $suppressed,
            $this->basePath,
            $this->warnings,
            $this->checkedModules,
            $this->ignored,
            $this->ignoreErrors,
            $this->ignoreWarnings,
            $this->ignoredViolations,
            $this->ignoreWarningFiles,
        );
    }
}
