<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Analysis\ModuleFilter;
use Cordon\Analysis\Result;
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
        {--fail-on-parse-error : Fail when a file cannot be parsed, since its references are not checked}
        {--generate-baseline : Record every current violation in the baseline file and exit}
        {--prune-baseline : Remove baseline entries that no longer match the code, without adding new violations, and exit}
        {--no-baseline : Ignore the baseline file}
        {--allow-empty : Pass when no modules are found, for projects that have none yet}';

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
            // Passing here would keep CI green without analysing anything,
            // for example after app/Modules moves or the resolver is misconfigured.
            $message = sprintf('No modules found. Check the resolver settings in config/cordon.php (%s), or pass --allow-empty while the project has no modules yet.', $this->sibling('modules'));

            if ($this->option('allow-empty')) {
                $this->output->getErrorStyle()->writeln('<comment>No modules found.</comment>');

                return self::SUCCESS;
            }

            if ($format === 'github') {
                $this->output->writeln('::error title=Cordon::'.$message, OutputInterface::OUTPUT_RAW);
            } else {
                $this->output->getErrorStyle()->writeln('<error>'.$message.'</error>');
            }

            return self::FAILURE;
        }

        $only = array_values(array_map('strval', (array) $this->option('module')));

        foreach ($only as $name) {
            if (! $modules->has($name)) {
                $this->error(sprintf('Unknown module [%s]. Run %s to list the detected modules.', $name, $this->sibling('modules')));

                return self::INVALID;
            }
        }

        if ($only !== [] && $this->option('generate-baseline')) {
            $this->error('The --module option cannot be combined with --generate-baseline: the baseline must cover every module.');

            return self::INVALID;
        }

        if ($this->option('prune-baseline') && ($message = $this->pruneConflict($verifier, $only)) !== null) {
            $this->error($message);

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

        if ($this->option('prune-baseline')) {
            return $this->prune($verifier, $result);
        }

        if (! $this->option('no-baseline')) {
            foreach ($verifier->baselineWarnings() as $warning) {
                if ($format === 'github') {
                    $this->output->writeln('::warning title=Cordon::'.$warning, OutputInterface::OUTPUT_RAW);
                } else {
                    $this->output->getErrorStyle()->writeln('<comment>'.$warning.'</comment>');
                }
            }

            // Counted on the whole analysis, before --module narrows the report.
            if (($stale = $verifier->staleBaselineEntries($result)) > 0) {
                $message = sprintf(
                    '%d baseline %s no longer %s the code. Run %s --prune-baseline to remove %s.',
                    $stale,
                    $stale === 1 ? 'entry' : 'entries',
                    $stale === 1 ? 'matches' : 'match',
                    $this->sibling('verify'),
                    $stale === 1 ? 'it' : 'them',
                );

                if ($format === 'github') {
                    $this->output->writeln('::notice title=Cordon::'.$message, OutputInterface::OUTPUT_RAW);
                } else {
                    $this->output->getErrorStyle()->writeln('<comment>'.$message.'</comment>');
                }
            }

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

        if ($result->hasViolations()) {
            return self::FAILURE;
        }

        if ($this->option('fail-on-parse-error') && $result->parseErrors !== []) {
            $this->output->getErrorStyle()->writeln(sprintf(
                '<error>Failing because %d file(s) could not be parsed (--fail-on-parse-error).</error>',
                count($result->parseErrors),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Why --prune-baseline cannot run with the other options given, if it can't.
     *
     * @param  list<string>  $only
     */
    private function pruneConflict(Verifier $verifier, array $only): ?string
    {
        return match (true) {
            $only !== [] => 'The --module option cannot be combined with --prune-baseline: pruning needs every module.',
            (bool) $this->option('generate-baseline') => 'The --generate-baseline and --prune-baseline options cannot be combined: one rewrites the baseline, the other only shrinks it.',
            (bool) $this->option('no-baseline') => 'The --no-baseline and --prune-baseline options cannot be combined.',
            ! $verifier->hasBaseline() => sprintf(
                'There is no baseline file to prune at %s. Create one with %s --generate-baseline.',
                Paths::relative($verifier->basePath(), $verifier->baselineFile()),
                $this->sibling('verify'),
            ),
            default => null,
        };
    }

    private function prune(Verifier $verifier, Result $result): int
    {
        [$pruned, $remaining, $new] = $verifier->pruneBaseline($result);

        $this->info(sprintf(
            'Pruned %d baseline %s that no longer matched the code. %d %s remain in %s.',
            $pruned,
            $pruned === 1 ? 'entry' : 'entries',
            $remaining,
            $remaining === 1 ? 'entry' : 'entries',
            Paths::relative($verifier->basePath(), $verifier->baselineFile()),
        ));

        if ($new > 0) {
            $this->output->getErrorStyle()->writeln(sprintf(
                '<comment>%d violation(s) are not in the baseline and were not added. Run %s to see them.</comment>',
                $new,
                $this->sibling('verify'),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * How to run another Cordon command from wherever this one was run:
     * artisan, or vendor/bin/cordon (StandaloneApplication).
     */
    private function sibling(string $command): string
    {
        return $this->getName() === 'cordon:verify' ? 'php artisan cordon:'.$command : 'vendor/bin/cordon '.$command;
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
