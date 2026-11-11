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

it('verifies a project without artisan, .env or APP_KEY', function () {
    $project = fixture_path('namespace-app');

    expect($project.'/artisan')->not->toBeFile()
        ->and($project.'/.env')->not->toBeFile();

    $run = run_cordon(['verify', '--format=json'], $project);

    expect($run['code'])->toBe(1)
        ->and(json_decode($run['out'], true)['summary']['violations'] ?? null)->toBe(2);
});

it('reads the project config, like cordon:verify', function () {
    // config/cordon.php marks Shared as open; without it, there are more violations.
    $project = temp_project('namespace-app');
    unlink($project.'/config/cordon.php');

    $run = run_cordon(['verify', '--format=json'], $project);

    expect(json_decode($run['out'], true)['summary']['violations'] ?? 0)->toBeGreaterThan(2);
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

it('fails on stale baseline entries with --fail-on-stale', function () {
    $project = temp_project('namespace-app');
    expect(run_cordon(['verify', '--generate-baseline'], $project)['code'])->toBe(0);

    $baseline = json_decode((string) file_get_contents($project.'/cordon-baseline.json'), true);
    $baseline['violations'][] = ['rule' => 'internal_access', 'file' => 'app/Modules/Billing/Gone.php', 'target' => 'App\\Modules\\Catalog\\Models\\Product', 'count' => 1];
    file_put_contents($project.'/cordon-baseline.json', json_encode($baseline));

    $run = run_cordon(['verify', '--fail-on-stale'], $project);

    expect($run['code'])->toBe(1)
        ->and($run['out'].$run['err'])->toContain('Run vendor/bin/cordon verify --prune-baseline', '[internal_access] app/Modules/Billing/Gone.php');
});

it('limits the report to the given paths, relative to the project root', function () {
    $run = run_cordon(['verify', 'app/Modules/Billing/Services/CheckoutService.php', '--format=json'], temp_project('namespace-app'));

    expect($run['code'])->toBe(1)
        ->and(array_column(json_decode($run['out'], true)['violations'], 'target'))->toBe(['App\\Modules\\Catalog\\Models\\Product']);
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

it('writes the errors of the explore commands to stderr, so JSON output stays JSON', function () {
    $project = temp_project('namespace-app');
    $run = run_cordon(['why', 'Biling', 'Catalog', '--format=json'], $project);

    expect($run['code'])->toBe(2)
        ->and($run['out'])->toBe('')
        ->and($run['err'])->toContain('Unknown module [Biling].');
});

it('warns about the code outside the modules, unless host.paths is empty', function () {
    $project = temp_project('host-app');
    $report = json_decode(run_cordon(['verify', '--format=json'], $project)['out'], true);

    expect(array_count_values(array_column($report['warnings'], 'rule')))->toBe(['host_access' => 4])
        ->and(run_cordon(['modules'], $project)['out'])->toContain('Code outside the modules (host): app, routes, database');

    mkdir($project.'/config');
    file_put_contents($project.'/config/cordon.php', "<?php return ['host' => ['paths' => []]];");

    expect(json_decode(run_cordon(['verify', '--format=json'], $project)['out'], true)['warnings'])->toBe([])
        ->and(run_cordon(['modules'], $project)['out'])->not->toContain('Code outside the modules');
});

it('reports what @cordon-ignore accepted, and the comments that no longer match', function () {
    $project = temp_project('ignore-app');
    $json = run_cordon(['verify', '--format=json'], $project);
    $text = run_cordon(['verify'], $project);

    expect($json['code'])->toBe(1)
        ->and(json_decode($json['out'], true)['summary']['ignored'])->toBe(2)
        ->and($json['err'])->toContain('Cordon ignore directive in app/Modules/Billing/Catalogue.php:9 (internal_access) no longer matches a violation on its line or the statement after it; remove it.')
        ->and($text['out'])->toContain('2 ignored in place');
});

it('fails with exit code 2 on a @cordon-ignore without a reason', function () {
    $project = temp_project('ignore-app');
    add_unexplained_ignore($project);
    $run = run_cordon(['verify', '--format=json'], $project);

    expect($run['code'])->toBe(2)
        ->and($run['out'])->toBe('')
        ->and($run['err'])->toContain('Cordon ignore directive in app/Modules/Billing/Unexplained.php:5 needs a reason in parentheses');
});

it('reports config errors even when there are no modules (M9)', function () {
    $project = temp_project();
    mkdir($project.'/config');
    file_put_contents($project.'/config/cordon.php', "<?php return ['rule' => []];");

    foreach ([['verify'], ['verify', '--allow-empty']] as $arguments) {
        $run = run_cordon($arguments, $project);

        expect($run['code'])->toBe(2)
            ->and($run['err'])->toContain('Unknown key [rule] in config/cordon.php. Did you mean [rules]?');
    }
});
