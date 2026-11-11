<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use InvalidArgumentException;

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

    /**
     * @return array{line: int, rules: list<string>, reason: ?string, lines: list<int>}
     */
    public function toArray(): array
    {
        return ['line' => $this->line, 'rules' => $this->rules, 'reason' => $this->reason, 'lines' => $this->lines];
    }

    /**
     * @param  array<array-key, mixed>  $data  What toArray() wrote; anything else throws.
     */
    public static function fromArray(array $data): self
    {
        $rules = $data['rules'] ?? null;
        $lines = $data['lines'] ?? null;

        if (! is_array($rules) || ! array_is_list($rules) || ! is_array($lines) || ! array_is_list($lines)) {
            throw new InvalidArgumentException('Expected lists of rules and lines.');
        }

        return new self(
            $data['line'] ?? null,
            array_map(static fn (mixed $rule): string => is_string($rule) ? $rule : throw new InvalidArgumentException('Expected a rule id.'), $rules),
            $data['reason'] ?? null,
            array_map(static fn (mixed $line): int => is_int($line) ? $line : throw new InvalidArgumentException('Expected a line number.'), $lines),
        );
    }
}
