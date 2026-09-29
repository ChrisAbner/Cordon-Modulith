<?php

declare(strict_types=1);

namespace Cordon\Reporters;

use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Reporter;
use Cordon\Support\Paths;

final class JsonReporter implements Reporter
{
    public function formatted(): bool
    {
        return false;
    }

    public function render(Result $result): string
    {
        $parseErrors = [];

        foreach ($result->parseErrors as $file => $error) {
            $parseErrors[] = ['file' => Paths::relative($result->basePath, $file), 'error' => $error];
        }

        return json_encode([
            'summary' => [
                'modules' => count($result->modules),
                'files' => $result->filesAnalysed,
                'cross_module_references' => $result->crossModuleReferences,
                'violations' => count($result->violations),
                'suppressed' => $result->suppressed,
                'parse_errors' => count($parseErrors),
            ],
            'violations' => array_map(
                static fn (Violation $violation): array => $violation->toArray($result->basePath),
                $result->violations,
            ),
            'parse_errors' => $parseErrors,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
