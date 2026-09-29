<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Support\Paths;

final readonly class Violation
{
    public function __construct(
        public string $rule,
        public string $message,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $sourceModule = null,
        public ?string $targetModule = null,
        public ?string $target = null,
    ) {}

    /**
     * Identity used by the baseline. Line numbers are left out on purpose so
     * unrelated edits do not invalidate the baseline.
     */
    public function baselineKey(?string $basePath): string
    {
        return implode('|', [$this->rule, Paths::relative($basePath, $this->file) ?? '', $this->target ?? '']);
    }

    /**
     * @return array{rule: string, message: string, file: string|null, line: int|null, source_module: string|null, target_module: string|null, target: string|null}
     */
    public function toArray(?string $basePath = null): array
    {
        return [
            'rule' => $this->rule,
            'message' => $this->message,
            'file' => Paths::relative($basePath, $this->file),
            'line' => $this->line,
            'source_module' => $this->sourceModule,
            'target_module' => $this->targetModule,
            'target' => $this->target,
        ];
    }
}
