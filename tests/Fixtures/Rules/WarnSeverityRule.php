<?php

namespace Cordon\Tests\Fixtures\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;
use Cordon\Rules\Severity;

/**
 * Reports one violation that claims its own severity, whatever the config says.
 */
final class WarnSeverityRule implements Rule
{
    public function id(): string
    {
        return 'warn_severity';
    }

    public function check(AnalysisContext $context): iterable
    {
        yield new Violation('warn_severity', 'A violation that says it is a warning.', severity: Severity::Warn);
    }
}
