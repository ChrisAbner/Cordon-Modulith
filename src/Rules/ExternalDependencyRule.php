<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Support\PackageMap;

/**
 * A module may only use the Composer packages it lists in `packages`, plus
 * the shared ones. A module without `packages` is not restricted, and a
 * nested module without its own list takes the one of the module it is in.
 * One violation per file and package.
 *
 * Only classes an installed package provably provides count: PHP's own
 * classes, the project's and those of a package that is not installed belong
 * to no package, and are never reported. References of every kind count
 * (code, docblocks, listeners); the code outside the modules is not checked.
 *
 * @internal
 */
final class ExternalDependencyRule implements Rule
{
    public const ID = 'external_dependency';

    /**
     * @param  list<string>  $shared  Packages every module may use (packages.shared).
     */
    public function __construct(private readonly PackageMap $packages, private readonly array $shared = []) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(AnalysisContext $context): iterable
    {
        if (! $this->restricts($context->modules) || $this->packages->problem() !== null) {
            return;
        }

        $reported = [];
        /** @var array<string, array{0: Module, 1: list<string>}|null> $allowed The module of each file and what it may use. */
        $allowed = [];

        foreach ($context->externalReferences as $reference) {
            $file = $reference->sourceFile;

            if (! array_key_exists($file, $allowed)) {
                $module = $context->modules->forPath($file);
                $own = $module === null ? null : self::listed($context->modules, $module);
                $allowed[$file] = $module === null || $own === null ? null : [$module, [...$this->shared, ...$own]];
            }

            if ($allowed[$file] === null) {
                continue;
            }

            [$module, $patterns] = $allowed[$file];
            $package = $this->packages->packageOf($reference->target);
            $key = $file.'|'.$package;

            if ($package === null || isset($reported[$key]) || $this->packages->covers($package, $patterns)) {
                continue;
            }

            $reported[$key] = true;

            yield new Violation(
                self::ID,
                sprintf('Module [%s] uses %s from [%s], which is not in its packages.', $module->name, ltrim($reference->target, '\\'), $package),
                $file,
                $reference->line,
                $module->name,
                null,
                $package,
            );
        }
    }

    /**
     * Whether any module lists packages: without it the rule has nothing to check.
     */
    private function restricts(ModuleMap $modules): bool
    {
        foreach ($modules as $module) {
            if ($module->packages !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The packages a module lists, or those of the nearest module it is nested in; null when none lists any.
     *
     * @return list<string>|null
     */
    public static function listed(ModuleMap $modules, Module $module): ?array
    {
        for ($current = $module; $current !== null; $current = $modules->parentOf($current)) {
            if ($current->packages !== null) {
                return $current->packages;
            }
        }

        return null;
    }
}
