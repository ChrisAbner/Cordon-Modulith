<?php

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
        'Unknown key [rule] in config/cordon.php. Did you mean [rules]? Valid keys: resolver, resolvers, public_namespaces, modules, rules, listeners, exclude, baseline.',
    ]);
});

it('leaves the suggestion out when no key is close', function () {
    expect(config_errors(['colour' => 'red']))->toBe([
        'Unknown key [colour] in config/cordon.php. Valid keys: resolver, resolvers, public_namespaces, modules, rules, listeners, exclude, baseline.',
    ]);
});

it('rejects an unknown module key', function () {
    expect(config_errors(['modules' => ['Billing' => ['depend_on' => ['Catalog']]]]))->toBe([
        'Unknown key [depend_on] for module [Billing]. Did you mean [depends_on]? Valid keys: depends_on, public, open, metadata.',
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
