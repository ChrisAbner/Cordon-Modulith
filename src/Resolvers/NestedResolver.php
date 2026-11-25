<?php

declare(strict_types=1);

namespace Cordon\Resolvers;

use Cordon\Contracts\ModuleResolver;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Support\Paths;

/**
 * Adds the modules nested inside other modules to what another resolver
 * found. A module lists its nested ones in config/cordon.php (nested =>
 * ['Invoices', 'Payments'], folders directly under the module, or true for
 * every folder that is not a public namespace). A nested module is named
 * "Parent.Child", lives in the folder and namespace of the child, and may
 * itself have nested modules.
 *
 * Folders that do not exist, or whose name has a dot, are left out here and
 * reported by ConfigValidator.
 *
 * @internal
 */
final readonly class NestedResolver implements ModuleResolver
{
    /**
     * @param  array<array-key, mixed>  $moduleConfig  The `modules` config, by module name.
     * @param  list<string>  $publicNamespaces  Folders named like these are public API, not nested modules, with nested => true.
     * @param  string  $sourceDirectory  The directory of the module's namespace root inside its path, when it has one ("app" for nwidart, "src" for InterNACHI).
     */
    public function __construct(
        private ModuleResolver $inner,
        private array $moduleConfig = [],
        private array $publicNamespaces = ['Contracts', 'Events', 'Data', 'Enums', 'Exceptions'],
        private string $sourceDirectory = '',
    ) {}

    public function resolve(): ModuleMap
    {
        $map = $this->inner->resolve();
        $modules = $map->all();
        $pending = $modules;

        while ($pending !== []) {
            foreach ($this->children(array_shift($pending)) as $child) {
                $modules[] = $child;
                $pending[] = $child;
            }
        }

        return count($modules) === count($map) ? $map : new ModuleMap($modules);
    }

    /**
     * The namespace root of a module: the directory its nested folders live in.
     */
    public static function root(Module $module, string $sourceDirectory = ''): string
    {
        $source = $sourceDirectory === '' ? $module->path : Paths::join($module->path, $sourceDirectory);

        return is_dir($source) ? $source : $module->path;
    }

    /**
     * @return list<Module>
     */
    private function children(Module $parent): array
    {
        $settings = $this->moduleConfig[$parent->name] ?? null;
        $nested = is_array($settings) ? ($settings['nested'] ?? null) : null;
        $root = self::root($parent, $this->sourceDirectory);
        $children = [];

        foreach ($this->folders($nested, $settings, $root) as $folder) {
            $children[$folder] = Module::make(
                $parent->name.'.'.$folder,
                $parent->namespace.'\\'.$folder,
                Paths::join($root, $folder),
                $parent->name,
            );
        }

        return array_values($children);
    }

    /**
     * @return list<string>
     */
    private function folders(mixed $nested, mixed $settings, string $root): array
    {
        if ($nested === true) {
            $public = is_array($settings) && is_array($settings['public'] ?? null) ? array_filter($settings['public'], 'is_string') : [];
            $excluded = array_map(
                static fn (string $entry): string => strtolower(trim($entry, '\\')),
                [...$this->publicNamespaces, ...$public],
            );
            $folders = array_map('basename', Paths::childDirectories($root));

            return array_values(array_filter(
                $folders,
                static fn (string $folder): bool => ! str_contains($folder, '.') && ! in_array(strtolower($folder), $excluded, true),
            ));
        }

        if (! is_array($nested) || ! array_is_list($nested)) {
            return [];
        }

        return array_values(array_filter(
            array_filter($nested, 'is_string'),
            static fn (string $folder): bool => $folder !== '' && ! str_contains($folder, '.') && ! str_contains($folder, '/') && ! str_contains($folder, '\\') && is_dir(Paths::join($root, $folder)),
        ));
    }
}
