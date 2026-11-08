<?php

declare(strict_types=1);

namespace Cordon\Support;

use Cordon\Module\ModuleMap;

/**
 * Checks config/cordon.php before anything is analysed, so a typo fails
 * instead of silently changing what is checked: a misspelled depends_on
 * would leave the module's dependencies open and CI green.
 *
 * The values of `rules` are checked where the rules are built (Severity).
 */
final class ConfigValidator
{
    private const KEYS = ['resolver', 'resolvers', 'public_namespaces', 'modules', 'rules', 'listeners', 'exclude', 'baseline'];

    private const MODULE_KEYS = ['depends_on', 'public', 'open', 'metadata'];

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

        foreach (['modules', 'rules', 'listeners'] as $key) {
            if (array_key_exists($key, $config) && ! is_array($config[$key])) {
                $errors[] = sprintf('%s in config/cordon.php must be an array.', $key);
            }
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
    private static function moduleErrors(string $name, mixed $settings, ModuleMap $modules): array
    {
        if (! $modules->has($name)) {
            return [sprintf('config/cordon.php configures unknown module [%s].', $name).self::suggestion($name, $modules->names())];
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

        if (! is_array($dependsOn) && $dependsOn !== null) {
            $errors[] = sprintf('Module [%s]: depends_on must be a list of module names, or null to leave dependencies open.', $name);
        }

        foreach (is_array($dependsOn) ? $dependsOn : [] as $dependency) {
            $dependency = (string) $dependency;

            if (str_contains($dependency, '::')) {
                $errors[] = sprintf('Module [%s] depends on [%s]: the Module::Interface syntax is reserved for named interfaces in a later version.', $name, $dependency);
            } elseif (! $modules->has($dependency)) {
                $errors[] = sprintf('Module [%s] declares a dependency on unknown module [%s].', $name, $dependency).self::suggestion($dependency, $modules->names());
            }
        }

        if (array_key_exists('public', $settings) && ! is_array($settings['public'])) {
            $errors[] = sprintf('Module [%s]: public must be a list of namespaces or classes.', $name);
        }

        if (array_key_exists('open', $settings) && ! is_bool($settings['open'])) {
            $errors[] = sprintf('Module [%s]: open must be true or false.', $name);
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
        return sprintf('Unknown key [%s] %s.%s Valid keys: %s.', $key, $where, self::suggestion($key, $valid), implode(', ', $valid));
    }

    /**
     * " Did you mean [x]?" for the closest candidate within two edits, or an empty string.
     *
     * @param  list<string>  $candidates
     */
    private static function suggestion(string $name, array $candidates): string
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
