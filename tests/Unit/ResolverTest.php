<?php

use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Resolvers\InternachiResolver;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Resolvers\NwidartResolver;

it('resolves plain namespace modules', function () {
    $modules = (new NamespaceResolver(fixture_path('namespace-app/app/Modules'), 'App\\Modules'))->resolve();

    expect($modules->names())->toBe(['Billing', 'Catalog', 'Shared'])
        ->and($modules->get('Billing')?->namespace)->toBe('App\\Modules\\Billing');
});

it('resolves nwidart modules that have a module.json', function () {
    $modules = (new NwidartResolver(fixture_path('nwidart-app/Modules'), 'Modules'))->resolve();

    expect($modules->names())->toBe(['Blog', 'Shop'])
        ->and($modules->get('Blog')?->namespace)->toBe('Modules\\Blog')
        ->and(NwidartResolver::detect(fixture_path('nwidart-app/Modules')))->toBeTrue()
        ->and(NwidartResolver::detect(fixture_path('namespace-app/app/Modules')))->toBeFalse();
});

it('resolves InterNACHI modules from composer.json', function () {
    $modules = (new InternachiResolver(fixture_path('internachi-app/app-modules')))->resolve();

    expect($modules->names())->toBe(['billing', 'orders'])
        ->and($modules->get('billing')?->namespace)->toBe('Modules\\Billing')
        ->and($modules->get('orders')?->namespace)->toBe('Modules\\Orders');
});

it('maps classes and files to the most specific module', function () {
    $modules = new ModuleMap([
        Module::make('App', 'App', '/project/app'),
        Module::make('Billing', 'App\\Billing', '/project/app/Billing'),
    ]);

    expect($modules->forClass('App\\Billing\\Invoice')?->name)->toBe('Billing')
        ->and($modules->forClass('App\\Other\\Thing')?->name)->toBe('App')
        ->and($modules->forClass('Vendor\\Package\\Thing'))->toBeNull()
        ->and($modules->forPath('/project/app/Billing/Invoice.php')?->name)->toBe('Billing')
        ->and($modules->forPath('/project/app/Kernel.php')?->name)->toBe('App');
})->skipOnWindows();

it('applies per-module config and warns about unknown modules', function () {
    $modules = (new NamespaceResolver(fixture_path('namespace-app/app/Modules'), 'App\\Modules'))->resolve();
    $config = [
        'Billing' => ['depends_on' => ['Catalog', 'Ghost'], 'public' => ['Services\\CheckoutService']],
        'Nope' => ['open' => true],
    ];

    $configured = $modules->configure($config);

    expect($configured->get('Billing')?->dependsOn)->toBe(['Catalog', 'Ghost'])
        ->and($configured->get('Billing')?->publicApi)->toBe(['Services\\CheckoutService'])
        ->and($configured->get('Catalog')?->dependsOn)->toBeNull()
        ->and($modules->configurationWarnings($config))->toHaveCount(2);
});
