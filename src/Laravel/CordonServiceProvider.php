<?php

declare(strict_types=1);

namespace Cordon\Laravel;

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Contracts\DependencyExtractor;
use Cordon\Contracts\ModuleResolver;
use Cordon\Laravel\Commands\ModulesCommand;
use Cordon\Laravel\Commands\VerifyCommand;
use Cordon\Rules\RuleSet;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;

final class CordonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/cordon.php', 'cordon');

        $this->app->bind(ModuleResolver::class, function ($app): ModuleResolver {
            return ResolverFactory::make($app->make(Repository::class), $app->basePath());
        });

        $this->app->bind(DependencyExtractor::class, fn (): DependencyExtractor => new PhpParserExtractor);

        $this->app->bind(Analyzer::class, function ($app): Analyzer {
            /** @var Repository $config */
            $config = $app->make(Repository::class);

            return new Analyzer(
                $app->make(DependencyExtractor::class),
                new FileCollector(self::strings($config->get('cordon.exclude', ['vendor', 'node_modules']))),
                new PublicApiPolicy(self::strings($config->get('cordon.public_namespaces', []))),
                RuleSet::fromConfig((array) $config->get('cordon.rules', [])),
            );
        });

        $this->app->bind(Verifier::class, fn ($app): Verifier => new Verifier(
            $app->make(ModuleResolver::class),
            $app->make(Analyzer::class),
            $app->make(Repository::class),
            $app->basePath(),
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../../config/cordon.php' => $this->app->configPath('cordon.php'),
        ], 'cordon-config');

        $this->commands([
            VerifyCommand::class,
            ModulesCommand::class,
        ]);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_map('strval', array_filter((array) $value, 'is_scalar')));
    }
}
