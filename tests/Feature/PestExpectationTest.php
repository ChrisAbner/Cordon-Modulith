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
        ->toThrow(AssertionFailedError::class, 'Module [Billing] does not respect its boundaries (2 violations)');
});

it('includes the dependency cycles the module takes part in', function () {
    config()->set('cordon.listeners', ['cycles' => true]);

    expect(fn () => expect('Catalog')->toRespectBoundaries())
        ->toThrow(AssertionFailedError::class, '[cycles]');
});

it('works on every module with each', function () {
    expect(fn () => expect(Cordon::modules())->each->toRespectBoundaries())
        ->toThrow(AssertionFailedError::class, 'Module [Billing]');
});

it('fails when the config has errors, as cordon:verify does', function () {
    config()->set('cordon.modules', ['Shared' => ['open' => true], 'Catalog' => ['depend_on' => []]]);

    expect(fn () => expect('Catalog')->toRespectBoundaries())
        ->toThrow(AssertionFailedError::class, 'Unknown key [depend_on] for module [Catalog]. Did you mean [depends_on]?');
});

it('rejects unknown modules', function () {
    expect(fn () => expect('Ghost')->toRespectBoundaries())
        ->toThrow(AssertionFailedError::class, 'Unknown module [Ghost]. Detected modules: Billing, Catalog, Shared.');
});

it('respects the baseline', function () {
    $this->artisan('cordon:verify', ['--generate-baseline' => true])->assertExitCode(0);

    expect('Billing')->toRespectBoundaries();
});

it('fails on a @cordon-ignore without a reason, as cordon:verify does', function () {
    $project = temp_project('ignore-app');
    add_unexplained_ignore($project);
    config()->set('cordon.resolvers.namespace.path', $project.'/app/Modules');
    config()->set('cordon.modules', []);
    Cordon::flush();

    expect(fn () => expect('Catalog')->toRespectBoundaries())
        ->toThrow(AssertionFailedError::class, 'Unexplained.php:5 needs a reason in parentheses');
});
