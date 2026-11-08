<?php

use Illuminate\Support\Facades\Artisan;

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function explore(string $command, array $arguments): array
{
    $code = Artisan::call($command, $arguments);

    return [$code, Artisan::output()];
}

it('explains why a class is internal and who uses it', function () {
    [$code, $output] = explore('cordon:explain', ['class' => 'App\\Modules\\Catalog\\Models\\Product']);

    expect($code)->toBe(0)
        ->and($output)->toContain('Module:  Catalog')
        ->toContain('internal: it is not in a public namespace, not marked #[PublicApi], and its module is not open')
        ->toContain('x Billing')
        ->toContain('CheckoutService.php:13 (use)');
});

it('explains why a class is public', function () {
    [$code, $output] = explore('cordon:explain', ['class' => '\\App\\Modules\\Catalog\\Contracts\\ProductCatalog', '--format' => 'json']);
    $report = json_decode($output, true);

    expect($code)->toBe(0)
        ->and($report)->toMatchArray(['class' => 'App\\Modules\\Catalog\\Contracts\\ProductCatalog', 'module' => 'Catalog', 'public' => true, 'reason' => 'it is under the public namespace [Contracts]'])
        ->and($report['used_by'][0])->toMatchArray(['module' => 'Billing', 'line' => 11, 'kind' => 'use', 'violation' => false]);
});

it('says when a class is outside every module or not declared', function (string $class, string $message) {
    [$code, $output] = explore('cordon:explain', ['class' => $class]);

    expect($code)->toBe(2)->and($output)->toContain($message);
})->with([
    'outside the modules' => ['App\\Http\\Controllers\\HomeController', '[App\\Http\\Controllers\\HomeController] does not belong to any module.'],
    'not declared' => ['App\\Modules\\Billing\\Nope', '[App\\Modules\\Billing\\Nope] is not declared in module [Billing].'],
]);

it('lists why one module depends on another', function () {
    [$code, $output] = explore('cordon:why', ['from' => 'Billing', 'to' => 'Catalog']);

    expect($code)->toBe(0)
        ->and($output)->toContain('Billing -> Catalog: 4 references')
        ->toContain('CheckoutService.php:11  App\\Modules\\Catalog\\Contracts\\ProductCatalog (use)')
        ->toContain('Billing does not declare depends_on, so it may depend on any module.');

    config()->set('cordon.modules', ['Shared' => ['open' => true], 'Billing' => ['depends_on' => []]]);

    [, $output] = explore('cordon:why', ['from' => 'Billing', 'to' => 'Catalog', '--format' => 'json']);

    expect(json_decode($output, true))->toMatchArray(['from' => 'Billing', 'to' => 'Catalog', 'allowed' => false]);
});

it('says when a module does not depend on another', function () {
    [$code, $output] = explore('cordon:why', ['from' => 'Shared', 'to' => 'Catalog']);

    expect($code)->toBe(0)->and($output)->toContain('Shared does not depend on Catalog.');
});

it('rejects unknown modules with a suggestion', function (string $command, array $arguments) {
    [$code, $output] = explore($command, $arguments);

    expect($code)->toBe(2)->and($output)->toContain('Unknown module [Biling]. Did you mean [Billing]?');
})->with([
    'why' => ['cordon:why', ['from' => 'Biling', 'to' => 'Catalog']],
    'impact' => ['cordon:impact', ['module' => 'Biling']],
]);

it('lists the modules a change to a module can affect', function () {
    [$code, $output] = explore('cordon:impact', ['module' => 'Catalog']);

    expect($code)->toBe(0)
        ->and($output)->toContain('Modules that depend on Catalog:')
        ->toContain('1  Billing (directly)');

    [, $output] = explore('cordon:impact', ['module' => 'Shared', '--format' => 'json']);

    // Catalog listens to Billing's events, and Billing uses Shared.
    expect(json_decode($output, true))->toBe(['module' => 'Shared', 'dependents' => [
        ['module' => 'Billing', 'distance' => 1, 'through' => null],
        ['module' => 'Catalog', 'distance' => 2, 'through' => 'Billing'],
    ]]);
});
