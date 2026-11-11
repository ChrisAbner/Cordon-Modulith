<?php

use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Support\ConfigValidator;

function namespace_app_modules(): ModuleMap
{
    return (new NamespaceResolver(fixture_path('namespace-app/app/Modules'), 'App\\Modules'))->resolve();
}

/**
 * @param  array<string, mixed>  $config
 * @return list<string>
 */
function config_errors(array $config): array
{
    return ConfigValidator::errors($config, namespace_app_modules());
}

it('accepts the default config', function () {
    expect(config_errors(require dirname(__DIR__, 2).'/config/cordon.php'))->toBe([]);
});

it('accepts the per-module settings of the fixture app', function () {
    expect(config_errors(['modules' => [
        'Billing' => ['depends_on' => ['Catalog', 'Shared'], 'public' => ['Services\\BillingService'], 'metadata' => ['owner' => 'payments']],
        'Catalog' => ['depends_on' => null],
        'Shared' => ['open' => true],
    ], 'listeners' => ['cycles' => true, 'depends_on' => false]]))->toBe([]);
});

it('rejects an unknown top-level key and suggests the closest one', function () {
    expect(config_errors(['rule' => []]))->toBe([
        'Unknown key [rule] in config/cordon.php. Did you mean [rules]? Valid keys: resolver, resolvers, public_namespaces, modules, host, layers, packages, rules, listeners, exclude, baseline, cache, docblocks.',
    ]);
});

it('leaves the suggestion out when no key is close', function () {
    expect(config_errors(['colour' => 'red']))->toBe([
        'Unknown key [colour] in config/cordon.php. Valid keys: resolver, resolvers, public_namespaces, modules, host, layers, packages, rules, listeners, exclude, baseline, cache, docblocks.',
    ]);
});

it('rejects an unknown module key', function () {
    expect(config_errors(['modules' => ['Billing' => ['depend_on' => ['Catalog']]]]))->toBe([
        'Unknown key [depend_on] for module [Billing]. Did you mean [depends_on]? Valid keys: depends_on, public, open, strict, nested, interfaces, packages, tests, metadata.',
    ]);
});

it('rejects an unknown module', function () {
    expect(config_errors(['modules' => ['Biling' => ['open' => true]]]))->toBe([
        'config/cordon.php configures unknown module [Biling]. Did you mean [Billing]?',
    ]);
});

it('rejects a dependency on an unknown module', function () {
    expect(config_errors(['modules' => ['Billing' => ['depends_on' => ['Catalgo', 'Inventory']]]]))->toBe([
        'Module [Billing] declares a dependency on unknown module [Catalgo]. Did you mean [Catalog]?',
        'Module [Billing] declares a dependency on unknown module [Inventory].',
    ]);
});

it('accepts Module::Interface in depends_on only for an interface the module defines', function () {
    expect(config_errors(['modules' => ['Billing' => ['depends_on' => ['Catalog::Contracts']]]]))->toBe([
        'Module [Billing] depends on [Catalog::Contracts], but module [Catalog] defines no interfaces. Add interfaces to its settings in config/cordon.php, or depend on [Catalog].',
    ])
        ->and(config_errors(['modules' => ['Catalog' => ['interfaces' => ['Contracts' => ['Contracts\\*']]], 'Billing' => ['depends_on' => ['Catalog::Contracts', 'Catalog::*']]]]))->toBe([]);
});

it('rejects settings of the wrong type', function () {
    expect(config_errors([
        'modules' => [
            'Billing' => ['depends_on' => 'Catalog', 'open' => 'yes', 'public' => 'Services', 'metadata' => 'payments'],
            'Catalog' => true,
        ],
        'rules' => 'all',
    ]))->toBe([
        'rules in config/cordon.php must be an array.',
        'Module [Billing]: depends_on must be a list of module names, or null to leave dependencies open.',
        'Module [Billing]: public must be a list of namespaces or classes.',
        'Module [Billing]: open must be true or false.',
        'Module [Billing]: metadata must be an array.',
        'Module [Catalog]: its settings must be an array.',
    ]);
});

it('rejects unknown listener keys and values that are not booleans', function () {
    expect(config_errors(['listeners' => ['cycle' => false, 'depends_on' => 'no']]))->toBe([
        'Unknown key [cycle] in listeners. Did you mean [cycles]? Valid keys: cycles, depends_on.',
        'listeners.depends_on must be true or false.',
    ]);
});

it('validates the host settings', function (mixed $host, string $error) {
    expect(config_errors(['host' => $host]))->toContain($error);
})->with([
    'not an array' => ['app', 'host in config/cordon.php must be an array.'],
    'an unknown key' => [['path' => ['app']], 'Unknown key [path] in host. Did you mean [paths]? Valid keys: paths, tests.'],
    'paths not a list of strings' => [['paths' => 'app'], 'host.paths must be a list of directories, relative to the project root.'],
    'a path that is not a string' => [['paths' => ['app', 3]], 'host.paths must be a list of directories, relative to the project root.'],
    'tests not a list of strings' => [['tests' => 'tests/Feature'], 'host.tests must be a list of directories, relative to the project root.'],
]);

it('reserves the module name host while the code outside the modules is checked', function () {
    $modules = new ModuleMap([Module::make('host', 'App\\Modules\\host', '/p/app/Modules/host')]);

    expect(ConfigValidator::errors([], $modules))->toBe(['A module cannot be named [host] while host.paths is set: the name is reserved for the code outside the modules. Rename the module or set host.paths to [].'])
        ->and(ConfigValidator::errors(['host' => ['paths' => []]], $modules))->toBe([]);
});

it('validates the layers', function (mixed $layers, string $error) {
    expect(config_errors(['layers' => $layers]))->toContain($error);
})->with([
    'not a list of layers' => ['Billing', 'layers must be a list of layers from the top down, each a list of module names.'],
    'a layer that is not a list' => [[['Billing'], 'Catalog'], 'layers must be a list of layers from the top down, each a list of module names.'],
    'an empty layer' => [[['Billing'], []], 'Layer 2 in layers is empty.'],
    'an unknown module' => [[['Biling']], 'layers lists unknown module [Biling]. Did you mean [Billing]?'],
    'a module in two layers' => [[['Billing', 'Catalog'], ['Billing']], 'Module [Billing] is in layers 1 and 2; a module belongs to one layer.'],
]);

it('accepts layers that cover some of the modules', function () {
    expect(config_errors(['layers' => [['Billing'], ['Shared']]]))->toBe([]);
});

it('requires tests to be a list of folders', function () {
    expect(config_errors(['modules' => ['Billing' => ['tests' => 'tests/Billing']]]))->toBe(['Module [Billing]: tests must be a list of directories, relative to the project root.'])
        ->and(config_errors(['modules' => ['Billing' => ['tests' => ['tests/Billing', 'tests/Unit']]]]))->toBe([])
        ->and(config_errors(['modules' => ['Billing' => ['tests' => []]]]))->toBe([])
        ->and(config_errors(['host' => ['tests' => ['tests/Feature']]]))->toBe([]);
});

it('requires strict to be true or false', function () {
    expect(config_errors(['modules' => ['Billing' => ['strict' => 'yes']]]))->toBe(['Module [Billing]: strict must be true or false.'])
        ->and(config_errors(['modules' => ['Billing' => ['strict' => true]]]))->toBe([]);
});

it('rejects depends_on and public entries that are not names, without crashing (M1)', function () {
    expect(config_errors(['modules' => ['Billing' => ['depends_on' => ['Catalog', ['Shared']], 'public' => ['Services\\Billing', 3]]]]))->toBe([
        'Module [Billing]: depends_on must be a list of module names, or null to leave dependencies open.',
        'Module [Billing]: public must be a list of namespaces or classes.',
    ])
        ->and(Module::make('Billing', 'B', '/p/B')->withConfig(['depends_on' => ['Catalog', ['Shared']], 'public' => [3, 'X']])->dependsOn)->toBe(['Catalog']);
});

it('validates the root keys and the rules even without modules (M9)', function () {
    expect(ConfigValidator::errors(['rule' => [], 'rules' => ['cycle' => true], 'modules' => ['Billing' => []]], new ModuleMap([])))->toBe([
        'Unknown key [rule] in config/cordon.php. Did you mean [rules]? Valid keys: resolver, resolvers, public_namespaces, modules, host, layers, packages, rules, listeners, exclude, baseline, cache, docblocks.',
        'Unknown rule [cycle] in rules. Did you mean [cycles]? Built-in rules: internal_access, undeclared_dependency, cycles, unused_dependency, host_access, layers, public_api_leak, external_dependency.',
    ]);
});

it('accepts a cache path or null and rejects anything else', function () {
    expect(config_errors(['cache' => null]))->toBe([])
        ->and(config_errors(['cache' => 'storage/framework/cache/cordon.json']))->toBe([])
        ->and(config_errors(['cache' => true]))->toBe(['cache in config/cordon.php must be a file path or null.'])
        ->and(config_errors(['cache' => '']))->toBe(['cache in config/cordon.php must be a file path or null.'])
        ->and(config_errors(['cache' => ['a.json']]))->toBe(['cache in config/cordon.php must be a file path or null.']);
});

it('suggests cache for a misspelled key', function () {
    expect(config_errors(['cach' => 'a.json']))->toBe([
        'Unknown key [cach] in config/cordon.php. Did you mean [cache]? Valid keys: resolver, resolvers, public_namespaces, modules, host, layers, packages, rules, listeners, exclude, baseline, cache, docblocks.',
    ]);
});

it('accepts docblocks as true or false and rejects anything else', function () {
    expect(config_errors(['docblocks' => true]))->toBe([])
        ->and(config_errors(['docblocks' => false]))->toBe([])
        ->and(config_errors(['docblocks' => 'yes']))->toBe(['docblocks in config/cordon.php must be true or false.'])
        ->and(config_errors(['docblocks' => 1]))->toBe(['docblocks in config/cordon.php must be true or false.'])
        ->and(config_errors(['docblocks' => null]))->toBe(['docblocks in config/cordon.php must be true or false.']);
});

it('suggests docblocks for a misspelled key', function () {
    expect(config_errors(['docblock' => true]))->toBe([
        'Unknown key [docblock] in config/cordon.php. Did you mean [docblocks]? Valid keys: resolver, resolvers, public_namespaces, modules, host, layers, packages, rules, listeners, exclude, baseline, cache, docblocks.',
    ]);
});

// packages and external_dependency

/**
 * @param  array<string, mixed>  $config
 * @return list<string>
 */
function package_errors(array $config, ?string $basePath = null): array
{
    return ConfigValidator::errors($config, namespace_app_modules(), $basePath);
}

it('accepts packages and shared packages, with a trailing wildcard', function () {
    expect(package_errors([
        'packages' => ['shared' => ['laravel/framework', 'illuminate/*', 'psr/log']],
        'modules' => ['Billing' => ['packages' => ['stripe/stripe-php', 'symfony/*', 'laravel/pa*']], 'Catalog' => ['packages' => []]],
    ]))->toBe([])
        ->and(package_errors(['packages' => ['shared' => ['*']], 'modules' => ['Billing' => ['packages' => ['*']]]]))->toBe([])
        ->and(package_errors(['packages' => []]))->toBe([]);
});

it('rejects packages settings of the wrong shape', function (array $config, string $message) {
    expect(package_errors($config))->toBe([$message]);
})->with([
    'not an array' => [['packages' => 'laravel/framework'], 'packages in config/cordon.php must be an array.'],
    'a list instead of a map' => [['packages' => ['laravel/framework']], 'Unknown key [0] in packages. Valid keys: shared.'],
    'a misspelled key' => [['packages' => ['share' => ['a/b']]], 'Unknown key [share] in packages. Did you mean [shared]? Valid keys: shared.'],
    'shared not a list' => [['packages' => ['shared' => 'a/b']], 'packages.shared must be a list of package names, such as "vendor/name" or "vendor/*".'],
    'shared with a non-string' => [['packages' => ['shared' => ['a/b', 3]]], 'packages.shared must be a list of package names, such as "vendor/name" or "vendor/*".'],
    'a module list that is not a list' => [['modules' => ['Billing' => ['packages' => 'a/b']]], 'Module [Billing]: packages must be a list of package names, such as "vendor/name" or "vendor/*".'],
    'a module null' => [['modules' => ['Billing' => ['packages' => null]]], 'Module [Billing]: packages must be a list of package names, such as "vendor/name" or "vendor/*".'],
    'a name without vendor' => [['modules' => ['Billing' => ['packages' => ['stripe']]]], 'Module [Billing]: packages lists [stripe], which is not a package name. Write vendor/name, or vendor/* for every package of a vendor.'],
    'a wildcard in the middle' => [['packages' => ['shared' => ['illu*/support']]], 'packages.shared lists [illu*/support], which is not a package name. Write vendor/name, or vendor/* for every package of a vendor.'],
    'two wildcards' => [['packages' => ['shared' => ['a/**']]], 'packages.shared lists [a/**], which is not a package name. Write vendor/name, or vendor/* for every package of a vendor.'],
    'an empty name' => [['packages' => ['shared' => ['']]], 'packages.shared lists [], which is not a package name. Write vendor/name, or vendor/* for every package of a vendor.'],
]);

it('says that the code outside the modules has no packages setting', function () {
    expect(package_errors(['modules' => ['host' => ['packages' => ['a/b']]]]))->toBe([
        'Module [host] cannot list packages: host is the code outside the modules, which external_dependency does not check. Set packages on the modules instead.',
    ]);
});

it('needs installed.json only when a module lists packages', function () {
    $root = temp_project();

    expect(package_errors([], $root))->toBe([])
        ->and(package_errors(['packages' => ['shared' => ['a/b']]], $root))->toBe([])
        ->and(package_errors(['modules' => ['Billing' => ['depends_on' => null]]], $root))->toBe([])
        ->and(package_errors(['modules' => ['Billing' => ['packages' => ['a/b']]]], $root))->toHaveCount(1)
        ->and(package_errors(['modules' => ['Billing' => ['packages' => ['a/b']]]], $root)[0])->toContain('vendor/composer/installed.json does not exist')->toContain('composer install')
        ->and(package_errors(['modules' => ['Billing' => ['packages' => ['a/b']]]]))->toBe([])
        ->and(package_errors(['modules' => ['Billing' => ['packages' => ['stripe/stripe-php']]]], fixture_path('packages-app')))->toBe([]);
});

it('warns about a listed package that is not installed, and suggests the closest name', function () {
    $app = fixture_path('packages-app');
    $warnings = ConfigValidator::warnings([
        'packages' => ['shared' => ['laravel/framework', 'illuminate/*', 'laravel/frameworc']],
        'modules' => ['Billing' => ['packages' => ['stripe/stripe-php', 'stripe/strip-php']], 'Catalog' => ['packages' => ['guzzlehttp/guzzle']]],
    ], namespace_app_modules(), $app);

    expect($warnings)->toBe([
        'packages.shared lists [laravel/frameworc], which is not installed. Did you mean [laravel/framework]? Check the name, or run composer install.',
        'Module [Billing] lists package [stripe/strip-php], which is not installed. Did you mean [stripe/stripe-php]? Check the name, or run composer install.',
    ])
        ->and(ConfigValidator::warnings([], namespace_app_modules(), $app))->toBe([])
        ->and(ConfigValidator::warnings(['modules' => ['Billing' => ['packages' => ['a/b']]]], namespace_app_modules(), temp_project()))->toBe([]);
});
