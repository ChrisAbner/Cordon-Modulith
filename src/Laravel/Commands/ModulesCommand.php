<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Contracts\ModuleResolver;
use Cordon\Laravel\ResolverFactory;
use Cordon\Module\LayeredLayout;
use Cordon\Module\Module;
use Cordon\Support\ConfigValidator;
use Cordon\Support\HostPaths;
use Cordon\Support\Paths;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * @internal
 */
final class ModulesCommand extends Command
{
    private const DOCS = 'https://chrisabner.github.io/Cordon-Modulith/guide/installation#is-cordon-for-my-project';

    protected $signature = 'cordon:modules';

    protected $description = 'List the modules Cordon detected and their boundary settings';

    public function handle(ModuleResolver $resolver, Repository $config): int
    {
        $basePath = $this->laravel->basePath();
        $resolved = $resolver->resolve();
        $modules = $resolved->configure((array) $config->get('cordon.modules', []));

        $this->line(sprintf('Resolver: <info>%s</info>', ResolverFactory::driver($config, $basePath)));

        // Listing modules is how a broken config gets fixed, so errors are shown, not fatal.
        foreach (ConfigValidator::errors((array) $config->get('cordon', []), $resolved) as $error) {
            $this->warn($error);
        }

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

        $host = HostPaths::configured((array) $config->get('cordon', []));

        if ($host !== []) {
            $this->line(sprintf('Code outside the modules (host): %s', implode(', ', $host)));
        }

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
