<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Laravel\Commands\Concerns\ExploresModules;
use Cordon\Laravel\Verifier;
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
        if ($this->configurationFailed($verifier)) {
            return self::INVALID;
        }

        $from = $this->module($verifier, $this->text('from'));
        $to = $from === null ? null : $this->module($verifier, $this->text('to'));

        if ($from === null || $to === null) {
            return self::INVALID;
        }

        $references = [];

        foreach ($verifier->snapshot()->context->edges as $edge) {
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
        $reason = match ($allowed) {
            null => sprintf('%s does not declare depends_on, so it may depend on any module.', $from->name),
            true => sprintf('%s lists %s in depends_on.', $from->name, $to->name),
            false => sprintf('%s does not list %s in depends_on: this dependency is an undeclared_dependency.', $from->name, $to->name),
        };

        if ($this->option('format') === 'json') {
            $this->json(['from' => $from->name, 'to' => $to->name, 'references' => $references, 'allowed' => $allowed, 'reason' => $reason]);

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
}
