<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Documentation\GraphExporter;
use Cordon\Laravel\Commands\Concerns\ExploresModules;
use Cordon\Laravel\Verifier;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class GraphCommand extends Command
{
    use ExploresModules;

    protected $signature = 'cordon:graph
        {--format=mermaid : Output format: mermaid, dot, json or c4}
        {--module= : Only this module and its neighbours}';

    protected $description = 'Export the module dependency graph as Mermaid, DOT (Graphviz), JSON or C4-PlantUML';

    public function handle(Verifier $verifier): int
    {
        if ($this->formatFailed(['mermaid', 'dot', 'json', 'c4'])) {
            return self::INVALID;
        }

        $format = $this->option('format');

        if ($this->configurationFailed($verifier)) {
            return self::INVALID;
        }

        $focus = $this->option('module');
        $focus = is_string($focus) && $focus !== '' ? $focus : null;

        if ($focus !== null && $this->module($verifier, $focus) === null) {
            return self::INVALID;
        }

        $snapshot = $verifier->snapshot();
        $violations = $verifier->check($snapshot)->violations;

        if ($format === 'c4') {
            $this->output->write(GraphExporter::c4($snapshot, $violations, $focus), false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $model = GraphExporter::model($verifier->modules()->names(), $snapshot->context->edges, $violations, $verifier->modules(), $focus);

        $output = match ($format) {
            'json' => (string) json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            'dot' => GraphExporter::dot($model),
            default => GraphExporter::flowchart($model),
        };

        $this->output->write($output, false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
