<?php

declare(strict_types=1);

namespace Cordon\Laravel;

use Cordon\Contracts\ModuleResolver;
use Cordon\Resolvers\InternachiResolver;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Resolvers\NwidartResolver;
use Cordon\Support\Paths;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final class ResolverFactory
{
    public static function make(Repository $config, string $basePath): ModuleResolver
    {
        $driver = self::driver($config, $basePath);

        return match ($driver) {
            'namespace' => new NamespaceResolver(
                Paths::join($basePath, self::string($config->get('cordon.resolvers.namespace.path'), 'app/Modules')),
                self::string($config->get('cordon.resolvers.namespace.namespace'), 'App\\Modules'),
            ),
            'nwidart' => new NwidartResolver(
                self::nwidartPath($config, $basePath),
                self::string($config->get('cordon.resolvers.nwidart.namespace') ?? $config->get('modules.namespace'), 'Modules'),
            ),
            'internachi' => new InternachiResolver(self::internachiPath($config, $basePath)),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown Cordon resolver [%s]. Use auto, namespace, nwidart or internachi.',
                $driver,
            )),
        };
    }

    /**
     * The configured resolver, with "auto" replaced by the detected one.
     */
    public static function driver(Repository $config, string $basePath): string
    {
        $driver = self::string($config->get('cordon.resolver'), 'auto');

        if ($driver !== 'auto') {
            return $driver;
        }

        if (NwidartResolver::detect(self::nwidartPath($config, $basePath))) {
            return 'nwidart';
        }

        if (is_dir(self::internachiPath($config, $basePath))) {
            return 'internachi';
        }

        return 'namespace';
    }

    private static function nwidartPath(Repository $config, string $basePath): string
    {
        $path = $config->get('cordon.resolvers.nwidart.path') ?? $config->get('modules.paths.modules');

        return Paths::join($basePath, self::string($path, 'Modules'));
    }

    private static function internachiPath(Repository $config, string $basePath): string
    {
        $path = $config->get('cordon.resolvers.internachi.path') ?? $config->get('app-modules.modules_directory');

        return Paths::join($basePath, self::string($path, 'app-modules'));
    }

    private static function string(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
