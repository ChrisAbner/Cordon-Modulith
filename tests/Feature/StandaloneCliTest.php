<?php

use Symfony\Component\Process\Process;

/**
 * Runs bin/cordon as a separate process from $cwd, the way CI would.
 *
 * @param  list<string>  $arguments
 * @return array{code: int, out: string, err: string}
 */
function run_cordon(array $arguments, string $cwd): array
{
    // NO_COLOR keeps the output plain wherever the suite runs: Symfony colours
    // piped output under Git Bash on Windows (MSYSTEM) or with FORCE_COLOR set.
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/cordon', ...$arguments], $cwd, ['XDEBUG_MODE' => 'off', 'NO_COLOR' => '1']);
    $process->run();

    return ['code' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
}

/**
 * A copy of a fixture project in a temporary directory, so commands can write to it.
 */
function temp_project(?string $fixture = null): string
{
    $directory = sys_get_temp_dir().'/cordon-cli-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($directory);

    if ($fixture !== null) {
        $source = fixture_path($fixture);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($files as $file) {
            $target = $directory.substr($file->getPathname(), strlen($source));
            $file->isDir() ? mkdir($target) : copy($file->getPathname(), $target);
        }
    }

    return $directory;
}

it('verifies a project without artisan, .env or APP_KEY', function () {
    $project = fixture_path('namespace-app');

    expect($project.'/artisan')->not->toBeFile()
        ->and($project.'/.env')->not->toBeFile();

    $run = run_cordon(['verify', '--format=json'], $project);

    expect($run['code'])->toBe(1)
        ->and(json_decode($run['out'], true)['summary']['violations'] ?? null)->toBe(4);
});

it('reads the project config, like cordon:verify', function () {
    // config/cordon.php marks Shared as open; without it, there are more violations.
    $project = temp_project('namespace-app');
    unlink($project.'/config/cordon.php');

    $run = run_cordon(['verify', '--format=json'], $project);

    expect(json_decode($run['out'], true)['summary']['violations'] ?? 0)->toBeGreaterThan(4);
});

it('prints GitHub annotations', function () {
    $run = run_cordon(['verify', '--format=github'], fixture_path('namespace-app'));

    expect($run['code'])->toBe(1)
        ->and($run['out'])->toContain('::error file=app/Modules/Billing/');
});

it('generates and applies a baseline', function () {
    $project = temp_project('namespace-app');

    expect(run_cordon(['verify', '--generate-baseline'], $project)['code'])->toBe(0)
        ->and($project.'/cordon-baseline.json')->toBeFile();

    $run = run_cordon(['verify'], $project);

    expect($run['code'])->toBe(0)
        ->and($run['out'])->toContain('suppressed by the baseline');
});

it('lists the detected modules', function () {
    $run = run_cordon(['modules'], fixture_path('namespace-app'));

    expect($run['code'])->toBe(0)
        ->and($run['out'])->toContain('Resolver: namespace')
        ->and($run['out'])->toContain('Billing');
});

it('generates the documentation', function () {
    $project = temp_project('namespace-app');

    $run = run_cordon(['docs', '--output=architecture'], $project);

    expect($run['code'])->toBe(0)
        ->and($project.'/architecture')->toBeDirectory();
});

it('fails when no modules are found, unless --allow-empty is given', function () {
    $project = temp_project();

    $run = run_cordon(['verify'], $project);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('(vendor/bin/cordon modules)')
        ->and($run['err'])->not->toContain('artisan')
        ->and(run_cordon(['verify', '--allow-empty'], $project)['code'])->toBe(0);
});

it('names its own commands in hints', function () {
    $run = run_cordon(['verify', '--module=Nope'], fixture_path('namespace-app'));

    expect($run['code'])->toBe(2)
        ->and($run['out'].$run['err'])->toContain('Run vendor/bin/cordon modules to list the detected modules.');
});

it('warns on stderr when the project config cannot be evaluated', function () {
    $project = temp_project('namespace-app');
    file_put_contents($project.'/config/cordon.php', '<?php throw new RuntimeException("boom");');

    $run = run_cordon(['verify', '--format=json'], $project);

    expect($run['err'])->toContain('could not evaluate config/cordon.php (boom)')
        ->and(json_decode($run['out'], true))->toBeArray();
});

it('accepts the artisan command names too', function () {
    expect(run_cordon(['cordon:modules'], fixture_path('namespace-app'))['code'])->toBe(0);
});
