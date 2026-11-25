<?php

declare(strict_types=1);

namespace Cordon\Module;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Module>
 *
 * @api
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
     * The module a nested module is nested in, or null for a top-level module.
     */
    public function parentOf(Module $module): ?Module
    {
        return $module->parent === null ? null : $this->get($module->parent);
    }

    /**
     * The top-level module a module belongs to: itself when it is not nested.
     */
    public function topLevel(Module $module): Module
    {
        while (($parent = $this->parentOf($module)) !== null) {
            $module = $parent;
        }

        return $module;
    }

    /**
     * The module $consumer sees when it uses a class of $target. A nested
     * module exists only inside its parent: from outside, a class of
     * Billing.Invoices is a class of Billing. Without a consumer, or for the
     * code outside the modules, every nested module is seen from outside.
     */
    public function visible(Module $target, ?Module $consumer): Module
    {
        while (($parent = $this->parentOf($target)) !== null && ! $this->isInside($consumer, $parent)) {
            $target = $parent;
        }

        return $target;
    }

    /**
     * Whether $module is $parent or nested in it, at any depth.
     */
    public function isInside(?Module $module, Module $parent): bool
    {
        return $module !== null && ($module->name === $parent->name || $module->isNestedIn($parent));
    }

    /**
     * The modules with every nested module right after its parent, for listings.
     *
     * @return list<Module>
     */
    public function hierarchy(): array
    {
        $children = [];

        foreach ($this->modules as $module) {
            if ($this->parentOf($module) !== null) {
                $children[(string) $module->parent][] = $module;
            }
        }

        $ordered = [];
        $add = function (Module $module) use (&$add, &$ordered, $children): void {
            $ordered[] = $module;

            foreach ($children[$module->name] ?? [] as $child) {
                $add($child);
            }
        };

        foreach ($this->modules as $module) {
            if ($this->parentOf($module) === null) {
                $add($module);
            }
        }

        return $ordered;
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
