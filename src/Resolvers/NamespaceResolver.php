<?php

declare(strict_types=1);

namespace Cordon\Resolvers;

use Cordon\Contracts\ModuleResolver;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Support\Paths;

/**
 * Every direct sub-directory of $path is a module named after it, under $namespace.
 * Example: app/Modules/Billing => App\Modules\Billing.
 */
final readonly class NamespaceResolver implements ModuleResolver
{
    public function __construct(
        private string $path,
        private string $namespace,
    ) {}

    public function resolve(): ModuleMap
    {
        $modules = [];

        foreach (Paths::childDirectories($this->path) as $directory) {
            $name = basename($directory);
            $modules[] = Module::make($name, trim($this->namespace, '\\').'\\'.$name, $directory);
        }

        return new ModuleMap($modules);
    }
}
