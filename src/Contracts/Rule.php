<?php

declare(strict_types=1);

namespace Cordon\Contracts;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;

interface Rule
{
    /**
     * Stable identifier used in config, reports and the baseline, e.g. "internal_access".
     */
    public function id(): string;

    /**
     * @return iterable<Violation>
     */
    public function check(AnalysisContext $context): iterable;
}
