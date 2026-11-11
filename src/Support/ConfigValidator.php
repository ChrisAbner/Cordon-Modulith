<?php

declare(strict_types=1);

namespace Cordon\Support;

use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\RuleSet;

/**
 * Checks config/cordon.php before anything is analysed, so a typo fails
 * instead of silently changing what is checked: a misspelled depends_on
 * would leave the module's dependencies open and CI green.
 *
 * @internal
 */
final class ConfigValidator
{
    private const KEYS = ['resolver', 'resolvers', 'public_namespaces', 'modules', 'host', 'layers', 'rules', 'listeners', 'exclude', 'baseline'];

    private const HOST_KEYS = ['paths'];

    private const MODULE_KEYS = ['depends_on', 'public', 'open', 'strict', 'metadata'];

    private const LISTENER_KEYS = ['cycles', 'depends_on'];

    /**
     * @param  array<array-key, mixed>  $config  The whole `cordon` config array.
     * @return list<string>
     */
    public static function errors(array $config, ModuleMap $modules): array
    {
        $errors = [];

        foreach (array_keys($config) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                $errors[] = self::unknownKey((string) $key, 'in config/cordon.php', self::KEYS);
            }
        }

        foreach (['modules', 'host', 'rules', 'listeners'] as $key) {
            if (array_key_exists($key, $config) && ! is_array($config[$key])) {
                $errors[] = sprintf('%s in config/cordon.php must be an array.', $key);
            }
        }

        if (is_array($config['rules'] ?? null)) {
            array_push($errors, ...RuleSet::errors($config['rules']));
        }

        if (is_array($config['listeners'] ?? null)) {
            foreach ($config['listeners'] as $key => $value) {
                if (! in_array($key, self::LISTENER_KEYS, true)) {
                    $errors[] = self::unknownKey((string) $key, 'in listeners', self::LISTENER_KEYS);
                } elseif (! is_bool($value)) {
                    $errors[] = sprintf('listeners.%s must be true or false.', $key);
                }
            }
        }

        if (is_array($config['host'] ?? null)) {
            foreach (array_keys($config['host']) as $key) {
                if (! in_array($key, self::HOST_KEYS, true)) {
                    $errors[] = self::unknownKey((string) $key, 'in host', self::HOST_KEYS);
                }
            }

            if (array_key_exists('paths', $config['host']) && ! HostPaths::valid($config['host']['paths'])) {
                $errors[] = 'host.paths must be a list of directories, relative to the project root.';
            }
        }

        // Without modules (a wrong resolver path), every configured module would
        // be unknown: only the keys that do not name modules are checked.
        if ($modules->count() === 0) {
            return $errors;
        }

        if ($modules->has(Module::HOST) && HostPaths::configured($config) !== []) {
            $errors[] = sprintf('A module cannot be named [%s] while host.paths is set: the name is reserved for the code outside the modules. Rename the module or set host.paths to [].', Module::HOST);
        }

        if (array_key_exists('layers', $config)) {
            array_push($errors, ...self::layerErrors($config['layers'], $modules));
        }

        if (is_array($config['modules'] ?? null)) {
            foreach ($config['modules'] as $name => $settings) {
                array_push($errors, ...self::moduleErrors((string) $name, $settings, $modules));
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function layerErrors(mixed $layers, ModuleMap $modules): array
    {
        $shape = 'layers must be a list of layers from the top down, each a list of module names.';

        if (! is_array($layers) || ! array_is_list($layers)) {
            return [$shape];
        }

        $errors = [];
        $seen = [];

        foreach ($layers as $index => $layer) {
            if (! is_array($layer) || ! array_is_list($layer) || array_filter($layer, static fn (mixed $module): bool => ! is_string($module)) !== []) {
                return [$shape];
            }

            if ($layer === []) {
                $errors[] = sprintf('Layer %d in layers is empty.', $index + 1);
            }

            foreach ($layer as $module) {
                if (! $modules->has($module)) {
                    $errors[] = sprintf('layers lists unknown module [%s].%s', $module, self::didYouMean($module, $modules->names()));
                } elseif (isset($seen[$module]) && $seen[$module] !== $index + 1) {
                    $errors[] = sprintf('Module [%s] is in layers %d and %d; a module belongs to one layer.', $module, $seen[$module], $index + 1);
                }

                $seen[$module] ??= $index + 1;
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function moduleErrors(string $name, mixed $settings, ModuleMap $modules): array
    {
        if (! $modules->has($name)) {
            return [sprintf('config/cordon.php configures unknown module [%s].', $name).self::didYouMean($name, $modules->names())];
        }

        if (! is_array($settings)) {
            return [sprintf('Module [%s]: its settings must be an array.', $name)];
        }

        $errors = [];

        foreach (array_keys($settings) as $key) {
            if (! in_array($key, self::MODULE_KEYS, true)) {
                $errors[] = self::unknownKey((string) $key, sprintf('for module [%s]', $name), self::MODULE_KEYS);
            }
        }

        $dependsOn = $settings['depends_on'] ?? null;

        if ($dependsOn !== null && (! is_array($dependsOn) || array_filter($dependsOn, static fn (mixed $dependency): bool => ! is_string($dependency)) !== [])) {
            $errors[] = sprintf('Module [%s]: depends_on must be a list of module names, or null to leave dependencies open.', $name);
        }

        foreach (is_array($dependsOn) ? array_filter($dependsOn, 'is_string') : [] as $dependency) {
            if (str_contains($dependency, '::')) {
                $errors[] = sprintf('Module [%s] depends on [%s]: the Module::Interface syntax is reserved for named interfaces in a later version.', $name, $dependency);
            } elseif (! $modules->has($dependency)) {
                $errors[] = sprintf('Module [%s] declares a dependency on unknown module [%s].', $name, $dependency).self::didYouMean($dependency, $modules->names());
            }
        }

        if (array_key_exists('public', $settings) && (! is_array($settings['public']) || array_filter($settings['public'], static fn (mixed $entry): bool => ! is_string($entry)) !== [])) {
            $errors[] = sprintf('Module [%s]: public must be a list of namespaces or classes.', $name);
        }

        if (array_key_exists('open', $settings) && ! is_bool($settings['open'])) {
            $errors[] = sprintf('Module [%s]: open must be true or false.', $name);
        }

        if (array_key_exists('strict', $settings) && ! is_bool($settings['strict'])) {
            $errors[] = sprintf('Module [%s]: strict must be true or false.', $name);
        }

        if (array_key_exists('metadata', $settings) && ! is_array($settings['metadata'])) {
            $errors[] = sprintf('Module [%s]: metadata must be an array.', $name);
        }

        return $errors;
    }

    /**
     * @param  list<string>  $valid
     */
    private static function unknownKey(string $key, string $where, array $valid): string
    {
        return sprintf('Unknown key [%s] %s.%s Valid keys: %s.', $key, $where, self::didYouMean($key, $valid), implode(', ', $valid));
    }

    /**
     * " Did you mean [x]?" for the closest candidate within two edits, or an empty string.
     *
     * @param  list<string>  $candidates
     */
    public static function didYouMean(string $name, array $candidates): string
    {
        $best = null;
        $distance = 3;

        foreach ($candidates as $candidate) {
            $d = levenshtein(strtolower($name), strtolower($candidate));

            if ($d < $distance) {
                [$best, $distance] = [$candidate, $d];
            }
        }

        return $best === null ? '' : sprintf(' Did you mean [%s]?', $best);
    }
}
