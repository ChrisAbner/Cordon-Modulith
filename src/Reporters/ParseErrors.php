<?php

declare(strict_types=1);

namespace Cordon\Reporters;

use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Rules\Severity;

/**
 * The files that do not parse, as warnings for the CI formats: a skipped
 * file must not disappear from the report without a trace.
 *
 * @internal
 */
final class ParseErrors
{
    public const RULE = 'parse_error';

    /**
     * @return list<Violation>
     */
    public static function asWarnings(Result $result): array
    {
        $warnings = [];

        foreach ($result->parseErrors as $file => $error) {
            $warnings[] = new Violation(
                self::RULE,
                sprintf('Cordon skipped this file (parse error: %s). Its references were not checked.', $error),
                $file,
                1,
                severity: Severity::Warn,
            );
        }

        return $warnings;
    }
}
