<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Documentation\GraphExporter;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Resolvers\NestedResolver;
use Cordon\Rules\RuleSet;
use Cordon\Rules\UndeclaredDependencyRule;
use Cordon\Support\ConfigValidator;

mutates(PublicApiPolicy::class, UndeclaredDependencyRule::class);

const CATALOG_INTERFACES = ['interfaces' => [
    'Pricing' => ['Contracts\\PriceList', 'Data\\Price*'],
    'Inventory' => ['Contracts\\Stock'],
]];

const STOCK_MESSAGE = 'Module [Billing] uses App\\Modules\\Catalog\\Contracts\\Stock, which is not in the interfaces it declares on [Catalog] (%s).';
const UNLISTED_MESSAGE = 'Module [Billing] uses App\\Modules\\Catalog\\Contracts\\Unlisted, which is not in the interfaces it declares on [Catalog] (%s).';

/**
 * The modules of tests/Fixtures/interfaces-app (or a copy of it), with the given settings.
 *
 * @param  array<string, mixed>  $moduleConfig
 */
function interfaces_modules(array $moduleConfig, ?string $root = null, bool $configured = true): ModuleMap
{
    $modules = (new NamespaceResolver(($root ?? fixture_path('interfaces-app')).'/app/Modules', 'App\\Modules'))->resolve();

    return $configured ? $modules->configure($moduleConfig) : $modules;
}

/**
 * @param  array<string, mixed>  $moduleConfig
 * @param  array<array-key, mixed>  $rules
 * @param  array<string, bool>  $listeners
 */
function analyse_interfaces_app(array $moduleConfig, array $rules = [], array $listeners = [], ?string $root = null): Result
{
    $root ??= fixture_path('interfaces-app');
    $built = RuleSet::fromConfig($rules, null, $listeners);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $built, RuleSet::severities($rules, $built));

    return $analyzer->analyze(interfaces_modules($moduleConfig, $root), $root);
}

/**
 * @param  list<Violation>  $violations
 * @return list<string>
 */
function messages_of(array $violations, string $rule): array
{
    return array_values(array_map(
        fn (Violation $violation) => $violation->message,
        array_filter($violations, fn (Violation $violation) => $violation->rule === $rule),
    ));
}

/**
 * @return list<array{string, string, string|null, int|null, string|null}>
 */
function comparable(Result $result): array
{
    return array_map(
        fn (Violation $v) => [$v->rule, $v->message, $v->file === null ? null : basename($v->file), $v->line, $v->target],
        [...$result->violations, ...$result->warnings],
    );
}

// Catalog and Catalog::* are what depends_on meant before

it('treats Catalog and Catalog::* as the whole public API, as before', function () {
    $plain = analyse_interfaces_app(['Billing' => ['depends_on' => ['Catalog']]]);
    $whole = analyse_interfaces_app(['Catalog' => CATALOG_INTERFACES, 'Billing' => ['depends_on' => ['Catalog']]]);
    $star = analyse_interfaces_app(['Catalog' => CATALOG_INTERFACES, 'Billing' => ['depends_on' => ['Catalog::*']]]);

    expect(messages_of($plain->violations, 'undeclared_dependency'))->toBe([])
        ->and(comparable($whole))->toBe(comparable($plain))
        ->and(comparable($star))->toBe(comparable($plain));
});

it('still reports a module that is not listed, whether or not it defines interfaces', function () {
    foreach ([[], ['Catalog' => CATALOG_INTERFACES]] as $catalog) {
        $result = analyse_interfaces_app([...$catalog, 'Billing' => ['depends_on' => []]]);

        expect(messages_of($result->violations, 'undeclared_dependency'))->toBe([
            'Module [Billing] depends on module [Catalog] (via App\\Modules\\Catalog\\Contracts\\PriceList), but [Catalog] is not listed in its depends_on.',
        ]);
    }
});

it('does not change the result of a module without interfaces', function () {
    $without = analyse_interfaces_app(['Billing' => ['depends_on' => ['Catalog']]]);
    $empty = analyse_interfaces_app(['Catalog' => ['interfaces' => []], 'Billing' => ['depends_on' => ['Catalog']]]);

    expect(comparable($empty))->toBe(comparable($without))
        ->and($empty->interfaceErrors)->toBe([])
        ->and($empty->interfaceWarnings)->toBe([]);
});

// Catalog::Pricing

it('lets Billing use what Catalog::Pricing lists and reports the other public classes', function () {
    $result = analyse_interfaces_app(['Catalog' => CATALOG_INTERFACES, 'Billing' => ['depends_on' => ['Catalog::Pricing']]]);
    $violations = array_values(array_filter($result->violations, fn (Violation $v) => $v->rule === 'undeclared_dependency'));

    expect(array_map(fn (Violation $v) => $v->message, $violations))->toBe([
        sprintf(STOCK_MESSAGE, 'Pricing'),
        sprintf(UNLISTED_MESSAGE, 'Pricing'),
    ])
        ->and($violations[0])->toMatchObject(['sourceModule' => 'Billing', 'targetModule' => 'Catalog', 'target' => 'App\\Modules\\Catalog\\Contracts\\Stock', 'line' => 12])
        ->and(basename((string) $violations[0]->file))->toBe('Invoicer.php');
});

it('matches an exact class and a trailing * by prefix, ignoring case', function () {
    $modules = interfaces_modules(['Catalog' => ['interfaces' => ['Pricing' => ['contracts\\pricelist', 'Data\\Price*'], 'Namespace' => ['Events\\*']]]]);
    $catalog = $modules->get('Catalog');
    $class = fn (string $relative) => 'App\\Modules\\Catalog\\'.$relative;

    expect($catalog->interfacesOf($class('Contracts\\PriceList')))->toBe(['Pricing'])
        ->and($catalog->interfacesOf($class('Contracts\\PriceListExtra')))->toBe([])
        ->and($catalog->interfacesOf($class('Data\\Price')))->toBe(['Pricing'])
        ->and($catalog->interfacesOf($class('Data\\PriceTag')))->toBe(['Pricing'])
        ->and($catalog->interfacesOf($class('Data\\Prices\\Deep')))->toBe(['Pricing'])
        ->and($catalog->interfacesOf($class('Data\\Cost')))->toBe([])
        ->and($catalog->interfacesOf($class('Events\\Deep\\Down\\Changed')))->toBe(['Namespace'])
        ->and($catalog->interfacesOf('App\\Modules\\Billing\\Contracts\\PriceList'))->toBe([]);
});

it('accepts several interfaces of the same module', function () {
    $result = analyse_interfaces_app(['Catalog' => CATALOG_INTERFACES, 'Billing' => ['depends_on' => ['Catalog::Pricing', 'Catalog::Inventory']]]);

    expect(messages_of($result->violations, 'undeclared_dependency'))->toBe([sprintf(UNLISTED_MESSAGE, 'Pricing, Inventory')]);
});

it('lets a class that is in two interfaces through either of them', function () {
    $catalog = ['interfaces' => [...CATALOG_INTERFACES['interfaces'], 'Shared' => ['Contracts\\PriceList']]];

    foreach (['Catalog::Pricing', 'Catalog::Shared'] as $entry) {
        $result = analyse_interfaces_app(['Catalog' => $catalog, 'Billing' => ['depends_on' => [$entry]]]);
        $messages = messages_of($result->violations, 'undeclared_dependency');

        expect(array_filter($messages, fn (string $m) => str_contains($m, 'PriceList')))->toBe([]);
    }

    $result = analyse_interfaces_app(['Catalog' => $catalog, 'Billing' => ['depends_on' => ['Catalog::Pricing', 'Catalog::Shared']]], ['unused_dependency' => 'error']);

    expect(messages_of($result->violations, 'unused_dependency'))->toBe([]);
});

it('reports a public class that is in no interface', function () {
    $result = analyse_interfaces_app(['Catalog' => CATALOG_INTERFACES, 'Billing' => ['depends_on' => ['Catalog::Inventory']]]);

    expect(messages_of($result->violations, 'undeclared_dependency'))->toContain(sprintf(UNLISTED_MESSAGE, 'Inventory'))
        ->and(messages_of($result->violations, 'undeclared_dependency'))->toContain('Module [Billing] uses App\\Modules\\Catalog\\Contracts\\PriceList, which is not in the interfaces it declares on [Catalog] (Inventory).');
});

it('leaves an internal class to internal_access', function () {
    $root = temp_project('interfaces-app');
    file_put_contents($root.'/app/Modules/Billing/Services/Reader.php', "<?php\n\nnamespace App\\Modules\\Billing\\Services;\n\nuse App\\Modules\\Catalog\\Models\\Price;\n\nfinal class Reader\n{\n    public function __construct(Price \$price) {}\n}\n");
    $result = analyse_interfaces_app(['Catalog' => CATALOG_INTERFACES, 'Billing' => ['depends_on' => ['Catalog::Pricing']]], root: $root);

    expect(messages_of($result->violations, 'internal_access'))->toHaveCount(1)
        ->and(array_filter(messages_of($result->violations, 'undeclared_dependency'), fn (string $m) => str_contains($m, 'Models\\Price')))->toBe([]);
});

it('reports a class once per file, whatever the number of references and the letter case', function () {
    $root = temp_project('interfaces-app');
    // The third reference writes the class in lowercase: PHP reads it as the same class.
    file_put_contents($root.'/app/Modules/Billing/Services/Twice.php', "<?php\n\nnamespace App\\Modules\\Billing\\Services;\n\nuse App\\Modules\\Catalog\\Contracts\\Stock;\n\nfinal class Twice\n{\n    public function __construct(Stock \$a, Stock \$b, \\app\\modules\\catalog\\contracts\\stock \$c) {}\n}\n");
    $result = analyse_interfaces_app(['Catalog' => CATALOG_INTERFACES, 'Billing' => ['depends_on' => ['Catalog::Pricing']]], root: $root);
    $twice = array_filter($result->violations, fn (Violation $v) => basename((string) $v->file) === 'Twice.php');

    expect($twice)->toHaveCount(1);
});

// listeners

it('applies the interfaces to listeners as listeners.depends_on says', function () {
    $config = ['Catalog' => CATALOG_INTERFACES, 'Orders' => ['depends_on' => ['Catalog::Pricing']]];
    $message = 'Module [Orders] uses App\\Modules\\Catalog\\Events\\StockChanged, which is not in the interfaces it declares on [Catalog] (Pricing).';

    expect(messages_of(analyse_interfaces_app($config)->violations, 'undeclared_dependency'))->toBe([$message])
        ->and(messages_of(analyse_interfaces_app($config, listeners: ['depends_on' => true])->violations, 'undeclared_dependency'))->toBe([$message])
        ->and(messages_of(analyse_interfaces_app($config, listeners: ['depends_on' => false])->violations, 'undeclared_dependency'))->toBe([]);

    $config['Catalog']['interfaces']['Events'] = ['Events\\StockChanged'];
    $config['Orders']['depends_on'] = ['Catalog::Events'];

    expect(messages_of(analyse_interfaces_app($config)->violations, 'undeclared_dependency'))->toBe([])
        ->and(messages_of(analyse_interfaces_app($config, ['unused_dependency' => 'error'])->violations, 'unused_dependency'))->toBe([])
        ->and(messages_of(analyse_interfaces_app($config, ['unused_dependency' => 'error'], ['depends_on' => false])->violations, 'unused_dependency'))->toBe([
            'Module [Orders] lists [Catalog::Events] in depends_on, but does not use it. Remove it so depends_on shows the real dependencies.',
        ]);
});

// unused_dependency

it('warns about an interface in depends_on that nothing uses', function () {
    $config = ['Catalog' => [...CATALOG_INTERFACES, 'interfaces' => [...CATALOG_INTERFACES['interfaces'], 'Events' => ['Events\\*']]], 'Billing' => ['depends_on' => ['Catalog::Pricing', 'Catalog::Events']]];
    $result = analyse_interfaces_app($config);
    $unused = array_values(array_filter($result->warnings, fn (Violation $v) => $v->rule === 'unused_dependency'));

    expect(array_map(fn (Violation $v) => $v->message, $unused))->toBe([
        'Module [Billing] lists [Catalog::Events] in depends_on, but does not use it. Remove it so depends_on shows the real dependencies.',
    ])
        ->and($unused[0])->toMatchObject(['sourceModule' => 'Billing', 'targetModule' => 'Catalog', 'target' => 'Billing -> Catalog::Events']);
});

it('counts Catalog::* as used when anything of Catalog is used, and as unused otherwise', function () {
    $used = analyse_interfaces_app(['Catalog' => CATALOG_INTERFACES, 'Billing' => ['depends_on' => ['Catalog::*']]]);
    $unused = analyse_interfaces_app(['Catalog' => CATALOG_INTERFACES, 'Orders' => ['depends_on' => ['Billing::*']]]);

    expect(messages_of($used->warnings, 'unused_dependency'))->toBe([])
        ->and(messages_of($unused->warnings, 'unused_dependency'))->toBe([
            'Module [Orders] lists [Billing::*] in depends_on, but does not use it. Remove it so depends_on shows the real dependencies.',
        ]);
});

// validation

/**
 * @param  array<string, mixed>  $modules
 * @return list<string>
 */
function interface_config_errors(array $modules, bool $withInterfaces = true): array
{
    $config = $withInterfaces ? ['Catalog' => CATALOG_INTERFACES, ...$modules] : $modules;

    return ConfigValidator::errors(['modules' => $config], interfaces_modules([], configured: false));
}

it('accepts Module::Interface and Module::* in depends_on', function () {
    expect(interface_config_errors(['Billing' => ['depends_on' => ['Catalog::Pricing', 'Catalog::Inventory', 'Catalog::*']]]))->toBe([]);
});

it('rejects a Module::Interface the module does not define, with a suggestion', function () {
    expect(interface_config_errors(['Billing' => ['depends_on' => ['Catalog::Pricng']]]))->toBe([
        'Module [Billing] depends on [Catalog::Pricng], but module [Catalog] defines no interface [Pricng]. Did you mean [Catalog::Pricing]? Interfaces of [Catalog]: Pricing, Inventory.',
    ])
        ->and(interface_config_errors(['Billing' => ['depends_on' => ['Catalog::Unrelated']]]))->toBe([
            'Module [Billing] depends on [Catalog::Unrelated], but module [Catalog] defines no interface [Unrelated]. Interfaces of [Catalog]: Pricing, Inventory.',
        ]);
});

it('rejects an interface of a module that defines none', function () {
    expect(interface_config_errors(['Billing' => ['depends_on' => ['Catalog::Pricing']]], withInterfaces: false))->toBe([
        'Module [Billing] depends on [Catalog::Pricing], but module [Catalog] defines no interfaces. Add interfaces to its settings in config/cordon.php, or depend on [Catalog].',
    ]);
});

it('rejects an interface of an unknown module, with a suggestion', function () {
    expect(interface_config_errors(['Billing' => ['depends_on' => ['Catalogue::Pricing']]]))->toBe([
        'Module [Billing] declares a dependency on unknown module [Catalogue]. Did you mean [Catalog]?',
    ]);
});

it('rejects an empty interface name in depends_on', function () {
    expect(interface_config_errors(['Billing' => ['depends_on' => ['Catalog::']]]))->toBe([
        'Module [Billing] depends on [Catalog::], which names no interface. Write Catalog::Name, or Catalog::* for the whole public API.',
    ]);
});

it('checks the shape of interfaces', function (mixed $interfaces, string $error) {
    expect(interface_config_errors(['Catalog' => ['interfaces' => $interfaces]], withInterfaces: false))->toBe([$error]);
})->with([
    'not an array' => ['Pricing', 'Module [Catalog]: interfaces must be a map of interface name to a list of class names or namespaces.'],
    'a list' => [['Contracts\\PriceList'], 'Module [Catalog]: interfaces must be a map of interface name to a list of class names or namespaces.'],
    'patterns not a list' => [['Pricing' => 'Contracts\\PriceList'], 'Module [Catalog]: interface [Pricing] must be a list of class names or namespaces.'],
    'pattern not a string' => [['Pricing' => [3]], 'Module [Catalog]: interface [Pricing] must be a list of class names or namespaces.'],
    'empty pattern' => [['Pricing' => ['']], 'Module [Catalog] interface [Pricing]: an empty pattern matches nothing. Write a class name relative to the module, such as Contracts\\PriceList.'],
    'star inside' => [['Pricing' => ['Data\\*\\Price']], 'Module [Catalog] interface [Pricing]: the pattern [Data\\*\\Price] may use * only at the end.'],
    'star name' => [['*' => ['Contracts\\PriceList']], 'Module [Catalog]: [*] cannot name an interface, because Catalog::* already means the whole public API.'],
    'colon name' => [['A::B' => ['Contracts\\PriceList']], 'Module [Catalog]: the interface name [A::B] cannot contain "::".'],
]);

it('rejects the interfaces of a nested module hidden outside its parent', function () {
    $config = ['Billing' => ['nested' => ['Invoices']], 'Billing.Invoices' => ['interfaces' => ['Api' => ['Contracts\\*']]], 'Orders' => ['depends_on' => ['Billing.Invoices::Api']]];
    $modules = (new NestedResolver(new NamespaceResolver(fixture_path('nested-app/app/Modules'), 'App\\Modules'), $config))->resolve();

    expect(ConfigValidator::errors(['modules' => $config], $modules))->toBe([
        'Module [Orders] depends on [Billing.Invoices::Api], which is nested in [Billing] and so invisible outside it. Depend on [Billing] instead.',
    ]);
});

// members of an interface

it('fails when an interface lists a class that is not public', function () {
    $result = analyse_interfaces_app(['Catalog' => ['interfaces' => ['Pricing' => ['Models\\Price']]], 'Billing' => ['depends_on' => ['Catalog::Pricing']]]);

    expect($result->interfaceErrors)->toBe(['Module [Catalog] interface [Pricing] lists Models\\Price, which is not public.'])
        ->and($result->interfaceWarnings)->toBe([]);
});

it('accepts a class that is public because of #[PublicApi], the public list or an open module', function () {
    $internal = ['Pricing' => ['Models\\Price']];

    expect(analyse_interfaces_app(['Catalog' => ['public' => ['Models'], 'interfaces' => $internal]])->interfaceErrors)->toBe([])
        ->and(analyse_interfaces_app(['Catalog' => ['open' => true, 'interfaces' => $internal]])->interfaceErrors)->toBe([]);
});

it('warns when a pattern matches no class', function () {
    $result = analyse_interfaces_app(['Catalog' => ['interfaces' => ['Pricing' => ['Contracts\\Missing', 'Nowhere\\*', 'Data\\Price*']]]]);

    expect($result->interfaceErrors)->toBe([])
        ->and($result->interfaceWarnings)->toBe([
            'Module [Catalog] interface [Pricing] lists Contracts\\Missing, which matches no class.',
            'Module [Catalog] interface [Pricing] lists Nowhere\\*, which matches no class.',
        ]);
});

it('warns when a pattern matches only classes that are not public', function () {
    $result = analyse_interfaces_app(['Catalog' => ['interfaces' => ['Models' => ['Models\\*']]]]);

    expect($result->interfaceErrors)->toBe([])
        ->and($result->interfaceWarnings)->toBe(['Module [Catalog] interface [Models] lists Models\\*, which matches no public class.']);
});

it('does not warn about a missing class while a file of the module does not parse', function () {
    $root = temp_project('interfaces-app');
    file_put_contents($root.'/app/Modules/Catalog/Broken.php', '<?php class {');
    $result = analyse_interfaces_app(['Catalog' => ['interfaces' => ['Pricing' => ['Contracts\\Missing']]]], root: $root);

    expect($result->interfaceWarnings)->toBe([]);
});

// nested modules

it('lets Parent.Child::Interface in depends_on, from inside the parent', function () {
    $config = [
        'Billing' => ['nested' => ['Invoices', 'Payments']],
        'Billing.Payments' => ['public' => ['Models'], 'interfaces' => ['Models' => ['Models\\Payment']]],
        'Billing.Invoices' => ['depends_on' => ['Billing.Payments::Models']],
    ];
    $modules = (new NestedResolver(new NamespaceResolver(fixture_path('nested-app/app/Modules'), 'App\\Modules'), $config))->resolve();

    expect(ConfigValidator::errors(['modules' => $config], $modules))->toBe([]);

    $built = RuleSet::fromConfig(['public_api_leak' => 'off']);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $built, RuleSet::severities(['public_api_leak' => 'off'], $built));
    $result = $analyzer->analyze($modules->configure($config), fixture_path('nested-app'));
    $messages = messages_of($result->violations, 'undeclared_dependency');

    // InvoiceService uses Payments' PaymentGateway, public but not in the Models interface, and Payment, which is.
    expect($messages)->toBe(['Module [Billing.Invoices] uses App\\Modules\\Billing\\Payments\\Contracts\\PaymentGateway, which is not in the interfaces it declares on [Billing.Payments] (Models).']);
});

it('does not restrict a nested module using its ancestors, internal classes included', function () {
    $config = [
        'Billing' => ['nested' => ['Invoices', 'Payments'], 'interfaces' => ['Api' => ['Contracts\\BillingGateway']]],
        'Billing.Invoices' => ['depends_on' => ['Billing.Payments', 'Billing::Api']],
    ];
    $modules = (new NestedResolver(new NamespaceResolver(fixture_path('nested-app/app/Modules'), 'App\\Modules'), $config))->resolve();
    $built = RuleSet::fromConfig(['public_api_leak' => 'off']);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $built, RuleSet::severities(['public_api_leak' => 'off'], $built));
    $result = $analyzer->analyze($modules->configure($config), fixture_path('nested-app'));

    // InvoiceTotals uses Billing\Services\Money, internal and in no interface: a child belongs to its parent.
    expect(messages_of($result->violations, 'undeclared_dependency'))->toBe([]);
});

it('applies the interfaces of the parent to the code outside it, through the visible module', function () {
    $config = [
        'Billing' => ['nested' => ['Invoices', 'Payments'], 'public' => ['Invoices\\Contracts'], 'interfaces' => ['Api' => ['Contracts\\BillingGateway', 'Invoices\\Contracts\\InvoiceReader']]],
        'Orders' => ['depends_on' => ['Billing::Api']],
    ];
    $modules = (new NestedResolver(new NamespaceResolver(fixture_path('nested-app/app/Modules'), 'App\\Modules'), $config))->resolve();
    $built = RuleSet::fromConfig(['public_api_leak' => 'off']);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $built, RuleSet::severities(['public_api_leak' => 'off'], $built));
    $result = $analyzer->analyze($modules->configure($config), fixture_path('nested-app'));

    // BillingGateway and InvoiceReader are in Api; Payments' Payment is internal to the outside (internal_access), not reported here.
    expect(messages_of($result->violations, 'undeclared_dependency'))->toBe([])
        ->and($result->interfaceErrors)->toBe([]);
});

// graph

it('labels an arrow with the interfaces Billing declares on Catalog', function () {
    $result = analyse_interfaces_app(['Catalog' => CATALOG_INTERFACES, 'Billing' => ['depends_on' => ['Catalog::Pricing', 'Catalog::Inventory']]]);
    $modules = interfaces_modules(['Catalog' => CATALOG_INTERFACES, 'Billing' => ['depends_on' => ['Catalog::Pricing', 'Catalog::Inventory']]]);
    $edges = (new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, RuleSet::fromConfig([])))->snapshot($modules)->context->edges;
    $model = GraphExporter::model(['Billing', 'Catalog', 'Orders'], $edges, $result->violations, $modules);
    $billing = array_values(array_filter($model['edges'], fn (array $edge) => $edge['from'] === 'Billing'));

    $label = $billing[0]['references'].' Catalog::Pricing, Catalog::Inventory';

    expect($billing[0]['interfaces'])->toBe(['Catalog::Pricing', 'Catalog::Inventory'])
        ->and(GraphExporter::flowchart($model))->toContain('|"'.$label.'"|')
        ->and(GraphExporter::dot($model))->toContain('label="'.$label.'"');
});

it('leaves the arrows of whole-module dependencies as they were', function () {
    $built = RuleSet::fromConfig([]);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $built);
    $modules = interfaces_modules(['Catalog' => CATALOG_INTERFACES, 'Billing' => ['depends_on' => ['Catalog', 'Catalog::Pricing']]]);
    $edges = $analyzer->snapshot($modules)->context->edges;
    $model = GraphExporter::model(['Billing', 'Catalog', 'Orders'], $edges, [], $modules);

    expect($model['edges'][0])->not->toHaveKey('interfaces')
        ->and(GraphExporter::flowchart($model))->toContain('-->|'.$model['edges'][0]['references'].'|');
});

it('labels an arrow with the interfaces the rules apply: a sibling edge ignores the ones declared on the parent', function () {
    $config = [
        'Billing' => ['nested' => ['Invoices', 'Payments'], 'public' => ['Contracts', 'Invoices\\Contracts', 'Payments\\Contracts'], 'interfaces' => ['Api' => ['Contracts\\BillingGateway']]],
        'Billing.Invoices' => ['depends_on' => ['Billing::Api']],
        'Orders' => ['depends_on' => ['Billing::Api']],
    ];
    $modules = (new NestedResolver(new NamespaceResolver(fixture_path('nested-app/app/Modules'), 'App\\Modules'), $config))->resolve()->configure($config);
    $built = RuleSet::fromConfig([]);
    $edges = (new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $built))->snapshot($modules)->context->edges;
    $model = GraphExporter::model($modules->names(), $edges, [], $modules);
    $labels = [];

    foreach ($model['edges'] as $edge) {
        $labels[$edge['from'].' -> '.$edge['to']] = $edge['interfaces'] ?? null;
    }

    // Invoices uses its sibling Payments: Billing::Api says nothing about it. Orders sees Billing.Payments as Billing.
    expect($labels['Billing.Invoices -> Billing.Payments'])->toBeNull()
        ->and($labels['Orders -> Billing.Payments'])->toBe(['Billing::Api']);
});

it('keeps Module free of interfaces by default', function () {
    expect(Module::make('Billing', 'App\\Billing', '/p')->interfaces)->toBe([])
        ->and(Module::make('Billing', 'App\\Billing', '/p')->withConfig(['interfaces' => ['A' => ['X'], 'B' => [3, 'Y\\*'], 'C' => 'no', 4 => ['Z']]])->interfaces)->toBe(['A' => ['X'], 'B' => ['Y\\*'], '4' => ['Z']]);
});

it('keeps the parent interface in the C4 arrow of a nested project', function () {
    $config = [
        'Billing' => ['nested' => ['Invoices', 'Payments'], 'public' => ['Contracts', 'Invoices\\Contracts', 'Payments\\Contracts'], 'interfaces' => ['Api' => ['Contracts\\BillingGateway']]],
        'Billing.Invoices' => ['depends_on' => ['Billing::Api']],
        'Orders' => ['depends_on' => ['Billing::Api']],
    ];
    $modules = (new NestedResolver(new NamespaceResolver(fixture_path('nested-app/app/Modules'), 'App\\Modules'), $config))->resolve()->configure($config);
    $snapshot = (new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, RuleSet::fromConfig([])))->snapshot($modules);
    $c4 = GraphExporter::c4($snapshot, []);

    // Orders sees Billing.Payments as Billing: the label names Billing::Api, as the Mermaid arrow does.
    expect($c4)->toContain('Rel(m3, m2, "2 Billing::Api")');
});
