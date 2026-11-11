<?php

declare(strict_types=1);

namespace Cordon\Analysis;

/**
 * A `// @cordon-ignore <rule>[, <rule>] (<reason>)` comment: a decision to
 * accept a violation where it is, unlike the baseline, which records debt.
 *
 * @internal
 */
final readonly class IgnoreDirective
{
    /**
     * @param  int  $line  The line of the comment.
     * @param  list<string>  $rules
     * @param  ?string  $reason  Null when the parentheses are missing or empty.
     * @param  list<int>  $lines  The lines it covers: its own and the next one with code.
     */
    public function __construct(
        public int $line,
        public array $rules,
        public ?string $reason,
        public array $lines,
    ) {}

    public function covers(string $rule, ?int $line): bool
    {
        return $line !== null && in_array($rule, $this->rules, true) && in_array($line, $this->lines, true);
    }
}
