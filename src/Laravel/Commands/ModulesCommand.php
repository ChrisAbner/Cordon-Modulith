<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Contracts\ModuleResolver;
use Cordon\Laravel\ResolverFactory;
use Cordon\Module\Module;
use Cordon\Support\Paths;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

final class ModulesCommand extends Command
{
    protected $signature = 'cordon:modules';

    protected $description = 'List the modules Cordon detected and their boundary settings';

    public function handle(ModuleResolver $resolver, Repository $config): int
    {
        $basePath = $this->laravel->basePath();
        $modules = $resolver->resolve()->configure((array) $config->get('cordon.modules', []));

        $this->line(sprintf('Resolver: <info>%s</info>', ResolverFactory::driver($config, $basePath)));

        if (count($modules) === 0) {
            $this->warn('No modules found. Check the resolver settings in config/cordon.php.');

            return self::SUCCESS;
        }

        $this->table(
            ['Module', 'Namespace', 'Path', 'Depends on', 'Open'],
            array_map(static fn (Module $module): array => [
                $module->name,
                $module->namespace,
                (string) Paths::relative($basePath, $module->path),
                match (true) {
                    $module->dependsOn === null => '(not declared)',
                    $module->dependsOn === [] => '(none)',
                    default => implode(', ', $module->dependsOn),
                },
                $module->open ? 'yes' : 'no',
            ], $modules->all()),
        );

        return self::SUCCESS;
    }
}
