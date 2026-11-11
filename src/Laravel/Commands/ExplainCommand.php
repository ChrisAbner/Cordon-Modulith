<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Analysis\Result;
use Cordon\Laravel\Commands\Concerns\ExploresModules;
use Cordon\Laravel\Verifier;
use Cordon\Rules\ExternalDependencyRule;
use Cordon\Rules\InternalAccessRule;
use Cordon\Rules\Severity;
use Cordon\Support\PackageMap;
use Cordon\Support\Paths;
use Illuminate\Console\Command;

/**
 * @internal
 */
final class ExplainCommand extends Command
{
    use ExploresModules;

    protected $signature = 'cordon:explain
        {class : The fully qualified class name}
        {--format=text : Output format: text or json}';

    protected $description = 'Explain which module a class belongs to, whether other modules may use it and who does';

    public function handle(Verifier $verifier): int
    {
        if ($this->formatFailed() || $this->configurationFailed($verifier)) {
            return self::INVALID;
        }

        $class = ltrim($this->text('class'), '\\');
        $module = $verifier->modules()->forClass($class);

        if ($module === null) {
            if (($package = $this->packageOf($verifier, $class)) !== null) {
                return $this->explainPackageClass($verifier, $class, $package);
            }

            $this->stderr(sprintf('[%s] does not belong to any module.', $class));

            return self::INVALID;
        }

        $snapshot = $verifier->snapshot();
        $declaration = $snapshot->context->symbols->get($class);

        if ($declaration === null) {
            $this->stderr(sprintf('[%s] is not declared in module [%s].', $class, $module->name));

            return self::INVALID;
        }

        $policy = $snapshot->context->policy;
        $public = $policy->isPublic($declaration->fqcn, $module, $declaration);
        $reported = $this->reported($verifier->check($snapshot), $declaration->fqcn);
        $usedBy = [];

        foreach ($snapshot->context->edges as $edge) {
            if (strcasecmp($edge->reference->target, $declaration->fqcn) === 0) {
                $usedBy[] = [
                    'module' => $edge->from->name,
                    'file' => Paths::relative($verifier->basePath(), $edge->reference->sourceFile),
                    'line' => $edge->reference->line,
                    'kind' => $edge->reference->kind,
                    'violation' => isset($reported[$edge->reference->sourceFile]),
                    'severity' => $reported[$edge->reference->sourceFile] ?? null,
                ];
            }
        }

        // The named interfaces of the module, and of the modules it is nested in, that the class belongs to.
        $interfaces = null;

        foreach ($verifier->modules() as $candidate) {
            if ($candidate->interfaces !== [] && $candidate->ownsClass($declaration->fqcn)) {
                $interfaces = [...($interfaces ?? []), ...array_map(static fn (string $name): string => $candidate->name.'::'.$name, $candidate->interfacesOf($declaration->fqcn))];
            }
        }

        $report = [
            'class' => $declaration->fqcn,
            'module' => $module->name,
            'public' => $public,
            'reason' => $policy->reason($declaration->fqcn, $module, $declaration),
            'used_by' => $usedBy,
            ...($interfaces === null ? [] : ['interfaces' => $interfaces]),
        ];

        // A nested module exists only inside its parent: the status above is the one outside it.
        $parent = $verifier->modules()->parentOf($module);

        if ($parent !== null) {
            $report['inside'] = $parent->name;
            $report['public_inside'] = $policy->isPublic($declaration->fqcn, $module, $declaration, $parent);
            $report['reason_inside'] = $policy->reason($declaration->fqcn, $module, $declaration, $parent);
        }

        if ($this->option('format') === 'json') {
            $this->json($report);

            return self::SUCCESS;
        }

        $this->line($report['class']);
        $this->line('  Module:  '.$report['module']);
        $this->line(sprintf('  Status:  %s: %s', $public ? 'public' : 'internal', $report['reason']));

        if ($parent !== null) {
            $this->line(sprintf('  Inside %s:  %s: %s', $parent->name, $report['public_inside'] ? 'public' : 'internal', $report['reason_inside']));
        }

        if ($interfaces !== null) {
            $this->line('  Interfaces:  '.($interfaces === [] ? '(none)' : implode(', ', $interfaces)));
        }

        $this->line('  Used by:'.($usedBy === [] ? ' no other module' : ''));

        foreach ($usedBy as $use) {
            $mark = match ($use['severity']) {
                Severity::Error->value => 'x',
                Severity::Warn->value => '!',
                default => '-',
            };
            $this->line(sprintf('    %s %s  %s:%d (%s)', $mark, $use['module'], $use['file'], $use['line'], $use['kind']));
        }

        return self::SUCCESS;
    }

    /**
     * The installed package that provides a class outside every module. Packages
     * are only looked at when a module lists them: without it a class of
     * a vendor is outside the modules, as it always was.
     */
    private function packageOf(Verifier $verifier, string $class): ?string
    {
        foreach ($verifier->modules() as $module) {
            if ($module->packages !== null) {
                return PackageMap::forProject($verifier->basePath())->packageOf($class);
            }
        }

        return null;
    }

    /**
     * A class of an installed package: the package, and the modules that use it.
     */
    private function explainPackageClass(Verifier $verifier, string $class, string $package): int
    {
        $snapshot = $verifier->snapshot();
        $result = $verifier->check($snapshot);
        $reported = [];

        foreach ([...$result->violations, ...$result->warnings] as $violation) {
            if ($violation->rule === ExternalDependencyRule::ID && $violation->file !== null && $violation->target === $package) {
                $reported[$violation->file] ??= $violation->severity->value;
            }
        }

        $usedBy = [];

        foreach ($snapshot->context->externalReferences as $reference) {
            $module = $snapshot->context->modules->forPath($reference->sourceFile);

            if ($module !== null && strcasecmp($reference->target, $class) === 0) {
                $usedBy[] = [
                    'module' => $module->name,
                    'file' => Paths::relative($verifier->basePath(), $reference->sourceFile),
                    'line' => $reference->line,
                    'kind' => $reference->kind,
                    'violation' => isset($reported[$reference->sourceFile]),
                    'severity' => $reported[$reference->sourceFile] ?? null,
                ];
            }
        }

        if ($this->option('format') === 'json') {
            $this->json(['class' => $class, 'package' => $package, 'used_by' => $usedBy]);

            return self::SUCCESS;
        }

        $this->line($class);
        $this->line('  Package:  '.$package);
        $this->line('  Used by:'.($usedBy === [] ? ' no module' : ''));

        foreach ($usedBy as $use) {
            $mark = match ($use['severity']) {
                Severity::Error->value => 'x',
                Severity::Warn->value => '!',
                default => '-',
            };
            $this->line(sprintf('    %s %s  %s:%d (%s)', $mark, $use['module'], $use['file'], $use['line'], $use['kind']));
        }

        return self::SUCCESS;
    }

    /**
     * The files where cordon:verify reports an internal_access to $class,
     * after the severity and the baseline: file => severity.
     *
     * @return array<string, string>
     */
    private function reported(Result $result, string $class): array
    {
        $files = [];

        foreach ([...$result->violations, ...$result->warnings] as $violation) {
            if ($violation->rule === InternalAccessRule::ID && $violation->file !== null && strcasecmp((string) $violation->target, $class) === 0) {
                $files[$violation->file] ??= $violation->severity->value;
            }
        }

        return $files;
    }
}
