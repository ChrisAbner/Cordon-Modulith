<?php

declare(strict_types=1);

namespace Cordon\Reporters;

use Cordon\Analysis\Result;
use Cordon\Contracts\Reporter;
use Cordon\Rules\Severity;
use Cordon\Support\Paths;

/**
 * GitLab Code Quality report: errors are "major" issues, warnings "minor".
 *
 * The fingerprint is the baseline key (rule, file and target, without the
 * line) plus the occurrence number, so an issue keeps its identity when the
 * code above it moves, and two identical violations in one file stay apart.
 * Violations without a file, such as cycles, point at config/cordon.php.
 *
 * @internal
 */
final class GitlabReporter implements Reporter
{
    public function formatted(): bool
    {
        return false;
    }

    public function render(Result $result): string
    {
        $issues = [];
        $seen = [];

        foreach ([...$result->violations, ...$result->warnings, ...ParseErrors::asWarnings($result)] as $violation) {
            $key = $violation->baselineKey($result->basePath);
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            $issues[] = [
                'description' => $violation->message,
                'check_name' => 'cordon.'.$violation->rule,
                'fingerprint' => hash('sha256', $key.'#'.$seen[$key]),
                'severity' => $violation->severity === Severity::Warn ? 'minor' : 'major',
                'location' => [
                    'path' => Paths::relative($result->basePath, $violation->file) ?? 'config/cordon.php',
                    'lines' => ['begin' => $violation->line ?? 1],
                ],
            ];
        }

        return json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
