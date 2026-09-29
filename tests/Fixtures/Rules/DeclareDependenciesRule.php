<?php

namespace Cordon\Tests\Fixtures\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;

/**
 * Example custom rule: every module must declare depends_on.
 */
final class DeclareDependenciesRule implements Rule
{
    public function id(): string
    {
        return 'declare_dependencies';
    }

    public function check(AnalysisContext $context): iterable
    {
        foreach ($context->modules as $module) {
            if ($module->dependsOn === null) {
                yield new Violation(
                    $this->id(),
                    sprintf('Module [%s] does not declare depends_on. List the modules it may use in config/cordon.php.', $module->name),
                    sourceModule: $module->name,
                    target: $module->name,
                );
            }
        }
    }
}
