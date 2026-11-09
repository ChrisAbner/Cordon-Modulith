<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Rules\CycleRule;
use Cordon\Rules\UnusedDependencyRule;
use Cordon\Support\ConfigValidator;
use Cordon\Support\Paths;

/**
 * Applies the @cordon-ignore comments of a snapshot to its violations, and
 * says which comments are invalid or no longer match anything.
 *
 * @internal
 */
final class IgnoreChecker
{
    /** Rules whose violations have no file or line, so a comment cannot point at them. */
    private const WITHOUT_LINE = [CycleRule::ID, UnusedDependencyRule::ID];

    /** @var array<string, list<IgnoreDirective>> Valid directives by file. */
    private array $directives = [];

    /** @var list<array{0: string, 1: int, 2: string}> file, line, message */
    private array $errors = [];

    /** @var array<string, true> "file|line|rule" of the directives that matched */
    private array $used = [];

    /** @var list<Violation> */
    private array $ignored = [];

    /**
     * @param  list<FileAnalysis>  $analyses
     * @param  list<string>  $ruleIds  The rule ids a directive may name.
     */
    public function __construct(array $analyses, private readonly array $ruleIds, private readonly ?string $basePath)
    {
        foreach ($analyses as $analysis) {
            foreach ($analysis->ignores as $directive) {
                if ($this->validate($analysis->file, $directive)) {
                    $this->directives[$analysis->file][] = $directive;
                }
            }
        }
    }

    /**
     * @param  list<Violation>  $violations
     * @return list<Violation>
     */
    public function filter(array $violations): array
    {
        $kept = [];

        foreach ($violations as $violation) {
            $directive = $violation->file === null ? null : IgnoreDirectives::covering($this->directives[$violation->file] ?? [], $violation->rule, $violation->line);

            if ($directive === null) {
                $kept[] = $violation;

                continue;
            }

            $this->used[$violation->file.'|'.$directive->line.'|'.$violation->rule] = true;
            $this->ignored[] = $violation;
        }

        return $kept;
    }

    /**
     * @return list<Violation>
     */
    public function ignored(): array
    {
        return $this->ignored;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->sorted($this->errors);
    }

    /**
     * @return list<array{file: string, message: string}>
     */
    public function unused(): array
    {
        $unused = [];

        foreach ($this->directives as $file => $directives) {
            foreach ($directives as $directive) {
                foreach ($directive->rules as $rule) {
                    if (! isset($this->used[$file.'|'.$directive->line.'|'.$rule])) {
                        $unused[] = [$file, $directive->line, sprintf('%s (%s) no longer matches a violation on its line or the statement after it; remove it.', $this->where($file, $directive), $rule)];
                    }
                }
            }
        }

        usort($unused, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $entry): array => ['file' => $entry[0], 'message' => $entry[2]], $unused);
    }

    private function validate(string $file, IgnoreDirective $directive): bool
    {
        $valid = true;

        if ($directive->reason === null) {
            $this->errors[] = [$file, $directive->line, sprintf('%s needs a reason in parentheses: // @cordon-ignore %s (why this is accepted).', $this->where($file, $directive), implode(', ', $directive->rules))];
            $valid = false;
        }

        if ($directive->rules === []) {
            $this->errors[] = [$file, $directive->line, sprintf('%s names no rule: // @cordon-ignore <rule> (<reason>).', $this->where($file, $directive))];
            $valid = false;
        }

        foreach ($directive->rules as $rule) {
            if (in_array($rule, self::WITHOUT_LINE, true)) {
                $this->errors[] = [$file, $directive->line, sprintf('%s names [%s], which reports no line to ignore: record it in the baseline instead.', $this->where($file, $directive), $rule)];
                $valid = false;
            } elseif (! in_array($rule, $this->ruleIds, true)) {
                $this->errors[] = [$file, $directive->line, sprintf('%s names unknown rule [%s].%s', $this->where($file, $directive), $rule, ConfigValidator::didYouMean($rule, $this->ruleIds))];
                $valid = false;
            }
        }

        return $valid;
    }

    private function where(string $file, IgnoreDirective $directive): string
    {
        return sprintf('Cordon ignore directive in %s:%d', Paths::relative($this->basePath, $file), $directive->line);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string}>  $messages
     * @return list<string>
     */
    private function sorted(array $messages): array
    {
        usort($messages, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($messages, 2);
    }
}
