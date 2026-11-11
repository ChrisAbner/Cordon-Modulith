<?php

declare(strict_types=1);

namespace Cordon\Reporters;

use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Reporter;
use Cordon\Support\Paths;

/**
 * GitHub Actions workflow commands: violations show up as annotations on the pull request.
 *
 * @internal
 */
final class GithubReporter implements Reporter
{
    public function formatted(): bool
    {
        return false;
    }

    public function render(Result $result): string
    {
        $lines = [];

        $annotated = [
            ...array_map(static fn (Violation $violation): array => ['error', $violation], $result->violations),
            ...array_map(static fn (Violation $warning): array => ['warning', $warning], $result->warnings),
        ];

        foreach ($annotated as [$level, $violation]) {
            $properties = [];

            if ($violation->file !== null) {
                $properties['file'] = (string) Paths::relative($result->basePath, $violation->file);
                $properties['line'] = (string) ($violation->line ?? 1);
            }

            $properties['title'] = 'Cordon: '.$violation->rule;

            $encoded = [];

            foreach ($properties as $name => $value) {
                $encoded[] = $name.'='.self::escapeProperty($value);
            }

            $lines[] = sprintf('::%s %s::%s', $level, implode(',', $encoded), self::escapeData($violation->message));
        }

        // A file that does not parse is skipped, so its references are not checked.
        foreach ($result->parseErrors as $file => $error) {
            $lines[] = sprintf(
                '::warning file=%s,line=1,title=%s::%s',
                self::escapeProperty((string) Paths::relative($result->basePath, $file)),
                self::escapeProperty('Cordon: parse error'),
                self::escapeData(sprintf('Cordon skipped this file (parse error: %s). Its references were not checked.', $error)),
            );
        }

        $summary = sprintf(
            'Cordon: %d violation(s), %d suppressed by the baseline',
            count($result->violations),
            $result->suppressed,
        );

        if ($result->warnings !== []) {
            $summary .= sprintf(', %d warning(s)', count($result->warnings));
        }

        if ($result->ignored > 0) {
            $summary .= sprintf(', %d ignored in place', $result->ignored);
        }

        if ($result->parseErrors !== []) {
            $summary .= sprintf(', %d file(s) skipped because they do not parse', count($result->parseErrors));
        }

        $lines[] = $summary.'.';

        return implode("\n", $lines)."\n";
    }

    private static function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private static function escapeProperty(string $value): string
    {
        return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }
}
