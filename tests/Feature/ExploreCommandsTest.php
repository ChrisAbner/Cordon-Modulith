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

    config()->set('cordon.modules', ['Shared' => ['open' => true, 'depends_on' => []]]);

    [, $output] = explore('cordon:why', ['from' => 'Shared', 'to' => 'Catalog', '--format' => 'json']);

    expect(json_decode($output, true))->toMatchArray(['references' => [], 'reported' => false, 'reason' => 'Shared does not depend on Catalog.']);
});

/**
 * The reason cordon:why gives for Billing -> Catalog (four uses) or
 * Catalog -> Billing (one listener), with neither declaring depends_on.
 *
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function why_report(string $from, string $to, array $config = []): array
{
    config()->set('cordon.modules', ['Shared' => ['open' => true], 'Billing' => ['depends_on' => []], 'Catalog' => ['depends_on' => []]]);

    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    return json_decode(explore('cordon:why', ['from' => $from, 'to' => $to, '--format' => 'json'])[1], true);
}

it('says that verify reports an undeclared dependency only when it does', function () {
    expect(why_report('Billing', 'Catalog'))->toMatchArray([
        'allowed' => false,
        'reported' => true,
        'reason' => 'Billing does not list Catalog in depends_on: cordon:verify reports it as undeclared_dependency.',
    ])
        ->and(why_report('Billing', 'Catalog', ['cordon.rules.undeclared_dependency' => 'off']))->toMatchArray([
            'reported' => false,
            'reason' => 'Billing does not list Catalog in depends_on, but cordon:verify does not report it: undeclared_dependency is off.',
        ]);
});

it('says when an undeclared dependency is in the baseline', function () {
    why_report('Billing', 'Catalog');
    Artisan::call('cordon:verify', ['--generate-baseline' => true]);

    try {
        expect(why_report('Billing', 'Catalog'))->toMatchArray([
            'reported' => false,
            'reason' => 'Billing does not list Catalog in depends_on, but cordon:verify does not report it: the baseline records it.',
        ]);
    } finally {
        @unlink((string) config('cordon.baseline'));
    }
});

it('says when depends_on does not count the listeners that make a dependency', function () {
    expect(why_report('Catalog', 'Billing', ['cordon.listeners.depends_on' => false]))->toMatchArray([
        'allowed' => true,
        'reported' => false,
        'reason' => 'Catalog does not list Billing in depends_on, but the references are listeners, which depends_on does not count (listeners.depends_on is false).',
    ])
        ->and(why_report('Catalog', 'Billing', ['cordon.listeners.depends_on' => true]))->toMatchArray(['allowed' => false, 'reported' => true]);
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

it('marks the uses that cordon:verify reports, after the severity and the baseline', function () {
    $uses = fn () => array_column(json_decode(explore('cordon:explain', ['class' => 'App\\Modules\\Catalog\\Models\\Product', '--format' => 'json'])[1], true)['used_by'], 'violation');

    expect($uses())->toBe([true])
        ->and(explore('cordon:explain', ['class' => 'App\\Modules\\Catalog\\Models\\Product'])[1])->toContain('x Billing');

    config()->set('cordon.rules.internal_access', 'warn');
    expect(explore('cordon:explain', ['class' => 'App\\Modules\\Catalog\\Models\\Product'])[1])->toContain('! Billing');

    config()->set('cordon.rules.internal_access', 'off');
    expect($uses())->toBe([false]);

    config()->set('cordon.rules.internal_access', 'error');
    Artisan::call('cordon:verify', ['--generate-baseline' => true]);

    try {
        expect($uses())->toBe([false]);
    } finally {
        @unlink((string) config('cordon.baseline'));
    }
});

it('rejects unknown formats', function (string $command, array $arguments) {
    [$code, $output] = explore($command, [...$arguments, '--format' => 'xml']);

    expect($code)->toBe(2)->and($output)->toContain('Unknown format [xml]. Use text or json.');
})->with([
    'explain' => ['cordon:explain', ['class' => 'App\\Modules\\Catalog\\Models\\Product']],
    'why' => ['cordon:why', ['from' => 'Billing', 'to' => 'Catalog']],
    'impact' => ['cordon:impact', ['module' => 'Catalog']],
]);
