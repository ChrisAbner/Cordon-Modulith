<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Contracts\ModuleResolver;
use Cordon\Laravel\ResolverFactory;
use Cordon\Module\LayeredLayout;
use Cordon\Module\Module;
use Cordon\Support\Paths;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

final class ModulesCommand extends Command
{
    private const DOCS = 'https://chrisabner.github.io/Cordon-Modulith/guide/installation#is-cordon-for-my-project';

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

        if (($layers = LayeredLayout::layersIn($modules)) !== []) {
            $this->warn(sprintf(
                'These modules look like the layers of a standard Laravel app (%s), not modules split by feature. Cordon checks the boundaries between feature modules; for rules between layers, a tool like Deptrac fits better: %s',
                implode(', ', $layers),
                self::DOCS,
            ));
        }

        return self::SUCCESS;
    }
}
