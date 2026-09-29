<?php

declare(strict_types=1);

namespace Cordon\Module;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Module>
 */
final class ModuleMap implements Countable, IteratorAggregate
{
    /** @var array<string, Module> */
    private array $modules = [];

    /**
     * @param  iterable<Module>  $modules
     */
    public function __construct(iterable $modules = [])
    {
        foreach ($modules as $module) {
            $this->modules[$module->name] = $module;
        }

        ksort($this->modules);
    }

    public function get(string $name): ?Module
    {
        return $this->modules[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    /**
     * @return list<Module>
     */
    public function all(): array
    {
        return array_values($this->modules);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (Module $module): string => $module->name, $this->all());
    }

    /**
     * The module owning a class, by longest matching namespace.
     */
    public function forClass(string $fqcn): ?Module
    {
        $match = null;

        foreach ($this->modules as $module) {
            if ($module->ownsClass($fqcn) && ($match === null || strlen($module->namespace) > strlen($match->namespace))) {
                $match = $module;
            }
        }

        return $match;
    }

    /**
     * The module owning a file, by longest matching path.
     */
    public function forPath(string $file): ?Module
    {
        $match = null;

        foreach ($this->modules as $module) {
            if ($module->ownsPath($file) && ($match === null || strlen($module->path) > strlen($match->path))) {
                $match = $module;
            }
        }

        return $match;
    }

    /**
     * Apply per-module settings (depends_on, public, open) from config.
     *
     * @param  array<array-key, mixed>  $config
     */
    public function configure(array $config): self
    {
        $modules = [];

        foreach ($this->modules as $module) {
            $settings = $config[$module->name] ?? null;
            $modules[] = is_array($settings) ? $module->withConfig($settings) : $module;
        }

        return new self($modules);
    }

    /**
     * Human readable problems in the per-module config (unknown module names).
     *
     * @param  array<array-key, mixed>  $config
     * @return list<string>
     */
    public function configurationWarnings(array $config): array
    {
        $warnings = [];

        foreach ($config as $name => $settings) {
            $name = (string) $name;

            if (! $this->has($name)) {
                $warnings[] = sprintf('config/cordon.php references unknown module [%s].', $name);

                continue;
            }

            $dependsOn = is_array($settings) && is_array($settings['depends_on'] ?? null) ? $settings['depends_on'] : [];

            foreach ($dependsOn as $dependency) {
                if (! $this->has((string) $dependency)) {
                    $warnings[] = sprintf('Module [%s] declares a dependency on unknown module [%s].', $name, (string) $dependency);
                }
            }
        }

        return $warnings;
    }

    public function count(): int
    {
        return count($this->modules);
    }

    /**
     * @return Traversable<int, Module>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }
}
