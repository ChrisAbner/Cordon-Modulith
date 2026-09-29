<?php

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
