<?php

declare(strict_types=1);

namespace Cordon\Reporters;

use Cordon\Analysis\Result;
use Cordon\Contracts\Reporter;
use Cordon\Support\Paths;

/**
 * GitHub Actions workflow commands: violations show up as annotations on the pull request.
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

        foreach ($result->violations as $violation) {
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

            $lines[] = sprintf('::error %s::%s', implode(',', $encoded), self::escapeData($violation->message));
        }

        $lines[] = sprintf(
            'Cordon: %d violation(s), %d suppressed by the baseline.',
            count($result->violations),
            $result->suppressed,
        );

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
