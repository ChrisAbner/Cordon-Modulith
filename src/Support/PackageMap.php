<?php

declare(strict_types=1);

namespace Cordon\Support;

use JsonException;
use PhpToken;

/**
 * The Composer packages of a project and the classes each one provides, read
 * from vendor/composer/installed.json as plain JSON: the analysed project's
 * autoloader is never loaded or run.
 *
 * A class belongs to a package only when the package provably provides it,
 * never because a shorter prefix happens to match: PHP's own classes, the
 * project's, those of a package that is not installed (a require-dev package
 * left out by --no-dev) and anything a package merely shares a namespace with
 * belong to none. The index is read the first time it is needed.
 *
 * @phpstan-type Mapping array{prefix: string, dirs: list<string>}
 * @phpstan-type Package array{name: string, psr4: list<Mapping>, psr0: list<Mapping>, classmap: list<string>, replace: list<string>}
 * @phpstan-type Entry array{prefix: string, package: string, dirs: list<string>, psr0: bool}
 *
 * @internal
 */
final class PackageMap
{
    /** Suffixes of the files a classmap entry scans, as Composer does. */
    private const CLASSMAP_EXTENSIONS = ['php', 'inc'];

    /** @var array<string, Package>|null */
    private ?array $packages = null;

    private ?string $problem = null;

    /** @var list<Entry>|null Longest prefix first. */
    private ?array $prefixes = null;

    /** @var array<string, string|null>|null Lowercased class name => package (null when several declare it), from the classmap entries. */
    private ?array $classmap = null;

    /** @var array<string, string|null> */
    private array $resolved = [];

    public function __construct(private readonly string $installedJson, private readonly string $vendorDir) {}

    /**
     * The packages installed under the vendor directory of $basePath (`config.vendor-dir` of its composer.json, or vendor).
     */
    public static function forProject(string $basePath): self
    {
        $vendor = 'vendor';
        $composer = Paths::join($basePath, 'composer.json');

        if (is_file($composer)) {
            $data = json_decode((string) @file_get_contents($composer), true);
            $configured = is_array($data) && is_array($data['config'] ?? null) ? $data['config']['vendor-dir'] ?? null : null;

            if (is_string($configured) && $configured !== '' && ! str_contains($configured, '$')) {
                $vendor = rtrim($configured, '/\\');
            }
        }

        $directory = Paths::isAbsolute($vendor) ? $vendor : Paths::join($basePath, $vendor);

        return new self(Paths::join($directory, 'composer/installed.json'), $directory);
    }

    /**
     * Why the packages cannot be read (a message that states the problem and the fix), or null when they can.
     */
    public function problem(): ?string
    {
        $this->load();

        return $this->problem;
    }

    /**
     * The names of the installed packages, sorted.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->load());
        sort($names);

        return $names;
    }

    /**
     * Whether a package is installed, or replaced by an installed one (laravel/framework replaces illuminate/support).
     */
    public function has(string $name): bool
    {
        $name = strtolower($name);

        foreach ($this->load() as $package) {
            if ($package['name'] === $name || in_array($name, $package['replace'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any of $patterns names $package, or a package it replaces. A
     * pattern is a package name, or a vendor or name prefix ending in *
     * ("illuminate/*"), compared without regard to case.
     *
     * @param  list<string>  $patterns
     */
    public function covers(string $package, array $patterns): bool
    {
        $names = [strtolower($package), ...($this->load()[strtolower($package)]['replace'] ?? [])];

        foreach ($patterns as $pattern) {
            foreach ($names as $name) {
                if (self::matches($name, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a package name matches a pattern of the packages setting.
     */
    public static function matches(string $name, string $pattern): bool
    {
        $pattern = strtolower($pattern);
        $name = strtolower($name);

        return str_ends_with($pattern, '*') ? str_starts_with($name, substr($pattern, 0, -1)) : $name === $pattern;
    }

    /**
     * The installed package that provides a class, or null for a class no
     * installed package provably provides.
     */
    public function packageOf(string $fqcn): ?string
    {
        $fqcn = ltrim($fqcn, '\\');
        $key = strtolower($fqcn);

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        return $this->resolved[$key] = $this->load() === [] || $fqcn === '' ? null : $this->resolve($fqcn);
    }

    private function resolve(string $fqcn): ?string
    {
        $lower = strtolower($fqcn);
        $unverifiable = null;

        foreach ($this->prefixes() as $entry) {
            if (! str_starts_with($lower, $entry['prefix'])) {
                continue;
            }

            $verdict = $this->provides($entry, $fqcn);

            if ($verdict === true) {
                return $entry['package'];
            }

            // The package directory is not on disk (a vendor folder that is not installed here): the prefix is all there is.
            if ($verdict === null && $entry['prefix'] !== '') {
                $unverifiable ??= $entry['package'];
            }
        }

        $classmap = $this->classmap();

        return array_key_exists($lower, $classmap) ? $classmap[$lower] : $unverifiable;
    }

    /**
     * Whether an autoload entry holds the file of a class: true or false, or
     * null when none of its directories exists, so nothing can be checked.
     *
     * @param  Entry  $entry
     */
    private function provides(array $entry, string $fqcn): ?bool
    {
        $exists = false;

        foreach ($entry['dirs'] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $exists = true;

            foreach ($entry['psr0'] ? self::psr0Files($fqcn) : [self::psr4File($fqcn, $entry['prefix'])] as $relative) {
                if (is_file($dir.'/'.$relative)) {
                    return true;
                }
            }
        }

        return $exists ? false : null;
    }

    private static function psr4File(string $fqcn, string $prefix): string
    {
        return str_replace('\\', '/', substr($fqcn, strlen($prefix))).'.php';
    }

    /**
     * The file of a class under PSR-0: the underscores of the class name, not of its namespace, are directories.
     *
     * @return list<string>
     */
    private static function psr0Files(string $fqcn): array
    {
        $position = strrpos($fqcn, '\\');

        if ($position === false) {
            return [str_replace('_', '/', $fqcn).'.php'];
        }

        return [str_replace('\\', '/', substr($fqcn, 0, $position)).'/'.str_replace('_', '/', substr($fqcn, $position + 1)).'.php'];
    }

    /**
     * The psr-4 and psr-0 entries of every package, the longest prefix first.
     *
     * @return list<Entry>
     */
    private function prefixes(): array
    {
        if ($this->prefixes !== null) {
            return $this->prefixes;
        }

        $entries = [];

        foreach ($this->load() as $package) {
            foreach ($package['psr4'] as $entry) {
                $entries[] = ['prefix' => strtolower($entry['prefix']), 'package' => $package['name'], 'dirs' => $entry['dirs'], 'psr0' => false];
            }

            foreach ($package['psr0'] as $entry) {
                $entries[] = ['prefix' => strtolower($entry['prefix']), 'package' => $package['name'], 'dirs' => $entry['dirs'], 'psr0' => true];
            }
        }

        // Longest prefix first, then a fixed order, so the answer never depends on the order of installed.json.
        usort($entries, static fn (array $a, array $b): int => strlen($b['prefix']) <=> strlen($a['prefix']) ?: strcmp($a['package'], $b['package']) ?: (int) $a['psr0'] <=> (int) $b['psr0']);

        return $this->prefixes = $entries;
    }

    /**
     * The classes declared by the files of the classmap entries, read with the
     * tokenizer (nothing is included or run): lowercased name => package, or
     * null for a class that more than one package declares, which no package
     * can be said to provide.
     *
     * @return array<string, string|null>
     */
    private function classmap(): array
    {
        if ($this->classmap !== null) {
            return $this->classmap;
        }

        $map = [];

        foreach ($this->load() as $package) {
            foreach ($package['classmap'] as $path) {
                foreach (self::phpFiles($path) as $file) {
                    foreach (self::declaredClasses($file) as $class) {
                        $key = strtolower($class);
                        $map[$key] = array_key_exists($key, $map) && $map[$key] !== $package['name'] ? null : $package['name'];
                    }
                }
            }
        }

        return $this->classmap = $map;
    }

    /**
     * The PHP files of a classmap entry (a file, or a directory searched
     * recursively). What cannot be read, and a directory that links back to
     * one already searched, is skipped: no file, so no class to attribute.
     *
     * @param  array<string, true>  $visited  The real paths of the directories already searched.
     * @return list<string>
     */
    private static function phpFiles(string $path, array &$visited = []): array
    {
        if (is_file($path)) {
            return [$path];
        }

        $real = is_dir($path) ? realpath($path) : false;

        if ($real === false || isset($visited[$real])) {
            return [];
        }

        $visited[$real] = true;
        $names = is_readable($path) ? @scandir($path) : false;
        $files = [];

        foreach ($names === false ? [] : $names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $child = $path.'/'.$name;

            if (is_dir($child)) {
                array_push($files, ...self::phpFiles($child, $visited));
            } elseif (is_file($child) && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::CLASSMAP_EXTENSIONS, true)) {
                $files[] = $child;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The classes, interfaces, traits and enums a file declares unconditionally
     * at its top level (directly, or inside a namespace block), in a namespace.
     *
     * A declaration inside an if, a function or any other block is conditional:
     * polyfills declare what PHP already has under `if (PHP_VERSION_ID < ...)`,
     * so it is not known to exist. A file that uses the alternative syntax
     * (if: ... endif) is read as declaring nothing, and the classes of the
     * global namespace are left out too: it is where the polyfills of PHP's
     * own classes (Attribute, Normalizer) declare theirs, without a condition.
     *
     * @return list<string>
     */
    private static function declaredClasses(string $file): array
    {
        $code = @file_get_contents($file);

        if ($code === false || ! preg_match('/\b(class|interface|trait|enum)\s/i', $code)) {
            return [];
        }

        try {
            $tokens = array_values(PhpToken::tokenize($code));
        } catch (\Throwable) {
            return [];
        }

        $classes = [];
        $namespace = '';
        $depth = 0;
        // The depth of the code a namespace holds: 1 inside `namespace X { }`, else 0.
        $top = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is([T_ENDIF, T_ENDFOREACH, T_ENDFOR, T_ENDWHILE, T_ENDSWITCH, T_ENDDECLARE])) {
                return [];
            }

            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->is('}')) {
                $depth--;

                // The end of a `namespace X { }` block: what follows is in the global namespace.
                if ($depth === 0 && $top === 1) {
                    [$namespace, $top] = ['', 0];
                }
            } elseif ($token->is(T_NAMESPACE) && $depth === 0) {
                $name = '';

                for ($j = $i + 1; $j < $count && ! $tokens[$j]->is([';', '{']); $j++) {
                    if ($tokens[$j]->is([T_STRING, T_NAME_QUALIFIED])) {
                        $name .= $tokens[$j]->text;
                    }
                }

                $namespace = $name;
                $top = $j < $count && $tokens[$j]->is('{') ? 1 : 0;
            } elseif ($depth === $top && $namespace !== '' && $token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && ! self::isClassConstant($tokens, $i)) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                        continue;
                    }

                    if ($tokens[$j]->is(T_STRING)) {
                        $classes[] = $namespace.'\\'.$tokens[$j]->text;
                    }

                    break;
                }
            }
        }

        return $classes;
    }

    /**
     * Whether a T_CLASS token is Foo::class and not a declaration.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function isClassConstant(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($tokens[$i]->is(T_WHITESPACE)) {
                continue;
            }

            return $tokens[$i]->is(T_DOUBLE_COLON);
        }

        return false;
    }

    /**
     * @return array<string, Package>
     */
    private function load(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $this->packages = [];

        if (! is_file($this->installedJson)) {
            $this->problem = sprintf('Cannot read the installed packages: %s does not exist. Run composer install, which writes it, before cordon:verify.', $this->display());

            return $this->packages;
        }

        try {
            $data = json_decode((string) file_get_contents($this->installedJson), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->problem = sprintf('Cannot read the installed packages: %s is not valid JSON. Run composer install to write it again.', $this->display());

            return $this->packages;
        }

        // Format 2 wraps the list in {"packages": [...]}; format 1 is the list.
        $list = is_array($data) ? ($data['packages'] ?? (array_is_list($data) ? $data : null)) : null;

        if (! is_array($list)) {
            $this->problem = sprintf('Cannot read the installed packages: %s is not a Composer installed.json. Run composer install to write it again.', $this->display());

            return $this->packages;
        }

        foreach ($list as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null) && $entry['name'] !== '') {
                $package = $this->package($entry);
                $this->packages[$package['name']] = $package;
            }
        }

        return $this->packages;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return Package
     */
    private function package(array $entry): array
    {
        $name = strtolower((string) $entry['name']);
        $dir = is_string($entry['install-path'] ?? null)
            ? self::normalise(Paths::isAbsolute($entry['install-path']) ? $entry['install-path'] : dirname($this->installedJson).'/'.$entry['install-path'])
            : self::normalise($this->vendorDir.'/'.$name);
        $autoload = is_array($entry['autoload'] ?? null) ? $entry['autoload'] : [];
        $classmap = [];

        foreach (is_array($autoload['classmap'] ?? null) ? $autoload['classmap'] : [] as $path) {
            if (is_string($path)) {
                $classmap[] = self::normalise($dir.'/'.$path);
            }
        }

        return [
            'name' => $name,
            'psr4' => self::mappings($autoload['psr-4'] ?? null, $dir),
            'psr0' => self::mappings($autoload['psr-0'] ?? null, $dir),
            'classmap' => $classmap,
            'replace' => array_map('strtolower', array_keys(is_array($entry['replace'] ?? null) ? array_filter($entry['replace'], static fn (mixed $_, mixed $key): bool => is_string($key), ARRAY_FILTER_USE_BOTH) : [])),
        ];
    }

    /**
     * @return list<Mapping>
     */
    private static function mappings(mixed $autoload, string $dir): array
    {
        $mappings = [];

        foreach (is_array($autoload) ? $autoload : [] as $prefix => $paths) {
            $dirs = [];

            foreach (is_array($paths) ? $paths : [$paths] as $path) {
                if (is_string($path)) {
                    $dirs[] = self::normalise($dir.'/'.$path);
                }
            }

            $mappings[] = ['prefix' => ltrim((string) $prefix, '\\'), 'dirs' => $dirs];
        }

        return $mappings;
    }

    private function display(): string
    {
        $vendor = basename($this->vendorDir);

        return ($vendor === '' ? 'vendor' : $vendor).'/composer/installed.json';
    }

    /**
     * The path without "." and ".." segments and trailing separators.
     */
    private static function normalise(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = str_starts_with($path, '/') ? '/' : '';
        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..' && $parts !== [] && end($parts) !== '..') {
                array_pop($parts);
            } else {
                $parts[] = $segment;
            }
        }

        return $prefix.implode('/', $parts);
    }
}
