<?php

declare(strict_types=1);

namespace Cordon\Module;

/**
 * @api
 */
final readonly class Module
{
    /**
     * @param  list<string>|null  $dependsOn  Declared dependencies; null means "not declared" (not enforced).
     * @param  list<string>  $publicApi  Extra namespaces or classes, relative to the module namespace, exposed as public API.
     * @param  array<array-key, mixed>  $metadata  Free-form information from config/cordon.php (description, owner...).
     * @param  bool  $strict  Its violations never enter the baseline: a clean module stays clean.
     * @param  string|null  $parent  The name of the module this one is nested in; null for a top-level module. A nested module is named "Parent.Child".
     * @param  array<string, list<string>>  $interfaces  Named interfaces: name => class names relative to the module namespace, a trailing * making an entry a prefix (a namespace, or "Data\Price*").
     */
    public function __construct(
        public string $name,
        public string $namespace,
        public string $path,
        public ?array $dependsOn = null,
        public array $publicApi = [],
        public bool $open = false,
        public array $metadata = [],
        public bool $strict = false,
        public ?string $parent = null,
        public array $interfaces = [],
    ) {}

    /** The name of the code outside the modules (host.paths in config/cordon.php). */
    public const HOST = 'host';

    /**
     * The code outside the modules, as the source of host_access violations.
     * It owns no class and is never part of a ModuleMap.
     */
    public static function host(): self
    {
        return new self(self::HOST, '', '');
    }

    public static function make(string $name, string $namespace, string $path, ?string $parent = null): self
    {
        return new self($name, trim($namespace, '\\'), rtrim($path, '/\\'), parent: $parent);
    }

    /**
     * Whether this module is nested, at any depth, in $other ("Billing.Invoices" is nested in "Billing").
     */
    public function isNestedIn(self $other): bool
    {
        return $this->parent !== null && str_starts_with($this->name, $other->name.'.');
    }

    /**
     * Whether depends_on lists $module in any form: whole ("Catalog", "Catalog::*") or through an interface ("Catalog::Pricing").
     */
    public function declares(string $module): bool
    {
        foreach ($this->dependsOn ?? [] as $entry) {
            if (self::entryModule($entry) === $module) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether depends_on lists $module as a whole: "Catalog" or "Catalog::*".
     */
    public function declaresWhole(string $module): bool
    {
        foreach ($this->dependsOn ?? [] as $entry) {
            if (self::entryModule($entry) === $module && self::entryInterface($entry) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The interfaces of $module that depends_on lists, in the order it lists them.
     *
     * @return list<string>
     */
    public function declaredInterfaces(string $module): array
    {
        $names = [];

        foreach ($this->dependsOn ?? [] as $entry) {
            $name = self::entryInterface($entry);

            if ($name !== null && self::entryModule($entry) === $module && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The module of a depends_on entry: "Catalog" for "Catalog", "Catalog::*" and "Catalog::Pricing".
     */
    public static function entryModule(string $entry): string
    {
        return explode('::', $entry, 2)[0];
    }

    /**
     * The named interface of a depends_on entry, or null when the entry is the whole module ("Catalog", "Catalog::*").
     */
    public static function entryInterface(string $entry): ?string
    {
        $parts = explode('::', $entry, 2);

        return isset($parts[1]) && $parts[1] !== '*' ? $parts[1] : null;
    }

    /**
     * The names of the interfaces of this module that a class belongs to (none, one or several).
     *
     * @return list<string>
     */
    public function interfacesOf(string $fqcn): array
    {
        if ($this->interfaces === [] || ! $this->ownsClass($fqcn)) {
            return [];
        }

        $relative = strtolower($this->relativeName($fqcn));
        $names = [];

        foreach ($this->interfaces as $name => $patterns) {
            foreach ($patterns as $pattern) {
                if (self::matchesPattern($relative, $pattern)) {
                    $names[] = (string) $name;

                    break;
                }
            }
        }

        return $names;
    }

    /**
     * Whether a pattern of an interface matches a class. An entry is a class
     * name relative to the module namespace; a trailing * makes it a prefix,
     * so "Data\*" is a namespace and "Data\Price*" every class starting so.
     * Names are compared without regard to case, as PHP does.
     *
     * @param  string  $relative  The class name relative to the module namespace, lowercased.
     */
    public static function matchesPattern(string $relative, string $pattern): bool
    {
        $pattern = strtolower(trim($pattern, '\\'));

        if (str_ends_with($pattern, '*')) {
            return str_starts_with($relative, substr($pattern, 0, -1));
        }

        return $pattern !== '' && $relative === $pattern;
    }

    public function ownsClass(string $fqcn): bool
    {
        return str_starts_with(strtolower(ltrim($fqcn, '\\')), strtolower($this->namespace).'\\');
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
                ? array_values(array_filter($config['depends_on'], 'is_string'))
                : null;
        }

        $publicApi = isset($config['public']) && is_array($config['public'])
            ? array_values(array_filter($config['public'], 'is_string'))
            : $this->publicApi;

        return new self(
            $this->name,
            $this->namespace,
            $this->path,
            $dependsOn,
            $publicApi,
            (bool) ($config['open'] ?? $this->open),
            is_array($config['metadata'] ?? null) ? $config['metadata'] : $this->metadata,
            is_bool($config['strict'] ?? null) ? $config['strict'] : $this->strict,
            $this->parent,
            is_array($config['interfaces'] ?? null) ? self::interfacesFrom($config['interfaces']) : $this->interfaces,
        );
    }

    /**
     * The usable part of an `interfaces` setting: the lists of strings. A wrong shape is a configuration error.
     *
     * @param  array<array-key, mixed>  $setting
     * @return array<string, list<string>>
     */
    private static function interfacesFrom(array $setting): array
    {
        $interfaces = [];

        foreach ($setting as $name => $patterns) {
            if (is_array($patterns)) {
                $interfaces[(string) $name] = array_values(array_filter($patterns, 'is_string'));
            }
        }

        return $interfaces;
    }
}
