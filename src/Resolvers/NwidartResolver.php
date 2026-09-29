<?php

declare(strict_types=1);

namespace Cordon\Resolvers;

use Cordon\Contracts\ModuleResolver;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Support\Paths;

/**
 * nwidart/laravel-modules: every directory with a module.json is a module.
 * Example: Modules/Blog (with app/, routes/, ...) => Modules\Blog.
 */
final readonly class NwidartResolver implements ModuleResolver
{
    public function __construct(
        private string $path,
        private string $namespace = 'Modules',
    ) {}

    public function resolve(): ModuleMap
    {
        $modules = [];

        foreach (Paths::childDirectories($this->path) as $directory) {
            if (! is_file($directory.DIRECTORY_SEPARATOR.'module.json')) {
                continue;
            }

            $name = basename($directory);
            $modules[] = Module::make($name, trim($this->namespace, '\\').'\\'.$name, $directory);
        }

        return new ModuleMap($modules);
    }

    public static function detect(string $path): bool
    {
        foreach (Paths::childDirectories($path) as $directory) {
            if (is_file($directory.DIRECTORY_SEPARATOR.'module.json')) {
                return true;
            }
        }

        return false;
    }
}
