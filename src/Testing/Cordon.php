<?php

declare(strict_types=1);

namespace Cordon\Testing;

use Cordon\Analysis\ModuleFilter;
use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Laravel\Verifier;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * Entry point for tests: the modules of the application and their violations.
 *
 * The analysis runs once per process and is reused while the config and the
 * baseline file stay the same, so many module expectations stay fast.
 *
 * @api
 */
final class Cordon
{
    private static ?string $key = null;

    private static ?Result $result = null;

    /**
     * Names of the detected modules.
     *
     * @return list<string>
     */
    public static function modules(): array
    {
        return self::verifier()->modules()->names();
    }

    /**
     * Problems in config/cordon.php, as cordon:verify reports them.
     *
     * @return list<string>
     */
    public static function configurationErrors(): array
    {
        return self::verifier()->configurationErrors();
    }

    /**
     * The verification result with the baseline applied.
     */
    public static function result(): Result
    {
        $verifier = self::verifier();
        $key = self::cacheKey($verifier);

        if (self::$result === null || self::$key !== $key) {
            self::$result = $verifier->verify();
            self::$key = $key;
        }

        return self::$result;
    }

    /**
     * Violations caused by a module, plus the dependency cycles it takes part in.
     *
     * @return list<Violation>
     */
    public static function violationsOf(string $module): array
    {
        return ModuleFilter::apply(self::result(), [$module])->violations;
    }

    /**
     * Fails with every module that crosses its boundaries, all in one message,
     * and fails on a broken config, an invalid @cordon-ignore or no modules,
     * as cordon:verify does. The test file `vendor:publish --tag=cordon-tests` writes calls it.
     */
    public static function assertBoundaries(): void
    {
        if (($errors = self::configurationErrors()) !== []) {
            Assert::fail(implode(PHP_EOL, ['config/cordon.php has errors:', ...array_map(static fn (string $error): string => '  - '.$error, $errors)]));
        }

        $modules = self::modules();

        if ($modules === []) {
            Assert::fail('Cordon Modulith found no modules. Check the resolver settings in config/cordon.php.');
        }

        if (($errors = self::result()->ignoreErrors) !== []) {
            Assert::fail(implode(PHP_EOL, ['Invalid @cordon-ignore comments:', ...array_map(static fn (string $error): string => '  - '.$error, $errors)]));
        }

        $failures = [];

        foreach ($modules as $module) {
            if (($violations = self::violationsOf($module)) !== []) {
                $failures[] = BoundaryExpectation::message($module, $violations, self::result()->basePath);
            }
        }

        Assert::assertSame([], $failures, implode(PHP_EOL.PHP_EOL, $failures));
    }

    public static function flush(): void
    {
        self::$key = null;
        self::$result = null;
    }

    private static function verifier(): Verifier
    {
        $container = class_exists(Container::class) ? Container::getInstance() : null;

        if ($container === null || ! $container->bound(Verifier::class)) {
            throw new LogicException('Cordon Modulith needs a booted Laravel application. Use the expectation in a test that extends your Tests\TestCase.');
        }

        return $container->make(Verifier::class);
    }

    private static function cacheKey(Verifier $verifier): string
    {
        $baseline = $verifier->baselineFile();
        $config = Container::getInstance()->make(Repository::class)->get('cordon');

        return implode('|', [
            $verifier->basePath(),
            md5(serialize($config)),
            is_file($baseline) ? (string) filemtime($baseline).':'.(string) filesize($baseline) : 'no-baseline',
        ]);
    }
}
