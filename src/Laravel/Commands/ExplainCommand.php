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
final class ExplainCommand extends Command
{
    use ExploresModules;

    protected $signature = 'cordon:explain
        {class : The fully qualified class name}
        {--format=text : Output format: text or json}';

    protected $description = 'Explain which module a class belongs to, whether other modules may use it and who does';

    public function handle(Verifier $verifier): int
    {
        if ($this->configurationFailed($verifier)) {
            return self::INVALID;
        }

        $class = ltrim($this->text('class'), '\\');
        $module = $verifier->modules()->forClass($class);

        if ($module === null) {
            $this->error(sprintf('[%s] does not belong to any module.', $class));

            return self::INVALID;
        }

        $snapshot = $verifier->snapshot();
        $declaration = $snapshot->context->symbols->get($class);

        if ($declaration === null) {
            $this->error(sprintf('[%s] is not declared in module [%s].', $class, $module->name));

            return self::INVALID;
        }

        $policy = $snapshot->context->policy;
        $public = $policy->isPublic($declaration->fqcn, $module, $declaration);
        $usedBy = [];

        foreach ($snapshot->context->edges as $edge) {
            if (strcasecmp($edge->reference->target, $declaration->fqcn) === 0) {
                $usedBy[] = [
                    'module' => $edge->from->name,
                    'file' => Paths::relative($verifier->basePath(), $edge->reference->sourceFile),
                    'line' => $edge->reference->line,
                    'kind' => $edge->reference->kind,
                    'violation' => ! $public,
                ];
            }
        }

        $report = [
            'class' => $declaration->fqcn,
            'module' => $module->name,
            'public' => $public,
            'reason' => $policy->reason($declaration->fqcn, $module, $declaration),
            'used_by' => $usedBy,
        ];

        if ($this->option('format') === 'json') {
            $this->json($report);

            return self::SUCCESS;
        }

        $this->line($report['class']);
        $this->line('  Module:  '.$report['module']);
        $this->line(sprintf('  Status:  %s: %s', $public ? 'public' : 'internal', $report['reason']));
        $this->line('  Used by:'.($usedBy === [] ? ' no other module' : ''));

        foreach ($usedBy as $use) {
            $this->line(sprintf('    %s %s  %s:%d (%s)', $use['violation'] ? 'x' : '-', $use['module'], $use['file'], $use['line'], $use['kind']));
        }

        return self::SUCCESS;
    }
}
