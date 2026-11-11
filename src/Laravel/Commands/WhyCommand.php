<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Analysis\Reference;
use Cordon\Analysis\Result;
use Cordon\Laravel\Commands\Concerns\ExploresModules;
use Cordon\Laravel\Verifier;
use Cordon\Module\Module;
use Cordon\Rules\ExternalDependencyRule;
use Cordon\Rules\UndeclaredDependencyRule;
use Cordon\Support\ConfigValidator;
use Cordon\Support\PackageMap;
use Cordon\Support\Paths;
use Illuminate\Console\Command;

/**
 * @internal
 */
final class WhyCommand extends Command
{
    use ExploresModules;

    protected $signature = 'cordon:why
        {from : The module that depends}
        {to : The module it depends on, or a Composer package (vendor/name) it uses}
        {--format=text : Output format: text or json}';

    protected $description = 'List the references that make one module depend on another module or on a package, and whether depends_on or packages allows it';

    public function handle(Verifier $verifier): int
    {
        if ($this->formatFailed() || $this->configurationFailed($verifier)) {
            return self::INVALID;
        }

        $from = $this->module($verifier, $this->text('from'));

        // A module name has no slash, a package name always has one.
        if ($from !== null && str_contains($this->text('to'), '/')) {
            return $this->whyPackage($verifier, $from, $this->text('to'));
        }

        $to = $from === null ? null : $this->module($verifier, $this->text('to'));

        if ($from === null || $to === null) {
            return self::INVALID;
        }

        $snapshot = $verifier->snapshot();
        $references = [];

        foreach ($snapshot->context->edges as $edge) {
            if ($edge->from->name === $from->name && $snapshot->context->modules->visible($edge->to, $edge->from)->name === $to->name) {
                $references[] = [
                    'file' => Paths::relative($verifier->basePath(), $edge->reference->sourceFile),
                    'line' => $edge->reference->line,
                    'class' => $edge->reference->target,
                    'kind' => $edge->reference->kind,
                ];
            }
        }

        $allowed = $from->dependsOn === null ? null : $from->declares($to->name);
        $reported = $this->reports($verifier->check($snapshot), $from, $to);
        $uncountedListeners = $references !== [] && $this->onlyListeners($references) && config('cordon.listeners.depends_on', true) === false;
        $reason = match (true) {
            $references === [] => sprintf('%s does not depend on %s.', $from->name, $to->name),
            $allowed === null => sprintf('%s does not declare depends_on, so it may depend on any module.', $from->name),
            $allowed && $reported => sprintf('%s lists %s in depends_on only through %s, and cordon:verify reports undeclared_dependency for references outside them.', $from->name, $to->name, implode(', ', array_map(static fn (string $interface): string => $to->name.'::'.$interface, $from->declaredInterfaces($to->name)))),
            $allowed => sprintf('%s lists %s in depends_on.', $from->name, $to->name),
            $reported => sprintf('%s does not list %s in depends_on: cordon:verify reports it as undeclared_dependency.', $from->name, $to->name),
            $this->reports($verifier->check($snapshot, baseline: false), $from, $to) => sprintf('%s does not list %s in depends_on, but cordon:verify does not report it: the baseline records it.', $from->name, $to->name),
            $uncountedListeners => sprintf('%s does not list %s in depends_on, but the references are listeners, which depends_on does not count (listeners.depends_on is false).', $from->name, $to->name),
            default => sprintf('%s does not list %s in depends_on, but cordon:verify does not report it: undeclared_dependency is off.', $from->name, $to->name),
        };

        // depends_on does not count these references, so it allows them.
        if ($allowed === false && $uncountedListeners) {
            $allowed = true;
        }

        if ($this->option('format') === 'json') {
            $this->json(['from' => $from->name, 'to' => $to->name, 'references' => $references, 'allowed' => $allowed, 'reported' => $reported, 'reason' => $reason]);

            return self::SUCCESS;
        }

        if ($references === []) {
            $this->line(sprintf('%s does not depend on %s.', $from->name, $to->name));

            return self::SUCCESS;
        }

        $this->line(sprintf('%s -> %s: %d %s', $from->name, $to->name, count($references), count($references) === 1 ? 'reference' : 'references'));

        foreach ($references as $reference) {
            $this->line(sprintf('  %s:%d  %s (%s)', $reference['file'], $reference['line'], $reference['class'], $reference['kind']));
        }

        $this->line($reason);

        return self::SUCCESS;
    }

    /**
     * The references of a module to the classes of a package, and whether packages allows them.
     */
    private function whyPackage(Verifier $verifier, Module $from, string $package): int
    {
        $package = strtolower($package);
        $packages = PackageMap::forProject($verifier->basePath());

        if (($problem = $packages->problem()) !== null) {
            $this->stderr($problem);

            return self::INVALID;
        }

        if (! $packages->has($package)) {
            $this->stderr(sprintf('Unknown package [%s].%s Run composer install if it should be installed.', $package, ConfigValidator::didYouMean($package, $packages->names())));

            return self::INVALID;
        }

        $snapshot = $verifier->snapshot();
        $references = [];

        foreach ($snapshot->context->externalReferences as $reference) {
            $used = $packages->packageOf($reference->target);

            if ($used !== null && $snapshot->context->modules->forPath($reference->sourceFile)?->name === $from->name && $packages->covers($used, [$package])) {
                $references[] = [
                    'file' => Paths::relative($verifier->basePath(), $reference->sourceFile),
                    'line' => $reference->line,
                    'class' => $reference->target,
                    'kind' => $reference->kind,
                ];
            }
        }

        $shared = array_values(array_filter((array) config('cordon.packages.shared', []), 'is_string'));
        $own = ExternalDependencyRule::listed($snapshot->context->modules, $from);
        $allowed = $own === null ? null : $packages->covers($package, [...$shared, ...$own]);
        $reported = $this->reportsPackage($verifier->check($snapshot), $packages, $from, $package);
        $reason = match (true) {
            $references === [] => sprintf('%s does not use %s.', $from->name, $package),
            $allowed === null => sprintf('%s does not list packages, so it may use any package.', $from->name),
            $packages->covers($package, $own) => sprintf('%s lists %s in packages.', $from->name, $package),
            $allowed => sprintf('%s is in packages.shared, so every module may use it.', $package),
            $reported => sprintf('%s does not list %s in packages: cordon:verify reports it as external_dependency.', $from->name, $package),
            $this->reportsPackage($verifier->check($snapshot, baseline: false), $packages, $from, $package) => sprintf('%s does not list %s in packages, but cordon:verify does not report it: the baseline records it.', $from->name, $package),
            default => sprintf('%s does not list %s in packages, but cordon:verify does not report it: external_dependency is off.', $from->name, $package),
        };

        if ($this->option('format') === 'json') {
            $this->json(['from' => $from->name, 'to' => $package, 'references' => $references, 'allowed' => $allowed, 'reported' => $reported, 'reason' => $reason]);

            return self::SUCCESS;
        }

        if ($references === []) {
            $this->line($reason);

            return self::SUCCESS;
        }

        $this->line(sprintf('%s -> %s: %d %s', $from->name, $package, count($references), count($references) === 1 ? 'reference' : 'references'));

        foreach ($references as $reference) {
            $this->line(sprintf('  %s:%d  %s (%s)', $reference['file'], $reference['line'], $reference['class'], $reference['kind']));
        }

        $this->line($reason);

        return self::SUCCESS;
    }

    private function reportsPackage(Result $result, PackageMap $packages, Module $from, string $package): bool
    {
        foreach ([...$result->violations, ...$result->warnings] as $violation) {
            if ($violation->rule === ExternalDependencyRule::ID && $violation->sourceModule === $from->name && $violation->target !== null && $packages->covers($violation->target, [$package])) {
                return true;
            }
        }

        return false;
    }

    private function reports(Result $result, Module $from, Module $to): bool
    {
        foreach ([...$result->violations, ...$result->warnings] as $violation) {
            if ($violation->rule === UndeclaredDependencyRule::ID && $violation->sourceModule === $from->name && $violation->targetModule === $to->name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{kind: string}>  $references
     */
    private function onlyListeners(array $references): bool
    {
        return array_filter($references, static fn (array $reference): bool => $reference['kind'] !== Reference::LISTENER) === [];
    }
}
