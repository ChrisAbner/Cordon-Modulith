<?php

declare(strict_types=1);

namespace Cordon\Testing;

use Cordon\Analysis\Violation;
use Cordon\Support\Paths;
use PHPUnit\Framework\Assert;

/**
 * Implementation of the Pest expectation toRespectBoundaries().
 *
 * @internal
 */
final class BoundaryExpectation
{
    public static function assert(mixed $module): void
    {
        if (! is_string($module)) {
            Assert::fail(sprintf('toRespectBoundaries() expects a module name, got %s.', get_debug_type($module)));
        }

        // Like cordon:verify, a broken config fails instead of changing what is
        // checked: with depend_on instead of depends_on the module would pass.
        if (($errors = Cordon::configurationErrors()) !== []) {
            Assert::fail(implode(PHP_EOL, ['config/cordon.php has errors:', ...array_map(fn (string $error): string => '  - '.$error, $errors)]));
        }

        $modules = Cordon::modules();

        if (! in_array($module, $modules, true)) {
            Assert::fail(sprintf(
                'Unknown module [%s]. Detected modules: %s.',
                $module,
                $modules === [] ? '(none)' : implode(', ', $modules),
            ));
        }

        if (($errors = Cordon::result()->ignoreErrors) !== []) {
            Assert::fail(implode(PHP_EOL, ['Invalid @cordon-ignore comments:', ...array_map(fn (string $error): string => '  - '.$error, $errors)]));
        }

        $violations = Cordon::violationsOf($module);

        Assert::assertEmpty(
            $violations,
            $violations === [] ? '' : self::message($module, $violations, Cordon::result()->basePath),
        );
    }

    /**
     * @param  list<Violation>  $violations
     */
    public static function message(string $module, array $violations, ?string $basePath): string
    {
        $lines = [sprintf(
            'Module [%s] does not respect its boundaries (%d %s):',
            $module,
            count($violations),
            count($violations) === 1 ? 'violation' : 'violations',
        )];

        foreach ($violations as $violation) {
            $location = $violation->file === null
                ? ''
                : ' '.Paths::relative($basePath, $violation->file).($violation->line === null ? '' : ':'.$violation->line);

            $lines[] = sprintf('  - [%s]%s %s', $violation->rule, $location, $violation->message);
        }

        return implode(PHP_EOL, $lines);
    }
}
