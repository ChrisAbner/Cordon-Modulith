<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Laravel\Commands\Concerns\ExploresModules;
use Cordon\Laravel\Verifier;
use Illuminate\Console\Command;

/**
 * @internal
 */
final class ImpactCommand extends Command
{
    use ExploresModules;

    protected $signature = 'cordon:impact
        {module : The module that changes}
        {--format=text : Output format: text or json}';

    protected $description = 'List the modules that depend on a module, directly or through others';

    public function handle(Verifier $verifier): int
    {
        if ($this->formatFailed() || $this->configurationFailed($verifier)) {
            return self::INVALID;
        }

        $module = $this->module($verifier, $this->text('module'));

        if ($module === null) {
            return self::INVALID;
        }

        $dependents = [];

        foreach ($verifier->snapshot()->context->edges as $edge) {
            $dependents[$edge->to->name][$edge->from->name] = true;
        }

        // Breadth first from the module, along "is depended on by".
        $found = [];
        $queue = [[$module->name, 0, null]];
        $visited = [$module->name => true];

        while ($queue !== []) {
            [$name, $distance, $through] = array_shift($queue);
            $next = array_keys($dependents[$name] ?? []);
            sort($next);

            foreach ($next as $dependent) {
                $dependent = (string) $dependent;

                if (! isset($visited[$dependent])) {
                    $visited[$dependent] = true;
                    $found[] = ['module' => $dependent, 'distance' => $distance + 1, 'through' => $distance === 0 ? null : $name];
                    $queue[] = [$dependent, $distance + 1, $name];
                }
            }
        }

        if ($this->option('format') === 'json') {
            $this->json(['module' => $module->name, 'dependents' => $found]);

            return self::SUCCESS;
        }

        if ($found === []) {
            $this->line(sprintf('No module depends on %s.', $module->name));

            return self::SUCCESS;
        }

        $this->line(sprintf('Modules that depend on %s:', $module->name));

        foreach ($found as $dependent) {
            $this->line(sprintf('  %d  %s (%s)', $dependent['distance'], $dependent['module'], $dependent['through'] === null ? 'directly' : 'through '.$dependent['through']));
        }

        return self::SUCCESS;
    }
}
