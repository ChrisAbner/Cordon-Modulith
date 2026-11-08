<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Analysis\Edge;
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
        {--format=mermaid : Output format: mermaid, dot or json}
        {--module= : Only this module and its neighbours}';

    protected $description = 'Export the module dependency graph as Mermaid, DOT (Graphviz) or JSON';

    public function handle(Verifier $verifier): int
    {
        $format = $this->option('format');

        if (! in_array($format, ['mermaid', 'dot', 'json'], true)) {
            $this->error(sprintf('Unknown format [%s]. Use mermaid, dot or json.', is_string($format) ? $format : ''));

            return self::INVALID;
        }

        if ($this->configurationFailed($verifier)) {
            return self::INVALID;
        }

        $focus = $this->option('module');
        $focus = is_string($focus) && $focus !== '' ? $focus : null;

        if ($focus !== null && $this->module($verifier, $focus) === null) {
            return self::INVALID;
        }

        $snapshot = $verifier->snapshot();
        $edges = $snapshot->context->edges;
        $violations = $verifier->check($snapshot)->violations;
        $model = GraphExporter::model($verifier->modules()->names(), $edges, $violations, $focus);

        $output = match ($format) {
            'json' => (string) json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            'dot' => GraphExporter::dot($model),
            default => $this->mermaid($model, $edges),
        };

        $this->output->write($output, false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * @param  array{modules: list<string>, edges: list<array{from: string, to: string, references: int, kinds: list<string>, violations: int}>}  $model
     * @param  list<Edge>  $edges
     */
    private function mermaid(array $model, array $edges): string
    {
        $kept = array_flip($model['modules']);
        $dependencies = GraphExporter::dependencies(array_values(array_filter(
            $edges,
            static fn ($edge): bool => isset($kept[$edge->from->name], $kept[$edge->to->name]),
        )));
        $marked = [];

        foreach ($model['edges'] as $edge) {
            if ($edge['violations'] > 0) {
                $marked[$edge['from'].'|'.$edge['to']] = true;
            }
        }

        return implode(PHP_EOL, GraphExporter::mermaid($model['modules'], $dependencies, $marked)).PHP_EOL;
    }
}
