<?php

declare(strict_types=1);

namespace Cordon\Resolvers;

use Cordon\Contracts\ModuleResolver;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Support\Paths;
use JsonException;

/**
 * InterNACHI/modular: every directory with a composer.json is a module; its
 * namespace comes from the PSR-4 autoload entry (preferably the one mapped to src/).
 * Example: app-modules/billing => Modules\Billing (module name "billing").
 */
final readonly class InternachiResolver implements ModuleResolver
{
    public function __construct(private string $path) {}

    public function resolve(): ModuleMap
    {
        $modules = [];

        foreach (Paths::childDirectories($this->path) as $directory) {
            $namespace = $this->namespaceFrom($directory.DIRECTORY_SEPARATOR.'composer.json');

            if ($namespace !== null) {
                $modules[] = Module::make(basename($directory), $namespace, $directory);
            }
        }

        return new ModuleMap($modules);
    }

    private function namespaceFrom(string $composerFile): ?string
    {
        if (! is_file($composerFile)) {
            return null;
        }

        try {
            $composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $psr4 = is_array($composer) ? ($composer['autoload']['psr-4'] ?? null) : null;

        if (! is_array($psr4) || $psr4 === []) {
            return null;
        }

        foreach ($psr4 as $namespace => $directory) {
            if (is_string($directory) && trim($directory, '/') === 'src') {
                return trim((string) $namespace, '\\');
            }
        }

        return trim((string) array_key_first($psr4), '\\');
    }
}
