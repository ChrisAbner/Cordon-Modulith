<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Analysis\Analyzer;
use Cordon\Baseline\Baseline;
use Cordon\Contracts\ModuleResolver;
use Cordon\Contracts\Reporter;
use Cordon\Reporters\GithubReporter;
use Cordon\Reporters\JsonReporter;
use Cordon\Reporters\TextReporter;
use Cordon\Support\Paths;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class VerifyCommand extends Command
{
    protected $signature = 'cordon:verify
        {--format=text : Output format: text, json or github}
        {--generate-baseline : Record every current violation in the baseline file and exit}
        {--no-baseline : Ignore the baseline file}';

    protected $description = 'Verify that modules only depend on each other through their public API';

    public function handle(ModuleResolver $resolver, Analyzer $analyzer): int
    {
        $format = (string) $this->option('format');
        $reporter = $this->reporter($format);

        if ($reporter === null) {
            $this->error(sprintf('Unknown format [%s]. Use text, json or github.', $format));

            return self::INVALID;
        }

        $basePath = $this->laravel->basePath();
        $moduleConfig = (array) $this->laravel['config']->get('cordon.modules', []);
        $modules = $resolver->resolve();

        foreach ($modules->configurationWarnings($moduleConfig) as $warning) {
            $this->output->getErrorStyle()->writeln('<comment>'.$warning.'</comment>');
        }

        $modules = $modules->configure($moduleConfig);

        if (count($modules) === 0) {
            $this->output->getErrorStyle()->writeln('<comment>No modules found. Check the resolver settings in config/cordon.php (php artisan cordon:modules).</comment>');

            return self::SUCCESS;
        }

        $result = $analyzer->analyze($modules, $basePath);
        $baselineFile = Paths::join($basePath, (string) $this->laravel['config']->get('cordon.baseline', 'cordon-baseline.json'));

        if ($this->option('generate-baseline')) {
            Baseline::fromViolations($result->violations, $basePath)->save($baselineFile);

            $this->info(sprintf(
                'Baseline written to %s with %d violation(s).',
                Paths::relative($basePath, $baselineFile),
                count($result->violations),
            ));

            return self::SUCCESS;
        }

        if (! $this->option('no-baseline') && is_file($baselineFile)) {
            $result = $result->withBaseline(Baseline::load($baselineFile));
        }

        $this->output->write(
            $reporter->render($result),
            false,
            $reporter->formatted() ? OutputInterface::OUTPUT_NORMAL : OutputInterface::OUTPUT_RAW,
        );

        return $result->hasViolations() ? self::FAILURE : self::SUCCESS;
    }

    private function reporter(string $format): ?Reporter
    {
        return match ($format) {
            'text' => new TextReporter,
            'json' => new JsonReporter,
            'github' => new GithubReporter,
            default => null,
        };
    }
}
