<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Analysis\ModuleFilter;
use Cordon\Baseline\Baseline;
use Cordon\Contracts\Reporter;
use Cordon\Laravel\Verifier;
use Cordon\Laravel\XdebugWarning;
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
        {--module=* : Only report the violations of this module (repeatable)}
        {--generate-baseline : Record every current violation in the baseline file and exit}
        {--no-baseline : Ignore the baseline file}';

    protected $description = 'Verify that modules only depend on each other through their public API';

    public function handle(Verifier $verifier, XdebugWarning $xdebug): int
    {
        $format = $this->option('format');
        $format = is_string($format) ? $format : 'text';
        $reporter = $this->reporter($format);

        if ($reporter === null) {
            $this->error(sprintf('Unknown format [%s]. Use text, json or github.', $format));

            return self::INVALID;
        }

        if (($message = $xdebug->message()) !== null) {
            $this->output->getErrorStyle()->writeln('<comment>'.$message.'</comment>');
        }

        foreach ($verifier->configurationWarnings() as $warning) {
            $this->output->getErrorStyle()->writeln('<comment>'.$warning.'</comment>');
        }

        $modules = $verifier->modules();

        if (count($modules) === 0) {
            $this->output->getErrorStyle()->writeln('<comment>No modules found. Check the resolver settings in config/cordon.php (php artisan cordon:modules).</comment>');

            return self::SUCCESS;
        }

        $only = array_values(array_map('strval', (array) $this->option('module')));

        foreach ($only as $name) {
            if (! $modules->has($name)) {
                $this->error(sprintf('Unknown module [%s]. Run php artisan cordon:modules to list the detected modules.', $name));

                return self::INVALID;
            }
        }

        if ($only !== [] && $this->option('generate-baseline')) {
            $this->error('The --module option cannot be combined with --generate-baseline: the baseline must cover every module.');

            return self::INVALID;
        }

        $result = $verifier->analyze();
        $basePath = $verifier->basePath();

        if ($this->option('generate-baseline')) {
            $baselineFile = $verifier->baselineFile();
            Baseline::fromViolations($result->violations, $basePath)->save($baselineFile);

            $this->info(sprintf(
                'Baseline written to %s with %d violation(s).',
                Paths::relative($basePath, $baselineFile),
                count($result->violations),
            ));

            return self::SUCCESS;
        }

        if (! $this->option('no-baseline')) {
            $result = $verifier->applyBaseline($result);
        }

        if ($only !== []) {
            $result = ModuleFilter::apply($result, $only);
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
