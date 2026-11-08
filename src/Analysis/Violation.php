<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Rules\RuleDocs;
use Cordon\Rules\Severity;
use Cordon\Support\Paths;

/**
 * @api
 */
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
        public Severity $severity = Severity::Error,
    ) {}

    /**
     * The documentation of the rule; null for a custom rule.
     */
    public function docsUrl(): ?string
    {
        return RuleDocs::url($this->rule);
    }

    /**
     * The same violation with another severity; the rule's configured severity is applied after it runs.
     */
    public function withSeverity(Severity $severity): self
    {
        return new self($this->rule, $this->message, $this->file, $this->line, $this->sourceModule, $this->targetModule, $this->target, $severity);
    }

    /**
     * Identity used by the baseline. Line numbers are left out on purpose so
     * unrelated edits do not invalidate the baseline.
     */
    public function baselineKey(?string $basePath): string
    {
        return implode('|', [$this->rule, Paths::relative($basePath, $this->file) ?? '', $this->target ?? '']);
    }

    /**
     * @return array{rule: string, severity: string, message: string, file: string|null, line: int|null, source_module: string|null, target_module: string|null, target: string|null, docs_url: string|null}
     */
    public function toArray(?string $basePath = null): array
    {
        return [
            'rule' => $this->rule,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'file' => Paths::relative($basePath, $this->file),
            'line' => $this->line,
            'source_module' => $this->sourceModule,
            'target_module' => $this->targetModule,
            'target' => $this->target,
            'docs_url' => $this->docsUrl(),
        ];
    }
}
