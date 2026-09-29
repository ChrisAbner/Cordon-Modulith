<?php

use Cordon\Testing\Cordon;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(fn () => Cordon::flush());

afterEach(function () {
    @unlink((string) config('cordon.baseline'));
});

it('lists the detected modules', function () {
    expect(Cordon::modules())->toBe(['Billing', 'Catalog', 'Shared']);
});

it('passes for a module without violations', function () {
    expect('Shared')->toRespectBoundaries();
});

it('fails with every violation of the module', function () {
    expect(fn () => expect('Billing')->toRespectBoundaries())
        ->toThrow(AssertionFailedError::class, 'Module [Billing] does not respect its boundaries (3 violations)');
});

it('includes the dependency cycles the module takes part in', function () {
    expect(fn () => expect('Catalog')->toRespectBoundaries())
        ->toThrow(AssertionFailedError::class, '[cycles]');
});

it('works on every module with each', function () {
    expect(fn () => expect(Cordon::modules())->each->toRespectBoundaries())
        ->toThrow(AssertionFailedError::class, 'Module [Billing]');
});

it('rejects unknown modules', function () {
    expect(fn () => expect('Ghost')->toRespectBoundaries())
        ->toThrow(AssertionFailedError::class, 'Unknown module [Ghost]. Detected modules: Billing, Catalog, Shared.');
});

it('respects the baseline', function () {
    $this->artisan('cordon:verify', ['--generate-baseline' => true])->assertExitCode(0);

    expect('Billing')->toRespectBoundaries();
});
