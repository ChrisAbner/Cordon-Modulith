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

/**
 * Entry point for tests: the modules of the application and their violations.
 *
 * The analysis runs once per process and is reused while the config and the
 * baseline file stay the same, so many module expectations stay fast.
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
