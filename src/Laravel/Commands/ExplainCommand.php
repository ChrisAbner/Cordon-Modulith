<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Analysis\Result;
use Cordon\Laravel\Commands\Concerns\ExploresModules;
use Cordon\Laravel\Verifier;
use Cordon\Rules\InternalAccessRule;
use Cordon\Rules\Severity;
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
