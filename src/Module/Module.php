<?php

declare(strict_types=1);

namespace Cordon\Module;

final readonly class Module
{
    /**
     * @param  list<string>|null  $dependsOn  Declared dependencies; null means "not declared" (not enforced).
     * @param  list<string>  $publicApi  Extra namespaces or classes, relative to the module namespace, exposed as public API.
     */
    public function __construct(
        public string $name,
        public string $namespace,
        public string $path,
        public ?array $dependsOn = null,
        public array $publicApi = [],
        public bool $open = false,
    ) {}

    public static function make(string $name, string $namespace, string $path): self
    {
        return new self($name, trim($namespace, '\\'), rtrim($path, '/\\'));
    }

    public function ownsClass(string $fqcn): bool
    {
        return str_starts_with(ltrim($fqcn, '\\'), $this->namespace.'\\');
    }

    public function ownsPath(string $file): bool
    {
        $file = str_replace('\\', '/', $file);
        $path = rtrim(str_replace('\\', '/', $this->path), '/');

        return str_starts_with($file, $path.'/');
    }

    /**
     * The class name relative to the module namespace, e.g. "Models\Product".
     */
    public function relativeName(string $fqcn): string
    {
        return substr(ltrim($fqcn, '\\'), strlen($this->namespace) + 1);
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    public function withConfig(array $config): self
    {
        $dependsOn = $this->dependsOn;

        if (array_key_exists('depends_on', $config)) {
            $dependsOn = is_array($config['depends_on'])
                ? array_values(array_map('strval', $config['depends_on']))
                : null;
        }

        $publicApi = isset($config['public']) && is_array($config['public'])
            ? array_values(array_map('strval', $config['public']))
            : $this->publicApi;

        return new self(
            $this->name,
            $this->namespace,
            $this->path,
            $dependsOn,
            $publicApi,
            (bool) ($config['open'] ?? $this->open),
        );
    }
}
