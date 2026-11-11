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
        'Unknown key [rule] in config/cordon.php. Did you mean [rules]? Valid keys: resolver, resolvers, public_namespaces, modules, host, layers, rules, listeners, exclude, baseline.',
    ]);
});

it('leaves the suggestion out when no key is close', function () {
    expect(config_errors(['colour' => 'red']))->toBe([
        'Unknown key [colour] in config/cordon.php. Valid keys: resolver, resolvers, public_namespaces, modules, host, layers, rules, listeners, exclude, baseline.',
    ]);
});

it('rejects an unknown module key', function () {
    expect(config_errors(['modules' => ['Billing' => ['depend_on' => ['Catalog']]]]))->toBe([
        'Unknown key [depend_on] for module [Billing]. Did you mean [depends_on]? Valid keys: depends_on, public, open, strict, metadata.',
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

it('reserves the Module::Interface syntax in depends_on', function () {
    expect(config_errors(['modules' => ['Billing' => ['depends_on' => ['Catalog::Contracts']]]]))->toBe([
        'Module [Billing] depends on [Catalog::Contracts]: the Module::Interface syntax is reserved for named interfaces in a later version.',
    ]);
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
    'an unknown key' => [['path' => ['app']], 'Unknown key [path] in host. Did you mean [paths]? Valid keys: paths.'],
    'paths not a list of strings' => [['paths' => 'app'], 'host.paths must be a list of directories, relative to the project root.'],
    'a path that is not a string' => [['paths' => ['app', 3]], 'host.paths must be a list of directories, relative to the project root.'],
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
        'Unknown key [rule] in config/cordon.php. Did you mean [rules]? Valid keys: resolver, resolvers, public_namespaces, modules, host, layers, rules, listeners, exclude, baseline.',
        'Unknown rule [cycle] in rules. Did you mean [cycles]? Built-in rules: internal_access, undeclared_dependency, cycles, unused_dependency, host_access, layers, public_api_leak.',
    ]);
});
