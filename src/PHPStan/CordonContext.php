<?php

declare(strict_types=1);

namespace Cordon\PHPStan;

use Cordon\Analysis\ClassDeclaration;
use Cordon\Analysis\DeclaredVisibility;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Attributes\Internal;
use Cordon\Attributes\PublicApi;
use Cordon\Baseline\Baseline;
use Cordon\Laravel\ResolverFactory;
use Cordon\Laravel\StandaloneConfig;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\Severity;
use Cordon\Support\ConfigValidator;
use Cordon\Support\Paths;
use FilesystemIterator;
use InvalidArgumentException;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * What every Cordon rule in PHPStan needs, read once per process from the
 * project's config files (PHPStan does not boot Laravel): modules, public API,
 * baseline, severities and the files the result depends on.
 *
 * @internal
 */
final class CordonContext
{
    private bool $booted = false;

    private ModuleMap $modules;

    private PublicApiPolicy $policy;

    /** @var list<string> */
    private array $exclude = [];

    /** @var array<array-key, mixed> */
    private array $config = [];

    private Baseline $baseline;

    /** @var list<string> */
    private array $trackedFiles = [];

    /** @var list<string> */
    private array $pendingErrors = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly string $basePath,
    ) {}

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function modules(): ModuleMap
    {
        $this->boot();

        return $this->modules;
    }

    public function policy(): PublicApiPolicy
    {
        $this->boot();

        return $this->policy;
    }

    public function baseline(): Baseline
    {
        $this->boot();

        return $this->baseline;
    }

    /**
     * The configured severity of a built-in rule. An invalid value is already
     * reported as a configuration error, and the rule then stays on.
     */
    public function severity(string $rule): Severity
    {
        $this->boot();

        try {
            return Severity::fromConfig($rule, $this->config['rules'][$rule] ?? true);
        } catch (InvalidArgumentException) {
            return Severity::Error;
        }
    }

    /**
     * Whether listener edges count for "cycles" or "depends_on" (the listeners config).
     */
    public function countsListeners(string $for): bool
    {
        $this->boot();
        $listeners = is_array($this->config['listeners'] ?? null) ? $this->config['listeners'] : [];

        return ($listeners[$for] ?? $for === 'depends_on') === true;
    }

    /**
     * The module a file belongs to, or null when it belongs to none or sits in
     * an excluded directory.
     *
     * In editor mode PHPStan analyses the buffer as a temporary file outside
     * the project (--tmp-file), so the path matches no module: the module then
     * comes from the first class the file declares, and excluded directories
     * are checked against its namespace (App\Modules\Billing\Tests\...).
     *
     * @param  list<ClassDeclaration>  $declarations
     */
    public function moduleOf(string $file, array $declarations): ?Module
    {
        $this->boot();
        $module = $this->modules->forPath($file);

        if ($module !== null) {
            return $this->isExcluded($module, $file) ? null : $module;
        }

        $class = $declarations[0]->fqcn ?? null;
        $module = $class !== null ? $this->modules->forClass($class) : null;

        if ($module === null) {
            return null;
        }

        $segments = array_slice(explode('\\', substr($class, strlen($module->namespace) + 1)), 0, -1);

        return array_intersect($segments, $this->exclude) === [] ? $module : null;
    }

    /**
     * The project path of a file, for baseline keys. In editor mode the buffer
     * is a temporary file, so its real path is looked up in its module: the one
     * file named after the class it declares. When there is not exactly one,
     * the path stays as given.
     *
     * @param  list<ClassDeclaration>  $declarations
     */
    public function projectPath(string $file, array $declarations): string
    {
        $this->boot();
        $class = $declarations[0]->fqcn ?? null;

        if ($this->modules->forPath($file) !== null || $class === null || ($module = $this->modules->forClass($class)) === null || ! is_dir($module->path)) {
            return $file;
        }

        $name = substr($class, (int) strrpos($class, '\\') + 1).'.php';
        $matches = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module->path, FilesystemIterator::SKIP_DOTS)) as $candidate) {
            if ($candidate->getFilename() === $name) {
                $matches[] = $candidate->getPathname();
            }
        }

        return count($matches) === 1 ? $matches[0] : $file;
    }

    public function isExcluded(Module $module, string $file): bool
    {
        $this->boot();
        $relative = (string) Paths::relative($module->path, $file);
        $directories = array_slice(explode('/', $relative), 0, -1);

        return array_intersect($directories, $this->exclude) !== [];
    }

    /**
     * Problems with the config and the baseline, returned once per process, so
     * whichever rule runs first reports them and a broken config is not silent.
     *
     * @return list<string>
     */
    public function pullConfigurationErrors(): array
    {
        $this->boot();
        $errors = $this->pendingErrors;
        $this->pendingErrors = [];

        return $errors;
    }

    /**
     * The result depends on files PHPStan does not analyse. Without this, its
     * result cache keeps old errors after the baseline or config change.
     * DependencyTracker exists since PHPStan 2.3; older versions skip this.
     */
    public function trackDependencies(Scope $scope): void
    {
        $this->boot();

        if ($scope instanceof DependencyTracker) {
            foreach ($this->trackedFiles as $tracked) {
                $scope->trackFileDependency($tracked);
            }
        }
    }

    public function declaration(string $class): ?ClassDeclaration
    {
        if (! $this->reflectionProvider->hasClass($class)) {
            return null;
        }

        $native = $this->reflectionProvider->getClass($class)->getNativeReflection();
        $visibility = DeclaredVisibility::Unspecified;

        foreach ($native->getAttributes() as $attribute) {
            $name = ltrim($attribute->getName(), '\\');

            if ($name === Internal::class) {
                $visibility = DeclaredVisibility::Internal;

                break;
            }

            if ($name === PublicApi::class) {
                $visibility = DeclaredVisibility::PublicApi;
            }
        }

        return new ClassDeclaration($class, (string) $native->getFileName(), (int) $native->getStartLine(), $visibility);
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        $config = StandaloneConfig::load($this->basePath);
        $this->pendingErrors = $config->warnings();
        $this->config = (array) $config->get('cordon', []);

        $resolved = ResolverFactory::make($config, $this->basePath)->resolve();
        $this->modules = $resolved->configure((array) $config->get('cordon.modules', []));
        array_push($this->pendingErrors, ...ConfigValidator::errors($this->config, $resolved));

        $this->policy = new PublicApiPolicy(self::strings($config->get('cordon.public_namespaces', [])));
        $this->exclude = self::strings($config->get('cordon.exclude', ['vendor', 'node_modules']));
        $this->baseline = $this->loadBaseline($config->get('cordon.baseline', 'cordon-baseline.json'));
        $this->trackedFiles = [
            $this->baselinePath($config->get('cordon.baseline', 'cordon-baseline.json')),
            ...array_map(fn (string $name): string => Paths::join($this->basePath, 'config/'.$name.'.php'), ['cordon', 'modules', 'app-modules']),
        ];
    }

    /**
     * The same baseline cordon:verify applies. A file that cannot be read is
     * reported once and not applied, rather than hiding every violation.
     */
    private function loadBaseline(mixed $file): Baseline
    {
        $path = $this->baselinePath($file);

        if (! is_file($path)) {
            return Baseline::empty();
        }

        try {
            return Baseline::load($path);
        } catch (Throwable $e) {
            $this->pendingErrors[] = sprintf(
                'Cordon could not read the baseline %s (%s), so it is not applied. Fix the file or regenerate it with cordon:verify --generate-baseline.',
                (string) Paths::relative($this->basePath, $path),
                $e->getMessage(),
            );

            return Baseline::empty();
        }
    }

    private function baselinePath(mixed $file): string
    {
        return Paths::join($this->basePath, is_string($file) && $file !== '' ? $file : 'cordon-baseline.json');
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_map('strval', array_filter((array) $value, 'is_scalar')));
    }
}
