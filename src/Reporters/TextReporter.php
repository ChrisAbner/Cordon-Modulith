<?php

declare(strict_types=1);

namespace Cordon\Reporters;

use Cordon\Analysis\Result;
use Cordon\Contracts\Reporter;
use Cordon\Support\Paths;
use Symfony\Component\Console\Formatter\OutputFormatter;

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
            $location = '';

            if ($violation->file !== null) {
                $location = ' '.OutputFormatter::escape((string) Paths::relative($result->basePath, $violation->file))
                    .($violation->line !== null ? ':'.$violation->line : '');
            }

            $lines[] = sprintf('<fg=red>x</> <fg=yellow>[%s]</>%s', $violation->rule, $location);
            $lines[] = '  '.OutputFormatter::escape($violation->message);
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

        if ($result->suppressed > 0) {
            $summary .= sprintf(' <comment>(%d suppressed by the baseline)</comment>', $result->suppressed);
        }

        $lines[] = $summary;

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
