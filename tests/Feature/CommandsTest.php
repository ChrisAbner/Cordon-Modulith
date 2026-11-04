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
        ->expectsOutputToContain('"violations": 3')
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
        ->expectsOutputToContain('"violations": 3')
        ->assertExitCode(1);
});

it('keeps the dependency cycles a module takes part in', function () {
    $exitCode = Artisan::call('cordon:verify', ['--module' => ['Catalog'], '--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and(array_column($report['violations'], 'rule'))->toBe(['cycles'])
        ->and($report['violations'][0]['target'])->toBe('Billing -> Catalog -> Billing');
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
