<?php

use Cordon\Tests\Fixtures\Rules\DeclareDependenciesRule;
use Illuminate\Support\Facades\Artisan;

afterEach(function () {
    @unlink((string) config('cordon.baseline'));
});

it('fails when there are boundary violations', function () {
    $this->artisan('cordon:verify')
        ->expectsOutputToContain('internal_access')
        ->assertExitCode(1);
});

it('prints a machine readable report', function () {
    $this->artisan('cordon:verify', ['--format' => 'json'])
        ->expectsOutputToContain('"violations": 4')
        ->assertExitCode(1);
});

it('prints GitHub annotations', function () {
    $this->artisan('cordon:verify', ['--format' => 'github'])
        ->expectsOutputToContain('::error file=')
        ->assertExitCode(1);
});

it('rejects unknown formats', function () {
    $this->artisan('cordon:verify', ['--format' => 'xml'])->assertExitCode(2);
});

it('passes after generating a baseline and fails again without it', function () {
    $this->artisan('cordon:verify', ['--generate-baseline' => true])->assertExitCode(0);

    expect((string) config('cordon.baseline'))->toBeFile();

    $this->artisan('cordon:verify')
        ->expectsOutputToContain('suppressed by the baseline')
        ->assertExitCode(0);

    $this->artisan('cordon:verify', ['--no-baseline' => true])->assertExitCode(1);
});

it('lists the detected modules', function () {
    $this->artisan('cordon:modules')
        ->expectsOutputToContain('Billing')
        ->assertExitCode(0);
});

it('limits the report to one module with --module', function () {
    $this->artisan('cordon:verify', ['--module' => ['Shared']])
        ->expectsOutputToContain('No boundary violations')
        ->assertExitCode(0);

    $this->artisan('cordon:verify', ['--module' => ['Billing'], '--format' => 'json'])
        ->expectsOutputToContain('"violations": 4')
        ->assertExitCode(1);
});

it('keeps the dependency cycles a module takes part in', function () {
    $exitCode = Artisan::call('cordon:verify', ['--module' => ['Catalog'], '--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and(array_column($report['violations'], 'rule'))->toBe(['cycles', 'cycles'])
        ->and(array_column($report['violations'], 'target'))->toBe(['Billing -> Catalog', 'Catalog -> Billing']);
});

it('warns when a version 1 baseline has cycle entries that no longer match', function () {
    copy(fixture_path('baselines/v1-cycles.json'), (string) config('cordon.baseline'));

    $this->artisan('cordon:verify')
        ->expectsOutputToContain('Regenerate the baseline with php artisan cordon:verify --generate-baseline')
        ->assertExitCode(1);
});

it('shows the outdated baseline warning as a GitHub annotation', function () {
    copy(fixture_path('baselines/v1-cycles.json'), (string) config('cordon.baseline'));

    $this->artisan('cordon:verify', ['--format' => 'github'])
        ->expectsOutputToContain('::warning title=Cordon::The baseline has 1 cycles entry')
        ->assertExitCode(1);
});

it('fails when no modules are found, unless --allow-empty is given', function () {
    config()->set('cordon.resolvers.namespace.path', fixture_path('edge-cases/no-modules-here'));

    $this->artisan('cordon:verify')
        ->expectsOutputToContain('No modules found')
        ->assertExitCode(1);

    $this->artisan('cordon:verify', ['--allow-empty' => true])->assertExitCode(0);
});

it('reports a missing module setup as a GitHub annotation', function () {
    config()->set('cordon.resolvers.namespace.path', fixture_path('edge-cases/no-modules-here'));

    $this->artisan('cordon:verify', ['--format' => 'github'])
        ->expectsOutputToContain('::error title=Cordon::No modules found')
        ->assertExitCode(1);
});

it('rejects unknown modules and --module with --generate-baseline', function () {
    $this->artisan('cordon:verify', ['--module' => ['Ghost']])->assertExitCode(2);
    $this->artisan('cordon:verify', ['--module' => ['Billing'], '--generate-baseline' => true])->assertExitCode(2);

    expect((string) config('cordon.baseline'))->not->toBeFile();
});

it('runs custom rules from the config', function () {
    config()->set('cordon.rules', [DeclareDependenciesRule::class]);

    $this->artisan('cordon:verify', ['--format' => 'json'])
        ->expectsOutputToContain('"rule": "declare_dependencies"')
        ->assertExitCode(1);
});

function app_with_a_file_that_does_not_parse(): string
{
    $root = sys_get_temp_dir().'/cordon-parse-error-'.getmypid();
    @mkdir($root.'/Billing', 0777, true);
    file_put_contents($root.'/Billing/Broken.php', "<?php\n\nnamespace App\\Modules\\Billing;\n\nfinal class Broken {\n");

    config()->set('cordon.resolvers.namespace.path', $root);

    return $root;
}

it('reports a file that does not parse but passes by default', function () {
    app_with_a_file_that_does_not_parse();

    $this->artisan('cordon:verify', ['--format' => 'github'])
        ->expectsOutputToContain('Broken.php,line=1,title=Cordon%3A parse error::Cordon skipped this file (parse error:')
        ->assertExitCode(0);
});

it('fails on a file that does not parse with --fail-on-parse-error', function () {
    app_with_a_file_that_does_not_parse();

    $this->artisan('cordon:verify', ['--fail-on-parse-error' => true])
        ->expectsOutputToContain('Failing because 1 file(s) could not be parsed')
        ->assertExitCode(1);
});

/**
 * Rewrites the baseline file through $change.
 */
function edit_baseline(callable $change): void
{
    $file = (string) config('cordon.baseline');
    $data = json_decode((string) file_get_contents($file), true);
    $data['violations'] = array_values($change($data['violations']));
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function stale_entry(): array
{
    return ['rule' => 'internal_access', 'file' => 'app/Modules/Billing/Gone.php', 'target' => 'App\\Modules\\Catalog\\Models\\Product', 'count' => 1];
}

it('reports baseline entries that no longer match', function () {
    $this->artisan('cordon:verify', ['--generate-baseline' => true])->assertExitCode(0);
    edit_baseline(fn (array $entries) => [...$entries, stale_entry()]);

    $this->artisan('cordon:verify')
        ->expectsOutputToContain('1 baseline entry no longer matches the code. Run php artisan cordon:verify --prune-baseline to remove it.')
        ->assertExitCode(0);
});

it('shows baseline entries that no longer match as a GitHub notice', function () {
    $this->artisan('cordon:verify', ['--generate-baseline' => true])->assertExitCode(0);
    edit_baseline(fn (array $entries) => [...$entries, stale_entry(), ['target' => 'App\\Other'] + stale_entry()]);

    $this->artisan('cordon:verify', ['--format' => 'github'])
        ->expectsOutputToContain('::notice title=Cordon::2 baseline entries no longer match the code.')
        ->assertExitCode(0);
});

it('prunes fixed entries without accepting new violations', function () {
    $this->artisan('cordon:verify', ['--generate-baseline' => true])->assertExitCode(0);

    // One violation becomes new (its entry is dropped) and one entry is stale.
    $new = null;
    edit_baseline(function (array $entries) use (&$new) {
        $new = array_shift($entries);

        return [...$entries, stale_entry()];
    });

    $this->artisan('cordon:verify', ['--prune-baseline' => true])
        ->expectsOutputToContain('Pruned 1 baseline entry that no longer matched the code.')
        ->assertExitCode(0);

    $saved = json_decode((string) file_get_contents((string) config('cordon.baseline')), true);

    expect($saved['violations'])->not->toContain(stale_entry())
        ->and($saved['violations'])->not->toContain($new);

    $this->artisan('cordon:verify')
        ->doesntExpectOutputToContain('no longer match')
        ->assertExitCode(1);
});

it('rejects --prune-baseline with options that conflict with it or without a baseline', function () {
    $this->artisan('cordon:verify', ['--prune-baseline' => true])
        ->expectsOutputToContain('There is no baseline file to prune')
        ->assertExitCode(2);

    $this->artisan('cordon:verify', ['--generate-baseline' => true])->assertExitCode(0);

    $this->artisan('cordon:verify', ['--prune-baseline' => true, '--module' => ['Billing']])->assertExitCode(2);
    $this->artisan('cordon:verify', ['--prune-baseline' => true, '--generate-baseline' => true])->assertExitCode(2);
    $this->artisan('cordon:verify', ['--prune-baseline' => true, '--no-baseline' => true])->assertExitCode(2);
});

it('hints that a layered app is not split into modules', function () {
    config()->set('cordon.resolvers.namespace.path', fixture_path('layered-app/app'));
    config()->set('cordon.resolvers.namespace.namespace', 'App');

    $this->artisan('cordon:modules')
        ->expectsOutputToContain('These modules look like the layers of a standard Laravel app (Http, Models, Providers, Services)')
        ->assertExitCode(0);
});

it('does not hint for modules split by feature', function () {
    $this->artisan('cordon:modules')
        ->doesntExpectOutputToContain('look like the layers')
        ->assertExitCode(0);
});
