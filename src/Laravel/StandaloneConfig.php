<?php

declare(strict_types=1);

namespace Cordon\Laravel;

use ArrayAccess;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Configuration for tools that run outside a booted Laravel application,
 * such as the PHPStan rule: config/cordon.php merged over the package
 * defaults, plus config/modules.php and config/app-modules.php when they
 * can be loaded without the framework.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class StandaloneConfig implements ArrayAccess, Repository
{
    /**
     * @param  array<string, mixed>  $items
     */
    public function __construct(private array $items = []) {}

    public static function load(string $basePath): self
    {
        $defaults = self::requireArray(dirname(__DIR__, 2).'/config/cordon.php') ?? [];
        $cordon = self::requireArray($basePath.'/config/cordon.php') ?? [];

        $items = ['cordon' => array_replace($defaults, $cordon)];

        foreach (['modules', 'app-modules'] as $name) {
            $config = self::requireArray($basePath.'/config/'.$name.'.php');

            if ($config !== null) {
                $items[$name] = $config;
            }
        }

        return new self($items);
    }

    public function has($key): bool
    {
        return Arr::has($this->items, $key);
    }

    /**
     * @param  array<array-key, string>|string  $key
     */
    public function get($key, $default = null): mixed
    {
        if (is_array($key)) {
            return array_map(fn ($k) => Arr::get($this->items, $k), $key);
        }

        return Arr::get($this->items, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @param  array<array-key, mixed>|string  $key
     */
    public function set($key, $value = null): void
    {
        $values = is_array($key) ? $key : [$key => $value];

        foreach ($values as $k => $v) {
            Arr::set($this->items, (string) $k, $v);
        }
    }

    public function prepend($key, $value): void
    {
        $array = (array) $this->get($key, []);
        array_unshift($array, $value);
        $this->set($key, $array);
    }

    public function push($key, $value): void
    {
        $array = (array) $this->get($key, []);
        $array[] = $value;
        $this->set($key, $array);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->set((string) $offset, null);
    }

    /**
     * Config files may call framework helpers (base_path, env...). When they
     * cannot be loaded here, the resolvers fall back to their defaults.
     *
     * @return array<string, mixed>|null
     */
    private static function requireArray(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }

        try {
            $config = (static fn (): mixed => require $file)();
        } catch (Throwable) {
            return null;
        }

        return is_array($config) ? $config : null;
    }
}
