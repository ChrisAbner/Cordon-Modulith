<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Analysis\Reference;
use Cordon\Analysis\Result;
use Cordon\Laravel\Commands\Concerns\ExploresModules;
use Cordon\Laravel\Verifier;
use Cordon\Module\Module;
use Cordon\Rules\UndeclaredDependencyRule;
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
        {to : The module it depends on}
        {--format=text : Output format: text or json}';

    protected $description = 'List the references that make one module depend on another, and whether depends_on allows it';

    public function handle(Verifier $verifier): int
    {
        if ($this->formatFailed() || $this->configurationFailed($verifier)) {
            return self::INVALID;
        }

        $from = $this->module($verifier, $this->text('from'));
        $to = $from === null ? null : $this->module($verifier, $this->text('to'));

        if ($from === null || $to === null) {
            return self::INVALID;
        }

        $snapshot = $verifier->snapshot();
        $references = [];

        foreach ($snapshot->context->edges as $edge) {
            if ($edge->from->name === $from->name && $edge->to->name === $to->name) {
                $references[] = [
                    'file' => Paths::relative($verifier->basePath(), $edge->reference->sourceFile),
                    'line' => $edge->reference->line,
                    'class' => $edge->reference->target,
                    'kind' => $edge->reference->kind,
                ];
            }
        }

        $allowed = $from->dependsOn === null ? null : in_array($to->name, $from->dependsOn, true);
        $reported = $this->reports($verifier->check($snapshot), $from, $to);
        $uncountedListeners = $references !== [] && $this->onlyListeners($references) && config('cordon.listeners.depends_on', true) === false;
        $reason = match (true) {
            $references === [] => sprintf('%s does not depend on %s.', $from->name, $to->name),
            $allowed === null => sprintf('%s does not declare depends_on, so it may depend on any module.', $from->name),
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
