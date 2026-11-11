<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Analysis\AffectedModules;
use Cordon\Laravel\Commands\Concerns\ExploresModules;
use Cordon\Laravel\Verifier;
use Cordon\Module\Module;
use Cordon\Support\GitChanges;
use Cordon\Support\Paths;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * @internal
 */
final class AffectedCommand extends Command
{
    use ExploresModules;

    protected $signature = 'cordon:affected
        {--since= : The git reference to compare with, such as main or origin/main}
        {--format=text : Output format: text or json}
        {--tests : List the test folders of the affected modules, one per line, instead of the modules}';

    protected $description = 'List the modules affected by what changed since a git reference: the changed ones and those that depend on them';

    public function handle(Verifier $verifier): int
    {
        if ($this->formatFailed() || $this->configurationFailed($verifier)) {
            return self::INVALID;
        }

        $since = $this->option('since');

        if (! is_string($since) || $since === '') {
            $this->stderr('The --since option is required. Pass the git reference to compare with, such as --since=main.');

            return self::INVALID;
        }

        if (count($verifier->modules()) === 0) {
            // Listing no affected module would skip every test: an empty project is a broken setup.
            $this->stderr(sprintf('No modules found. Check the resolver settings in config/cordon.php (%s).', $this->getName() === 'cordon:affected' ? 'php artisan cordon:modules' : 'vendor/bin/cordon modules'));

            return self::INVALID;
        }

        try {
            $files = GitChanges::changedFiles($verifier->basePath(), $since);
        } catch (RuntimeException $exception) {
            $this->stderr($exception->getMessage());

            return self::INVALID;
        }

        $snapshot = $verifier->snapshot();

        foreach ($verifier->cacheWarnings() as $warning) {
            $this->output->getErrorStyle()->writeln('<comment>'.$warning.'</comment>');
        }

        if ($snapshot->parseErrors !== []) {
            $this->output->getErrorStyle()->writeln(sprintf('<comment>%d file(s) could not be parsed, so their references are missing: some modules that depend on the changes may not be listed.</comment>', count($snapshot->parseErrors)));
        }

        $changed = $this->changedModules($verifier, $files);
        $affected = AffectedModules::of($snapshot->context, $changed);
        $tests = $this->option('tests') ? $this->testFolders($verifier, array_column($affected, 'module')) : null;

        if ($this->option('format') === 'json') {
            $this->json(['since' => $since, 'changed' => $changed, 'affected' => $affected] + ($tests === null ? [] : ['tests' => $tests]));

            return self::SUCCESS;
        }

        if ($tests !== null) {
            foreach ($tests as $folder) {
                $this->line($folder);
            }

            return self::SUCCESS;
        }

        if ($affected === []) {
            $this->line(sprintf('No module is affected by the changes since %s.', $since));

            return self::SUCCESS;
        }

        $this->line(sprintf('Modules affected by the changes since %s:', $since));

        foreach ($affected as $entry) {
            $this->line('  '.$entry['module'].'  '.$this->reason($entry));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{module: string, reason: string, through: string|null}  $entry
     */
    private function reason(array $entry): string
    {
        return match ($entry['reason']) {
            AffectedModules::DEPENDS_ON => 'depends on '.$entry['through'],
            AffectedModules::PARENT => 'contains '.$entry['through'],
            AffectedModules::NESTED => 'nested in '.$entry['through'],
            default => $entry['module'] === Module::HOST ? 'changed (the code outside the modules: no module depends on it)' : 'changed',
        };
    }

    /**
     * The modules with a changed file (the code outside the modules as "host"), sorted.
     *
     * A file belongs to the module whose directory holds it, or else to the one
     * whose test folder does (a changed test is a change of what it tests), or
     * else to the host when it is under host.paths. Any other file, such as
     * composer.json or a README, belongs to no module.
     *
     * @param  list<string>  $files  Relative to the project root.
     * @return list<string>
     */
    private function changedModules(Verifier $verifier, array $files): array
    {
        $modules = $verifier->modules();
        $folders = [];

        foreach ($modules as $module) {
            foreach ($this->declaredFolders($verifier, $module->name) as $folder) {
                $folders[] = [$module->name, $folder];
            }
        }

        $changed = [];

        foreach ($files as $file) {
            $absolute = Paths::join($verifier->basePath(), $file);
            $owner = $modules->forPath($absolute)?->name;

            if ($owner === null) {
                $length = -1;

                foreach ($folders as [$name, $folder]) {
                    if ($this->within($absolute, $folder) && strlen($folder) > $length) {
                        [$owner, $length] = [$name, strlen($folder)];
                    }
                }
            }

            if ($owner === null) {
                foreach ([...$verifier->hostDirectories(), ...$this->declaredFolders($verifier, Module::HOST)] as $directory) {
                    if ($this->within($absolute, $directory)) {
                        $owner = Module::HOST;

                        break;
                    }
                }
            }

            if ($owner !== null) {
                $changed[$owner] = true;
            }
        }

        $changed = array_map('strval', array_keys($changed));
        sort($changed);

        return $changed;
    }

    /**
     * The test folders of the affected modules that exist, relative to the
     * project root, in the order of the modules, without the ones inside another.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function testFolders(Verifier $verifier, array $names): array
    {
        $folders = [];

        foreach ($names as $name) {
            foreach ($this->declaredFolders($verifier, $name) as $absolute) {
                $relative = Paths::relative($verifier->basePath(), $absolute) ?? $absolute;

                if (is_dir($absolute)) {
                    $folders[$relative] = $absolute;
                } elseif ($verifier->configuredTests($name) !== null) {
                    $this->output->getErrorStyle()->writeln(sprintf('<comment>Module [%s]: the tests folder [%s] does not exist.</comment>', $name, $relative));
                }
            }
        }

        $kept = [];

        foreach ($folders as $relative => $absolute) {
            foreach ($folders as $other) {
                if ($other !== $absolute && $this->within($absolute, $other)) {
                    continue 2;
                }
            }

            $kept[] = (string) $relative;
        }

        return $kept;
    }

    /**
     * The test folders of a module as absolute paths: the ones its `tests`
     * setting lists, or else tests/<Module> (tests/Billing/Invoices for
     * Billing.Invoices) and the tests folder inside the module. The code
     * outside the modules has only what host.tests lists.
     *
     * @return list<string>
     */
    private function declaredFolders(Verifier $verifier, string $name): array
    {
        $configured = $verifier->configuredTests($name);

        if ($configured === null && $name !== Module::HOST) {
            $module = $verifier->modules()->get($name);
            $configured = $module === null ? [] : ['tests/'.str_replace('.', '/', $name), Paths::relative($verifier->basePath(), Paths::join($module->path, 'tests')) ?? ''];
        }

        $folders = [];

        foreach ($configured ?? [] as $folder) {
            $folder = trim($folder, '/\\');

            if ($folder !== '') {
                $folders[] = Paths::join($verifier->basePath(), str_starts_with($folder, './') ? substr($folder, 2) : $folder);
            }
        }

        return $folders;
    }

    private function within(string $path, string $directory): bool
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $path = str_replace('\\', '/', $path);

        return $path === $directory || str_starts_with($path, $directory.'/');
    }
}
