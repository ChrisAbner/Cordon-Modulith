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
    // Billing <-> Catalog closes only through Catalog's listener of InvoicePaid, which is not a cycle by default.
    $this->artisan('cordon:verify', ['--format' => 'json'])
        ->expectsOutputToContain('"violations": 2')
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

it('stops before analysing when the config has errors', function () {
    config()->set('cordon.modules', ['Shared' => ['open' => true], 'Billing' => ['depend_on' => ['Catalog']]]);

    $this->artisan('cordon:verify')
        ->expectsOutputToContain('Unknown key [depend_on] for module [Billing]. Did you mean [depends_on]?')
        ->doesntExpectOutputToContain('internal_access')
        ->assertExitCode(2);
});

it('prints config errors as GitHub annotations', function () {
    config()->set('cordon.modules', ['Shared' => ['open' => true], 'Biling' => ['open' => true]]);

    $this->artisan('cordon:verify', ['--format' => 'github'])
        ->expectsOutputToContain('::error title=Cordon::config/cordon.php configures unknown module [Biling]. Did you mean [Billing]?')
        ->assertExitCode(2);
});

it('lists the modules even when the config has errors, and shows the errors', function () {
    config()->set('cordon.modules', ['Shared' => ['open' => true], 'Biling' => ['open' => true]]);

    $this->artisan('cordon:modules')
        ->expectsOutputToContain('config/cordon.php configures unknown module [Biling].')
        ->expectsOutputToContain('Billing')
        ->assertExitCode(0);
});

it('passes with warnings only and shows them in every format', function () {
    config()->set('cordon.rules', ['internal_access' => 'warn', 'undeclared_dependency' => 'warn', 'cycles' => 'warn']);

    expect(Artisan::call('cordon:verify'))->toBe(0)
        ->and(Artisan::output())->toContain('! [internal_access]', '(warn)', 'No boundary violations. (2 warnings)');

    expect(Artisan::call('cordon:verify', ['--format' => 'json']))->toBe(0);
    $report = json_decode(Artisan::output(), true);

    expect($report['violations'])->toBe([])
        ->and($report['summary']['warnings'])->toBe(2)
        ->and(array_unique(array_column($report['warnings'], 'severity')))->toBe(['warn']);

    $this->artisan('cordon:verify', ['--format' => 'github'])
        ->expectsOutputToContain('::warning file=')
        ->assertExitCode(0);
});

it('leaves warnings out of a generated baseline', function () {
    config()->set('cordon.rules', ['cycles' => 'warn']);

    $this->artisan('cordon:verify', ['--generate-baseline' => true])->assertExitCode(0);

    $rules = array_column(json_decode((string) file_get_contents((string) config('cordon.baseline')), true)['violations'], 'rule');

    expect($rules)->not->toBe([])->not->toContain('cycles');
});

it('reports an invalid rule value as a config error', function () {
    config()->set('cordon.rules', ['cycles' => 'warning']);

    $this->artisan('cordon:verify')
        ->expectsOutputToContain('Rule [cycles] has an invalid value [warning].')
        ->assertExitCode(2);
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
        ->expectsOutputToContain('"violations": 2')
        ->assertExitCode(1);
});

it('keeps the dependency cycles a module takes part in', function () {
    config()->set('cordon.listeners', ['cycles' => true]);

    $exitCode = Artisan::call('cordon:verify', ['--module' => ['Catalog'], '--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and(array_column($report['violations'], 'rule'))->toBe(['cycles', 'cycles'])
        ->and(array_column($report['violations'], 'target'))->toBe(['Billing -> Catalog', 'Catalog -> Billing']);
});

it('warns when a version 1 baseline has cycle entries that no longer match', function () {
    copy(fixture_path('baselines/v1-cycles.json'), (string) config('cordon.baseline'));

    $this->artisan('cordon:verify')
        ->expectsOutputToContain('Regenerate the baseline with cordon:verify --generate-baseline (or vendor/bin/cordon verify --generate-baseline).')
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
    config()->set('cordon.modules', []);

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

it('fails on stale baseline entries with --fail-on-stale, listing each one', function () {
    $this->artisan('cordon:verify', ['--generate-baseline' => true])->assertExitCode(0);
    edit_baseline(fn (array $entries) => [...$entries, stale_entry()]);

    expect(Artisan::call('cordon:verify'))->toBe(0);

    expect(Artisan::call('cordon:verify', ['--fail-on-stale' => true]))->toBe(1)
        ->and(Artisan::output())->toContain(
            '1 baseline entry no longer matches the code. Run php artisan cordon:verify --prune-baseline to remove it.',
            '  - [internal_access] app/Modules/Billing/Gone.php -> App\\Modules\\Catalog\\Models\\Product: expected 1, found 0',
        );

    expect(Artisan::call('cordon:verify', ['--fail-on-stale' => true, '--format' => 'github']))->toBe(1)
        ->and(Artisan::output())->toContain('::error title=Cordon::1 baseline entry no longer matches the code.');
});

it('counts an entry that records more occurrences than the code has as stale', function () {
    $this->artisan('cordon:verify', ['--generate-baseline' => true])->assertExitCode(0);
    edit_baseline(fn (array $entries) => array_map(
        fn (array $entry) => str_ends_with((string) $entry['file'], 'Billing/Services/CheckoutService.php') ? [...$entry, 'count' => 3] : $entry,
        $entries,
    ));

    expect(Artisan::call('cordon:verify', ['--fail-on-stale' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('Billing/Services/CheckoutService.php -> App\\Modules\\Catalog\\Models\\Product: expected 3, found 1');
});

it('skips the stale check when a file could not be parsed', function () {
    app_with_a_file_that_does_not_parse();
    file_put_contents((string) config('cordon.baseline'), json_encode(['version' => 2, 'violations' => [stale_entry()]]));

    expect(Artisan::call('cordon:verify', ['--fail-on-stale' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Skipped the stale baseline check: 1 file(s) could not be parsed.');
});

it('rejects --fail-on-stale without the baseline', function () {
    $this->artisan('cordon:verify', ['--fail-on-stale' => true, '--no-baseline' => true])
        ->expectsOutputToContain('The --fail-on-stale option needs the baseline: drop --no-baseline.')
        ->assertExitCode(2);
});

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
