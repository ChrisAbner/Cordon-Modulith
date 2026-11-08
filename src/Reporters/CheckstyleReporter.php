<?php

declare(strict_types=1);

namespace Cordon\Reporters;

use Cordon\Analysis\Result;
use Cordon\Contracts\Reporter;
use Cordon\Rules\Severity;
use Cordon\Support\Paths;
use DOMDocument;

/**
 * Checkstyle report, for CI tools that read it (Jenkins, reviewdog...).
 * Violations without a file, such as cycles, are listed under config/cordon.php.
 *
 * @internal
 */
final class CheckstyleReporter implements Reporter
{
    public function formatted(): bool
    {
        return false;
    }

    public function render(Result $result): string
    {
        $byFile = [];

        foreach ([...$result->violations, ...$result->warnings, ...ParseErrors::asWarnings($result)] as $violation) {
            $byFile[Paths::relative($result->basePath, $violation->file) ?? 'config/cordon.php'][] = $violation;
        }

        ksort($byFile);

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->appendChild($xml->createElement('checkstyle'));
        $root->setAttribute('version', '3.0');

        foreach ($byFile as $name => $violations) {
            $file = $root->appendChild($xml->createElement('file'));
            $file->setAttribute('name', (string) $name);

            foreach ($violations as $violation) {
                $error = $file->appendChild($xml->createElement('error'));
                $error->setAttribute('line', (string) ($violation->line ?? 1));
                $error->setAttribute('severity', $violation->severity === Severity::Warn ? 'warning' : 'error');
                $error->setAttribute('message', $violation->message);
                $error->setAttribute('source', 'cordon.'.$violation->rule);
            }
        }

        return (string) $xml->saveXML();
    }
}
