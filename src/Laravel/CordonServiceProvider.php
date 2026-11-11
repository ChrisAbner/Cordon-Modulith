<?php

declare(strict_types=1);

namespace Cordon\Laravel;

use Cordon\Analysis\AnalysisCache;
use Cordon\Analysis\Analyzer;
use Cordon\Analysis\CachedExtractor;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Contracts\DependencyExtractor;
use Cordon\Contracts\ModuleResolver;
use Cordon\Contracts\Rule;
use Cordon\Laravel\Commands\DocsCommand;
use Cordon\Laravel\Commands\ExplainCommand;
use Cordon\Laravel\Commands\GraphCommand;
use Cordon\Laravel\Commands\ImpactCommand;
use Cordon\Laravel\Commands\ModulesCommand;
use Cordon\Laravel\Commands\VerifyCommand;
use Cordon\Laravel\Commands\WhyCommand;
use Cordon\Rules\RuleSet;
use Cordon\Support\Paths;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * @internal
 */
final class CordonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/cordon.php', 'cordon');

        self::bindServices($this->app, $this->app->basePath());
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../../config/cordon.php' => $this->app->configPath('cordon.php'),
        ], 'cordon-config');

        $this->publishes([
            __DIR__.'/../../stubs/ModuleBoundariesTest.php' => $this->app->basePath('tests/Feature/ModuleBoundariesTest.php'),
        ], 'cordon-tests');

        $this->commands([
            VerifyCommand::class,
            ModulesCommand::class,
            DocsCommand::class,
            ExplainCommand::class,
            WhyCommand::class,
            ImpactCommand::class,
            GraphCommand::class,
        ]);
    }

    /**
     * The services behind the commands, the Pest expectation and the
     * Verifier: bound on the application here, and on a bare container by
     * StandaloneApplication (vendor/bin/cordon).
     */
    public static function bindServices(Container $container, string $basePath): void
    {
        $container->bind(ModuleResolver::class, function ($app) use ($basePath): ModuleResolver {
            return ResolverFactory::make($app->make(Repository::class), $basePath);
        });

        $container->bind(DependencyExtractor::class, fn ($app): DependencyExtractor => new PhpParserExtractor(docblocks: self::docblocks($app->make(Repository::class))));

        // $parameters['extractor'] is the cached extractor the Verifier shares with its analyzer.
        $container->bind(Analyzer::class, function ($app, array $parameters = []): Analyzer {
            /** @var Repository $config */
            $config = $app->make(Repository::class);
            $rulesConfig = (array) $config->get('cordon.rules', []);
            [$rules, $severities] = RuleSet::build($rulesConfig, fn (string $class): Rule => $app->make($class), (array) $config->get('cordon.listeners', []), (array) $config->get('cordon.layers', []));

            return new Analyzer(
                $parameters['extractor'] ?? $app->make(DependencyExtractor::class),
                new FileCollector(self::strings($config->get('cordon.exclude', ['vendor', 'node_modules']))),
                new PublicApiPolicy(self::strings($config->get('cordon.public_namespaces', []))),
                $rules,
                $severities,
            );
        });

        // The analyzer is built on first use, after cordon:verify has checked the
        // config: building it from a broken rules config would throw first.
        $container->bind(Verifier::class, function ($app) use ($basePath): Verifier {
            $cache = self::cachedExtractor($app->make(DependencyExtractor::class), $app->make(Repository::class), $basePath);

            return new Verifier(
                $app->make(ModuleResolver::class),
                fn (): Analyzer => $app->make(Analyzer::class, ['extractor' => $cache]),
                $app->make(Repository::class),
                $basePath,
                $cache,
            );
        });
    }

    /**
     * The extractor behind the analysis cache, or null while `cache` is not set.
     */
    private static function cachedExtractor(DependencyExtractor $extractor, Repository $config, string $basePath): ?CachedExtractor
    {
        $path = $config->get('cordon.cache');

        // A value that is not a path is reported by ConfigValidator before anything is analysed.
        if (! is_string($path) || $path === '') {
            return null;
        }

        return new CachedExtractor($extractor, new AnalysisCache(Paths::join($basePath, $path), $basePath, AnalysisCache::fingerprint(self::extractorOptions($config))));
    }

    /**
     * The settings that change what the extractor returns for a file, and so
     * must invalidate the cache: one entry here per extractor option.
     *
     * @return array<string, bool|int|string|null>
     */
    private static function extractorOptions(Repository $config): array
    {
        return ['docblocks' => self::docblocks($config)];
    }

    /**
     * Whether `docblocks` is on. Anything but true is off: a value that is not a
     * boolean is reported by ConfigValidator before anything is analysed.
     */
    private static function docblocks(Repository $config): bool
    {
        return $config->get('cordon.docblocks') === true;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_map('strval', array_filter((array) $value, 'is_scalar')));
    }
}
