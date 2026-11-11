<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Analysis\DependentGraph;
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

        $found = (new DependentGraph($verifier->snapshot()->context))->reach($module->name);

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
