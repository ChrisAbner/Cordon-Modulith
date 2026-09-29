<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Baseline\Baseline;
use Cordon\Module\ModuleMap;

final readonly class Result
{
    /**
     * @param  list<Violation>  $violations
     * @param  array<string, string>  $parseErrors  file => message
     */
    public function __construct(
        public ModuleMap $modules,
        public array $violations,
        public int $filesAnalysed,
        public int $crossModuleReferences,
        public array $parseErrors = [],
        public int $suppressed = 0,
        public ?string $basePath = null,
    ) {}

    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    public function withBaseline(Baseline $baseline): self
    {
        [$remaining, $suppressed] = $baseline->filter($this->violations, $this->basePath);

        return new self(
            $this->modules,
            $remaining,
            $this->filesAnalysed,
            $this->crossModuleReferences,
            $this->parseErrors,
            $this->suppressed + $suppressed,
            $this->basePath,
        );
    }
}
