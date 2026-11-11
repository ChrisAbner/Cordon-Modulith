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
    private const KEYS = ['resolver', 'resolvers', 'public_namespaces', 'modules', 'host', 'layers', 'rules', 'listeners', 'exclude', 'baseline', 'cache', 'docblocks'];

    private const HOST_KEYS = ['paths', 'tests'];

    private const MODULE_KEYS = ['depends_on', 'public', 'open', 'strict', 'nested', 'interfaces', 'tests', 'metadata'];

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

        if (array_key_exists('cache', $config) && $config['cache'] !== null && (! is_string($config['cache']) || $config['cache'] === '')) {
            $errors[] = 'cache in config/cordon.php must be a file path or null.';
        }

        if (array_key_exists('docblocks', $config) && ! is_bool($config['docblocks'])) {
            $errors[] = 'docblocks in config/cordon.php must be true or false.';
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

            if (array_key_exists('tests', $config['host']) && ! HostPaths::valid($config['host']['tests'])) {
                $errors[] = 'host.tests must be a list of directories, relative to the project root.';
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
                array_push($errors, ...self::moduleErrors((string) $name, $settings, $modules, $config['modules']));
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
     * @param  array<array-key, mixed>  $allSettings  The `modules` config, to read the interfaces other modules define.
     * @return list<string>
     */
    private static function moduleErrors(string $name, mixed $settings, ModuleMap $modules, array $allSettings): array
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
            $target = Module::entryModule($dependency);

            if (! $modules->has($target)) {
                $errors[] = sprintf('Module [%s] declares a dependency on unknown module [%s].', $name, $target).self::didYouMean($target, $modules->names());
            } elseif (($hidden = self::hiddenBehind($modules, $name, $target)) !== null) {
                $errors[] = sprintf('Module [%s] depends on [%s], which is nested in [%s] and so invisible outside it. Depend on [%s] instead.', $name, $dependency, $hidden, $hidden);
            } elseif (str_contains($dependency, '::')) {
                array_push($errors, ...self::interfaceDependencyErrors($name, $dependency, $allSettings));
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

        if (array_key_exists('nested', $settings)) {
            array_push($errors, ...self::nestedErrors($name, $settings['nested'], $modules));
        }

        if (array_key_exists('interfaces', $settings)) {
            array_push($errors, ...self::interfaceErrors($name, $settings['interfaces']));
        }

        if (array_key_exists('tests', $settings) && ! HostPaths::valid($settings['tests'])) {
            $errors[] = sprintf('Module [%s]: tests must be a list of directories, relative to the project root.', $name);
        }

        return $errors;
    }

    /**
     * A depends_on entry "Module::Interface": the interface has to exist in the settings of the module.
     *
     * @param  array<array-key, mixed>  $allSettings
     * @return list<string>
     */
    private static function interfaceDependencyErrors(string $name, string $dependency, array $allSettings): array
    {
        $target = Module::entryModule($dependency);
        $interface = substr($dependency, strlen($target) + 2);

        if ($interface === '*') {
            return [];
        }

        if ($interface === '') {
            return [sprintf('Module [%s] depends on [%s], which names no interface. Write %s::Name, or %s::* for the whole public API.', $name, $dependency, $target, $target)];
        }

        $defined = is_array($allSettings[$target] ?? null) && is_array($allSettings[$target]['interfaces'] ?? null)
            ? array_map('strval', array_keys($allSettings[$target]['interfaces']))
            : [];

        if ($defined === []) {
            return [sprintf('Module [%s] depends on [%s], but module [%s] defines no interfaces. Add interfaces to its settings in config/cordon.php, or depend on [%s].', $name, $dependency, $target, $target)];
        }

        if (in_array($interface, $defined, true)) {
            return [];
        }

        return [sprintf(
            'Module [%s] depends on [%s], but module [%s] defines no interface [%s].%s Interfaces of [%s]: %s.',
            $name,
            $dependency,
            $target,
            $interface,
            self::didYouMean($dependency, array_map(static fn (string $candidate): string => $target.'::'.$candidate, $defined)),
            $target,
            implode(', ', $defined),
        )];
    }

    /**
     * The shape of the `interfaces` setting of a module: a map of name => list of class names or namespaces.
     *
     * @return list<string>
     */
    private static function interfaceErrors(string $module, mixed $interfaces): array
    {
        if (! is_array($interfaces) || ($interfaces !== [] && array_is_list($interfaces))) {
            return [sprintf('Module [%s]: interfaces must be a map of interface name to a list of class names or namespaces.', $module)];
        }

        $errors = [];

        foreach ($interfaces as $name => $patterns) {
            $name = (string) $name;

            if ($name === '*') {
                $errors[] = sprintf('Module [%s]: [*] cannot name an interface, because %s::* already means the whole public API.', $module, $module);
            } elseif ($name === '' || str_contains($name, '::')) {
                $errors[] = sprintf('Module [%s]: the interface name [%s] cannot %s.', $module, $name, $name === '' ? 'be empty' : 'contain "::"');
            }

            if (! is_array($patterns) || ! array_is_list($patterns) || array_filter($patterns, static fn (mixed $pattern): bool => ! is_string($pattern)) !== []) {
                $errors[] = sprintf('Module [%s]: interface [%s] must be a list of class names or namespaces.', $module, $name);

                continue;
            }

            foreach ($patterns as $pattern) {
                if (trim($pattern, '\\') === '') {
                    $errors[] = sprintf('Module [%s] interface [%s]: an empty pattern matches nothing. Write a class name relative to the module, such as Contracts\\PriceList.', $module, $name);
                } elseif (substr_count($pattern, '*') > (str_ends_with($pattern, '*') ? 1 : 0)) {
                    $errors[] = sprintf('Module [%s] interface [%s]: the pattern [%s] may use * only at the end.', $module, $name, $pattern);
                }
            }
        }

        return $errors;
    }

    /**
     * The module that hides $dependency from $name: the one $dependency is
     * nested in that does not contain $name. Null when $name can see it.
     */
    private static function hiddenBehind(ModuleMap $modules, string $name, string $dependency): ?string
    {
        $consumer = $modules->get($name);
        $target = $modules->get($dependency);

        if ($consumer === null || $target === null) {
            return null;
        }

        $visible = $modules->visible($target, $consumer);

        return $visible->name === $target->name ? null : $visible->name;
    }

    /**
     * @return list<string>
     */
    private static function nestedErrors(string $name, mixed $nested, ModuleMap $modules): array
    {
        if (is_bool($nested)) {
            return [];
        }

        if (! is_array($nested) || ! array_is_list($nested) || array_filter($nested, static fn (mixed $folder): bool => ! is_string($folder)) !== []) {
            return [sprintf('Module [%s]: nested must be true or a list of folder names.', $name)];
        }

        $errors = [];

        foreach ($nested as $folder) {
            if (str_contains($folder, '.')) {
                $errors[] = sprintf('Module [%s]: nested folder [%s] contains a dot, which separates the parent from the child in names such as %s.Invoices. Rename the folder.', $name, $folder, $name);
            } elseif (! $modules->has($name.'.'.$folder)) {
                $errors[] = sprintf('Module [%s]: nested lists [%s], but the module has no such folder.', $name, $folder).self::didYouMean($folder, self::folders($modules->get($name)));
            }
        }

        return $errors;
    }

    /**
     * The sub-directories of a module, to suggest the folder a nested entry misspells.
     *
     * @return list<string>
     */
    private static function folders(?Module $module): array
    {
        if ($module === null) {
            return [];
        }

        $names = [];

        foreach ([$module->path, ...array_map(static fn (string $directory): string => Paths::join($module->path, $directory), ['src', 'app'])] as $directory) {
            array_push($names, ...array_map('basename', Paths::childDirectories($directory)));
        }

        return array_values(array_unique($names));
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
