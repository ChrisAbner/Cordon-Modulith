<?php

declare(strict_types=1);

namespace Cordon\Support;

/**
 * The directories of the code outside the modules (host.paths in
 * config/cordon.php): controllers, Filament resources, routes, migrations.
 *
 * @internal
 */
final class HostPaths
{
    public const DEFAULT = ['app', 'routes', 'database'];

    /**
     * The configured paths as written, or the default when host.paths is not
     * set. An invalid value gives no paths (ConfigValidator reports it).
     *
     * @param  array<array-key, mixed>  $config  The whole `cordon` config array.
     * @return list<string>
     */
    public static function configured(array $config): array
    {
        $host = $config['host'] ?? [];

        if (! is_array($host) || ! array_key_exists('paths', $host)) {
            return is_array($host) ? self::DEFAULT : [];
        }

        return self::valid($host['paths']) ? $host['paths'] : [];
    }

    /**
     * The configured paths that exist, as absolute directories.
     *
     * @param  array<array-key, mixed>  $config
     * @return list<string>
     */
    public static function absolute(array $config, string $basePath): array
    {
        $paths = array_map(static fn (string $path): string => Paths::join($basePath, $path), self::configured($config));

        return array_values(array_filter($paths, 'is_dir'));
    }

    /**
     * @phpstan-assert-if-true list<string> $paths
     */
    public static function valid(mixed $paths): bool
    {
        return is_array($paths) && array_is_list($paths) && array_filter($paths, static fn (mixed $path): bool => ! is_string($path)) === [];
    }
}
