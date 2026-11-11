<?php

declare(strict_types=1);

namespace Cordon\Reporters;

use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Reporter;
use Cordon\Support\Paths;
use DOMDocument;

/**
 * JUnit report: one test case per checked module, failing with a failure
 * per error. Warnings and files that do not parse never fail: they go to the
 * suite's system-out.
 *
 * @internal
 */
final class JunitReporter implements Reporter
{
    public function formatted(): bool
    {
        return false;
    }

    public function render(Result $result): string
    {
        $checked = $result->checkedModules === null
            ? $result->modules->names()
            : array_values(array_intersect($result->modules->names(), $result->checkedModules));
        $byModule = array_fill_keys($checked, []);

        foreach ($result->violations as $violation) {
            $byModule[$violation->sourceModule ?? '(project)'][] = $violation;
        }

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $suites = $xml->appendChild($xml->createElement('testsuites'));
        $suite = $suites->appendChild($xml->createElement('testsuite'));

        foreach ([$suites, $suite] as $element) {
            $element->setAttribute('name', 'Cordon Modulith');
            $element->setAttribute('tests', (string) count($byModule));
            $element->setAttribute('failures', (string) count(array_filter($byModule)));
        }

        foreach ($byModule as $module => $violations) {
            $case = $suite->appendChild($xml->createElement('testcase'));
            $case->setAttribute('name', sprintf('Module %s respects its boundaries', $module));
            $case->setAttribute('classname', (string) $module);

            foreach ($violations as $violation) {
                $failure = $case->appendChild($xml->createElement('failure'));
                $failure->setAttribute('type', $violation->rule);
                $failure->setAttribute('message', $violation->message);
                $failure->appendChild($xml->createTextNode($this->line($violation, $result)));
            }
        }

        $lines = [
            ...array_map(fn (Violation $warning): string => '[warn] '.$this->line($warning, $result), $result->warnings),
            ...array_map(
                static fn (string $file, string $error): string => sprintf('[parse error] %s: %s', Paths::relative($result->basePath, $file), $error),
                array_keys($result->parseErrors),
                $result->parseErrors,
            ),
        ];

        if ($lines !== []) {
            $suite->appendChild($xml->createElement('system-out'))->appendChild($xml->createTextNode(implode(PHP_EOL, $lines)));
        }

        return (string) $xml->saveXML();
    }

    private function line(Violation $violation, Result $result): string
    {
        $location = $violation->file === null
            ? ''
            : ' '.Paths::relative($result->basePath, $violation->file).($violation->line === null ? '' : ':'.$violation->line);

        return sprintf('[%s]%s %s', $violation->rule, $location, $violation->message);
    }
}
