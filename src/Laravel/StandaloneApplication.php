<?php

declare(strict_types=1);

namespace Cordon\Laravel;

use Composer\InstalledVersions;
use Cordon\Laravel\Commands\DocsCommand;
use Cordon\Laravel\Commands\ExplainCommand;
use Cordon\Laravel\Commands\GraphCommand;
use Cordon\Laravel\Commands\ImpactCommand;
use Cordon\Laravel\Commands\ModulesCommand;
use Cordon\Laravel\Commands\VerifyCommand;
use Cordon\Laravel\Commands\WhyCommand;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The vendor/bin/cordon console: the same commands as artisan, run without
 * booting the Laravel application, so CI needs no .env, database or APP_KEY.
 *
 * Configuration comes from StandaloneConfig and the services from the same
 * bindings CordonServiceProvider registers, on a bare ProjectContainer.
 *
 * @internal
 */
final class StandaloneApplication
{
    /**
     * Short command names, mapped to the artisan commands they run.
     */
    private const COMMANDS = [
        'verify' => VerifyCommand::class,
        'modules' => ModulesCommand::class,
        'docs' => DocsCommand::class,
        'explain' => ExplainCommand::class,
        'why' => WhyCommand::class,
        'impact' => ImpactCommand::class,
        'graph' => GraphCommand::class,
    ];

    public static function run(string $basePath, ?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $output ??= new ConsoleOutput;
        $config = StandaloneConfig::load($basePath);
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        foreach ($config->warnings() as $warning) {
            $errors->writeln('<comment>'.$warning.'</comment>');
        }

        $application = self::make($basePath, $config);
        $application->setAutoExit(false);

        return $application->run($input, $output);
    }

    public static function make(string $basePath, ?StandaloneConfig $config = null): Application
    {
        $container = new ProjectContainer($basePath);
        $container->instance(Repository::class, $config ?? StandaloneConfig::load($basePath));
        $container->alias(Repository::class, 'config');
        Container::setInstance($container);

        // StandaloneConfig already merges config/cordon.php over the package defaults.
        CordonServiceProvider::bindServices($container, $basePath);

        $commands = [];

        foreach (self::COMMANDS as $name => $class) {
            $command = new $class;
            $command->setLaravel($container);
            $command->setAliases([(string) $command->getName()]);
            $command->setName($name);
            $commands[] = $command;
        }

        $application = new Application('Cordon Modulith', InstalledVersions::getPrettyVersion('chrisabner/cordon-modulith') ?? 'dev');
        $application->addCommands($commands);

        return $application;
    }
}
