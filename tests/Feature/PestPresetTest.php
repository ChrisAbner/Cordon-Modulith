<?php

use Cordon\Testing\Cordon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(fn () => Cordon::flush());

/**
 * The message Cordon::assertBoundaries() fails with, or null when it passes.
 */
function boundaries_failure(): ?string
{
    try {
        Cordon::assertBoundaries();
    } catch (AssertionFailedError $failure) {
        return $failure->getMessage();
    }

    return null;
}

it('fails with every module that crosses its boundaries, in one message', function () {
    config()->set('cordon.listeners', ['cycles' => true]);

    expect(boundaries_failure())
        ->toContain('Module [Billing] does not respect its boundaries')
        ->toContain('Module [Catalog] does not respect its boundaries')
        ->not->toContain('Module [Shared] does not respect');
});

it('passes when no module has violations', function () {
    config()->set('cordon.rules', ['internal_access' => 'off', 'undeclared_dependency' => 'off', 'cycles' => 'off']);

    expect(boundaries_failure())->toBeNull();
});

it('fails instead of passing on an empty project or a broken config', function (Closure $break, string $message) {
    $break();

    expect(boundaries_failure())->toContain($message);
})->with([
    'no modules' => [fn () => config()->set('cordon.resolvers.namespace.path', sys_get_temp_dir().'/cordon-no-modules-'.getmypid()), 'Cordon Modulith found no modules'],
    'a typo in the config' => [fn () => config()->set('cordon.modules', ['Shared' => ['open' => true], 'Catalog' => ['depend_on' => []]]), 'config/cordon.php has errors'],
]);

it('publishes a one-line test file', function () {
    $file = base_path('tests/Feature/ModuleBoundariesTest.php');
    @unlink($file);

    Artisan::call('vendor:publish', ['--tag' => 'cordon-tests']);

    try {
        expect(file_get_contents($file))->toContain("it('keeps every module within its boundaries', fn () => Cordon::assertBoundaries());");
    } finally {
        @unlink($file);
    }
});
