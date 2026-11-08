<?php

declare(strict_types=1);

namespace Cordon\Reporters;

use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Reporter;
use Cordon\Support\Paths;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * @internal
 */
final class TextReporter implements Reporter
{
    public function formatted(): bool
    {
        return true;
    }

    public function render(Result $result): string
    {
        $lines = [
            sprintf(
                '<options=bold>Cordon</> analysed %d files in %d modules (%d cross-module references).',
                $result->filesAnalysed,
                count($result->modules),
                $result->crossModuleReferences,
            ),
            '',
        ];

        foreach ($result->violations as $violation) {
            $lines[] = sprintf('<fg=red>x</> <fg=yellow>[%s]</>%s', $violation->rule, $this->location($violation, $result));
            $lines[] = '  '.OutputFormatter::escape($violation->message);
            $lines[] = '';
        }

        foreach ($result->warnings as $warning) {
            $lines[] = sprintf('<fg=yellow>!</> <fg=yellow>[%s]</>%s <comment>(warn)</comment>', $warning->rule, $this->location($warning, $result));
            $lines[] = '  '.OutputFormatter::escape($warning->message);
            $lines[] = '';
        }

        foreach ($result->parseErrors as $file => $error) {
            $lines[] = sprintf(
                '<comment>! Skipped %s (parse error: %s)</comment>',
                OutputFormatter::escape((string) Paths::relative($result->basePath, $file)),
                OutputFormatter::escape($error),
            );
        }

        if ($result->parseErrors !== []) {
            $lines[] = '';
        }

        $count = count($result->violations);

        $summary = $count === 0
            ? '<fg=green;options=bold>No boundary violations.</>'
            : sprintf('<fg=red;options=bold>%d boundary %s.</>', $count, $count === 1 ? 'violation' : 'violations');

        if ($result->warnings !== []) {
            $summary .= sprintf(' <comment>(%d %s)</comment>', count($result->warnings), count($result->warnings) === 1 ? 'warning' : 'warnings');
        }

        if ($result->suppressed > 0) {
            $summary .= sprintf(' <comment>(%d suppressed by the baseline)</comment>', $result->suppressed);
        }

        if ($result->ignored > 0) {
            $summary .= sprintf(' <comment>(%d ignored in place)</comment>', $result->ignored);
        }

        $lines[] = $summary;

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function location(Violation $violation, Result $result): string
    {
        if ($violation->file === null) {
            return '';
        }

        return ' '.OutputFormatter::escape((string) Paths::relative($result->basePath, $violation->file))
            .($violation->line !== null ? ':'.$violation->line : '');
    }
}
