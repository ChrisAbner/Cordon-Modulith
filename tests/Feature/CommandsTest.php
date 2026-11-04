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
