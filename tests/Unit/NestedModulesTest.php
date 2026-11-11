<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\ClassDeclaration;
use Cordon\Analysis\DeclaredVisibility;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\ModuleFilter;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Baseline\Baseline;
use Cordon\Documentation\GraphExporter;
use Cordon\Laravel\ResolverFactory;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Resolvers\NestedResolver;
use Cordon\Rules\RuleSet;
use Cordon\Support\ConfigValidator;
use Illuminate\Config\Repository;

const NESTED_BILLING = ['Billing' => ['nested' => ['Invoices', 'Payments']]];

/**
 * The modules of tests/Fixtures/nested-app (or a copy of it), with the nested ones from the config.
 *
 * @param  array<string, mixed>  $moduleConfig
 */
function nested_modules(array $moduleConfig = [], ?string $root = null, bool $configured = true): ModuleMap
{
    $moduleConfig = [...NESTED_BILLING, ...$moduleConfig];
    $modules = (new NestedResolver(new NamespaceResolver(($root ?? fixture_path('nested-app')).'/app/Modules', 'App\\Modules'), $moduleConfig))->resolve();

    return $configured ? $modules->configure($moduleConfig) : $modules;
}

/**
 * @param  array<string, mixed>  $moduleConfig
 * @param  array<array-key, mixed>  $rules
 */
function analyse_nested_app(array $moduleConfig = [], array $rules = [], ?string $root = null, array $hostPaths = []): Result
{
    $root ??= fixture_path('nested-app');
    $built = RuleSet::fromConfig($rules);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $built, RuleSet::severities($rules, $built));

    return $analyzer->analyze(nested_modules($moduleConfig, $root), $root, $hostPaths);
}

/**
 * "rule source -> target (file)" of each violation, sorted.
 *
 * @param  list<Violation>  $violations
 * @return list<string>
 */
function nested_findings(array $violations, string $rule): array
{
    $found = [];

    foreach ($violations as $violation) {
        if ($violation->rule === $rule) {
            $found[] = $violation->sourceModule.' -> '.$violation->targetModule.' '.basename((string) $violation->file).' '.$violation->target;
        }
    }

    sort($found);

    return $found;
}

it('finds the folders listed in nested as modules named Parent.Child', function () {
    $modules = nested_modules();
    $invoices = $modules->get('Billing.Invoices');

    expect($modules->names())->toBe(['Billing', 'Billing.Invoices', 'Billing.Payments', 'Orders'])
        ->and($invoices)->toBeInstanceOf(Module::class)
        ->and($invoices->parent)->toBe('Billing')
        ->and($invoices->namespace)->toBe('App\\Modules\\Billing\\Invoices')
        ->and($invoices->path)->toBe(fixture_path('nested-app/app/Modules/Billing/Invoices'))
        ->and($modules->get('Billing')->parent)->toBeNull()
        ->and($invoices->isNestedIn($modules->get('Billing')))->toBeTrue()
        ->and($modules->get('Billing')->isNestedIn($invoices))->toBeFalse()
        ->and($invoices->isNestedIn($modules->get('Orders')))->toBeFalse();
});

it('keeps the settings of a nested module under its full name', function () {
    $modules = nested_modules(['Billing.Invoices' => ['depends_on' => ['Billing.Payments'], 'public' => ['Services']]]);

    expect($modules->get('Billing.Invoices')->dependsOn)->toBe(['Billing.Payments'])
        ->and($modules->get('Billing.Invoices')->publicApi)->toBe(['Services'])
        ->and($modules->get('Billing.Invoices')->parent)->toBe('Billing');
});

it('maps a class and a file to the deepest module that contains it', function () {
    $modules = nested_modules();

    expect($modules->forClass('App\\Modules\\Billing\\Invoices\\Models\\Invoice')?->name)->toBe('Billing.Invoices')
        ->and($modules->forClass('App\\Modules\\Billing\\Services\\BillingService')?->name)->toBe('Billing')
        ->and($modules->forPath(fixture_path('nested-app/app/Modules/Billing/Payments/Models/Payment.php'))?->name)->toBe('Billing.Payments')
        ->and($modules->forPath(fixture_path('nested-app/app/Modules/Billing/Services/BillingService.php'))?->name)->toBe('Billing')
        ->and($modules->topLevel($modules->get('Billing.Payments'))->name)->toBe('Billing')
        ->and($modules->topLevel($modules->get('Orders'))->name)->toBe('Orders');
});

it('lists a nested module right after its parent', function () {
    $modules = nested_modules();
    $names = array_map(fn (Module $module) => $module->name, (new ModuleMap([...$modules->all(), Module::make('Billing-Legacy', 'App\\Modules\\Billing-Legacy', '/x')]))->hierarchy());

    expect($names)->toBe(['Billing', 'Billing.Invoices', 'Billing.Payments', 'Billing-Legacy', 'Orders']);
});

it('takes every folder that is not a public namespace with nested => true', function () {
    $modules = nested_modules(['Billing' => ['nested' => true]]);

    expect($modules->names())->toBe(['Billing', 'Billing.Invoices', 'Billing.Payments', 'Billing.Services', 'Orders']);
});

it('nests modules at any depth', function () {
    $root = temp_project('nested-app');
    mkdir($root.'/app/Modules/Billing/Invoices/Lines');
    file_put_contents($root.'/app/Modules/Billing/Invoices/Lines/Line.php', "<?php\n\nnamespace App\\Modules\\Billing\\Invoices\\Lines;\n\nfinal class Line {}\n");
    $modules = nested_modules(['Billing.Invoices' => ['nested' => ['Lines']]], $root);
    $lines = $modules->get('Billing.Invoices.Lines');

    expect($lines?->parent)->toBe('Billing.Invoices')
        ->and($modules->forClass('App\\Modules\\Billing\\Invoices\\Lines\\Line')?->name)->toBe('Billing.Invoices.Lines')
        ->and($modules->topLevel($lines)->name)->toBe('Billing')
        ->and($lines->isNestedIn($modules->get('Billing')))->toBeTrue();
});

it('finds the nested folders under the source directory of the resolver', function () {
    $root = temp_project();
    mkdir($root.'/Modules/Blog/app/Invoices', 0777, true);
    file_put_contents($root.'/Modules/Blog/module.json', '{}');
    file_put_contents($root.'/Modules/Blog/app/Invoices/Invoice.php', "<?php\n\nnamespace Modules\\Blog\\Invoices;\n\nfinal class Invoice {}\n");

    $repository = new Repository(['cordon' => ['resolver' => 'nwidart', 'modules' => ['Blog' => ['nested' => ['Invoices']]]]]);
    $modules = ResolverFactory::make($repository, $root)->resolve();

    expect($modules->names())->toBe(['Blog', 'Blog.Invoices'])
        ->and($modules->get('Blog.Invoices')?->namespace)->toBe('Modules\\Blog\\Invoices')
        ->and($modules->get('Blog.Invoices')?->path)->toBe($root.'/Modules/Blog/app/Invoices');
});

// Visibility: Spring Modulith 1.3 semantics

it('exposes the public API of a nested module only inside its parent', function () {
    $modules = nested_modules();
    $policy = new PublicApiPolicy([], $modules);
    $invoices = $modules->get('Billing.Invoices');
    $class = 'App\\Modules\\Billing\\Invoices\\Contracts\\InvoiceReader';
    $sibling = $modules->get('Billing.Payments');

    expect($policy->isPublic($class, $invoices, null, $sibling))->toBeFalse()
        ->and((new PublicApiPolicy(['Contracts'], $modules))->isPublic($class, $invoices, null, $sibling))->toBeTrue()
        ->and((new PublicApiPolicy(['Contracts'], $modules))->isPublic($class, $invoices, null, $modules->get('Billing')))->toBeTrue()
        ->and((new PublicApiPolicy(['Contracts'], $modules))->isPublic($class, $invoices, null, $modules->get('Orders')))->toBeFalse()
        ->and((new PublicApiPolicy(['Contracts'], $modules))->isPublic($class, $invoices))->toBeFalse()
        ->and((new PublicApiPolicy(['Contracts'], $modules))->isPublic($class, $invoices, null, Module::host()))->toBeFalse();
});

it('exposes a nested class outside the parent only when the public list of the parent names it', function () {
    $modules = nested_modules(['Billing' => ['nested' => ['Invoices', 'Payments'], 'public' => ['Invoices\\Contracts']]]);
    $policy = new PublicApiPolicy(['Contracts'], $modules);
    $orders = $modules->get('Orders');
    $reader = 'App\\Modules\\Billing\\Invoices\\Contracts\\InvoiceReader';

    expect($policy->isPublic($reader, $modules->get('Billing.Invoices'), null, $orders))->toBeTrue()
        ->and($policy->isPublic('App\\Modules\\Billing\\Invoices\\Models\\Invoice', $modules->get('Billing.Invoices'), null, $orders))->toBeFalse()
        ->and($policy->reason($reader, $modules->get('Billing.Invoices'), null, $orders))->toBe('config/cordon.php lists [Invoices\\Contracts] in the public of module [Billing]');
});

it('explains why a nested class is internal outside its parent', function () {
    $modules = nested_modules();
    $reason = (new PublicApiPolicy(['Contracts'], $modules))->reason('App\\Modules\\Billing\\Invoices\\Contracts\\InvoiceReader', $modules->get('Billing.Invoices'), null, $modules->get('Orders'));

    expect($reason)->toBe('it belongs to module [Billing.Invoices], nested in [Billing]: outside [Billing] only what the public list of [Billing] names is exposed');
});

it('lets #[PublicApi], #[Internal] and an open parent decide for a nested class seen from outside', function () {
    $modules = nested_modules(['Billing' => ['nested' => ['Invoices', 'Payments'], 'open' => true]]);
    $policy = new PublicApiPolicy(['Contracts'], $modules);
    $class = 'App\\Modules\\Billing\\Invoices\\Models\\Invoice';
    $orders = $modules->get('Orders');
    $invoices = $modules->get('Billing.Invoices');

    expect($policy->isPublic($class, $invoices, null, $orders))->toBeTrue()
        ->and($policy->isPublic($class, $invoices, new ClassDeclaration($class, '/f', 1, DeclaredVisibility::Internal), $orders))->toBeFalse();

    $closed = nested_modules();
    $policy = new PublicApiPolicy([], $closed);

    expect($policy->isPublic($class, $closed->get('Billing.Invoices'), new ClassDeclaration($class, '/f', 1, DeclaredVisibility::PublicApi), $closed->get('Orders')))->toBeTrue();
});

it('applies the open flag of the nested module inside the parent and of the parent outside it', function () {
    $modules = nested_modules(['Billing.Invoices' => ['open' => true]]);
    $policy = new PublicApiPolicy([], $modules);
    $class = 'App\\Modules\\Billing\\Invoices\\Models\\Invoice';

    expect($policy->isPublic($class, $modules->get('Billing.Invoices'), null, $modules->get('Billing.Payments')))->toBeTrue()
        ->and($policy->isPublic($class, $modules->get('Billing.Invoices'), null, $modules->get('Orders')))->toBeFalse();
});

it('does not change the policy for modules that are not nested', function () {
    $modules = nested_modules();
    $policy = new PublicApiPolicy;
    $class = 'App\\Modules\\Billing\\Contracts\\BillingGateway';

    foreach ([null, $modules->get('Orders'), Module::host()] as $consumer) {
        expect($policy->isPublic($class, $modules->get('Billing'), null, $consumer))->toBeTrue()
            ->and($policy->isPublic('App\\Modules\\Billing\\Services\\BillingService', $modules->get('Billing'), null, $consumer))->toBeFalse();
    }
});

// internal_access

it('applies the public API of a nested module between siblings, parent and child, and hides it outside the parent', function () {
    $result = analyse_nested_app(rules: ['public_api_leak' => 'off']);

    expect(nested_findings($result->violations, 'internal_access'))->toBe([
        'Billing -> Billing.Invoices ReportBuilder.php App\\Modules\\Billing\\Invoices\\Models\\Invoice',
        'Billing.Invoices -> Billing.Payments InvoiceService.php App\\Modules\\Billing\\Payments\\Models\\Payment',
        'Orders -> Billing.Invoices OrderService.php App\\Modules\\Billing\\Invoices\\Contracts\\InvoiceReader',
        'Orders -> Billing.Payments OrderService.php App\\Modules\\Billing\\Payments\\Models\\Payment',
    ]);
});

it('lets a nested module use any class of its parent, internal ones included', function () {
    $modules = nested_modules();
    $policy = new PublicApiPolicy([], $modules);
    $money = 'App\\Modules\\Billing\\Services\\Money';
    $billing = $modules->get('Billing');

    expect($policy->isPublic($money, $billing, null, $modules->get('Billing.Invoices')))->toBeTrue()
        ->and($policy->isPublic($money, $billing, new ClassDeclaration($money, '/f', 1, DeclaredVisibility::Internal), $modules->get('Billing.Payments')))->toBeTrue()
        ->and($policy->isPublic($money, $billing, null, $modules->get('Orders')))->toBeFalse()
        ->and($policy->isPublic('App\\Modules\\Billing\\Invoices\\Models\\Invoice', $modules->get('Billing.Invoices'), null, $billing))->toBeFalse();
});

it('does not report a nested module using an internal class of its parent, but still the other directions', function () {
    $result = analyse_nested_app(rules: ['public_api_leak' => 'off']);
    $internal = nested_findings($result->violations, 'internal_access');

    expect(array_filter($internal, fn (string $finding) => str_contains($finding, 'InvoiceTotals')))->toBe([])
        // parent -> child and sibling -> sibling internals are still violations
        ->and($internal)->toContain('Billing -> Billing.Invoices ReportBuilder.php App\\Modules\\Billing\\Invoices\\Models\\Invoice')
        ->and($internal)->toContain('Billing.Invoices -> Billing.Payments InvoiceService.php App\\Modules\\Billing\\Payments\\Models\\Payment');
});

it('exposes a nested class to the outside through the public list of the parent', function () {
    $result = analyse_nested_app(['Billing' => ['nested' => ['Invoices', 'Payments'], 'public' => ['Invoices\\Contracts']]], ['public_api_leak' => 'off']);

    expect(nested_findings($result->violations, 'internal_access'))->not->toContain('Orders -> Billing.Invoices OrderService.php App\\Modules\\Billing\\Invoices\\Contracts\\InvoiceReader')
        ->and(nested_findings($result->violations, 'internal_access'))->toContain('Orders -> Billing.Payments OrderService.php App\\Modules\\Billing\\Payments\\Models\\Payment');
});

it('reports a host access to a nested class that is hidden from outside', function () {
    $root = temp_project('nested-app');
    mkdir($root.'/app/Http');
    file_put_contents($root.'/app/Http/Controller.php', "<?php\n\nnamespace App\\Http;\n\nuse App\\Modules\\Billing\\Contracts\\BillingGateway;\nuse App\\Modules\\Billing\\Invoices\\Contracts\\InvoiceReader;\n\nfinal class Controller\n{\n    public function __construct(BillingGateway \$a, InvoiceReader \$b) {}\n}\n");

    $result = analyse_nested_app(root: $root, hostPaths: [$root.'/app']);

    $hostWarnings = array_values(array_filter($result->warnings, fn (Violation $v) => $v->rule === 'host_access'));

    expect(array_map(fn (Violation $v) => $v->rule.' '.$v->targetModule.' '.$v->target, $hostWarnings))->toBe([
        'host_access Billing.Invoices App\\Modules\\Billing\\Invoices\\Contracts\\InvoiceReader',
    ]);
});

// undeclared_dependency

it('sees a nested module as part of its parent from outside, for depends_on', function () {
    $result = analyse_nested_app(['Orders' => ['depends_on' => ['Billing']]], ['internal_access' => 'off']);

    expect(nested_findings($result->violations, 'undeclared_dependency'))->toBe([]);

    $result = analyse_nested_app(['Orders' => ['depends_on' => []]], ['internal_access' => 'off']);

    expect(nested_findings($result->violations, 'undeclared_dependency'))->toBe(['Orders -> Billing OrderService.php Billing']);
    expect($result->violations[0]->message)->toBe('Module [Orders] depends on module [Billing] (via App\\Modules\\Billing\\Contracts\\BillingGateway), but [Billing] is not listed in its depends_on.');
});

it('asks siblings to list each other, and lets a parent use its children without listing them', function () {
    $config = [
        'Billing' => ['nested' => ['Invoices', 'Payments'], 'depends_on' => []],
        'Billing.Invoices' => ['depends_on' => []],
        'Billing.Payments' => ['depends_on' => []],
    ];
    $result = analyse_nested_app($config, ['internal_access' => 'off']);

    expect(nested_findings($result->violations, 'undeclared_dependency'))->toBe([
        'Billing.Invoices -> Billing.Payments InvoiceService.php Billing.Payments',
    ]);

    $config['Billing.Invoices']['depends_on'] = ['Billing.Payments'];

    expect(nested_findings(analyse_nested_app($config, ['internal_access' => 'off'])->violations, 'undeclared_dependency'))->toBe([]);
});

it('does not ask a nested module to list its parent', function () {
    $config = ['Billing.Invoices' => ['depends_on' => ['Billing.Payments']]];

    expect(nested_findings(analyse_nested_app($config, ['internal_access' => 'off'])->violations, 'undeclared_dependency'))->toBe([]);
});

// unused_dependency

it('counts a nested module as used through its parent for unused_dependency', function () {
    $config = [
        'Orders' => ['depends_on' => ['Billing']],
        'Billing.Invoices' => ['depends_on' => ['Billing.Payments', 'Orders', 'Billing']],
    ];
    $result = analyse_nested_app($config, ['unused_dependency' => 'error', 'internal_access' => 'off']);

    expect(nested_findings($result->violations, 'unused_dependency'))->toBe([
        'Billing.Invoices -> Orders  Billing.Invoices -> Orders',
    ]);
});

// cycles

it('names a cycle between siblings with their full names', function () {
    $root = temp_project('nested-app');
    file_put_contents($root.'/app/Modules/Billing/Payments/Services/Callback.php', "<?php\n\nnamespace App\\Modules\\Billing\\Payments\\Services;\n\nuse App\\Modules\\Billing\\Invoices\\Contracts\\InvoiceReader;\n\nfinal class Callback\n{\n    public function __construct(InvoiceReader \$reader) {}\n}\n");
    $result = analyse_nested_app(rules: ['internal_access' => 'off'], root: $root);
    $cycles = array_values(array_filter($result->violations, fn (Violation $v) => $v->rule === 'cycles'));

    expect(array_map(fn (Violation $v) => $v->target, $cycles))->toBe(['Billing.Invoices -> Billing.Payments', 'Billing.Payments -> Billing.Invoices'])
        ->and($cycles[0]->message)->toStartWith('Module Billing.Invoices depends on Billing.Payments, which closes the dependency cycle Billing.Invoices -> Billing.Payments -> Billing.Invoices.')
        ->and($cycles[0]->baselineKey($root))->toBe('cycles||Billing.Invoices -> Billing.Payments');
});

it('does not take the use between a parent and its children for a cycle', function () {
    $result = analyse_nested_app(rules: ['internal_access' => 'off']);

    expect(nested_findings($result->violations, 'cycles'))->toBe([]);
});

it('closes a cycle through a parent and one of its children', function () {
    $root = temp_project('nested-app');
    file_put_contents($root.'/app/Modules/Billing/Payments/Services/Notifier.php', "<?php\n\nnamespace App\\Modules\\Billing\\Payments\\Services;\n\nuse App\\Modules\\Orders\\Contracts\\OrderPlaced;\n\nfinal class Notifier\n{\n    public function __construct(OrderPlaced \$event) {}\n}\n");
    $result = analyse_nested_app(rules: ['internal_access' => 'off'], root: $root);
    $targets = array_map(fn (Violation $v) => $v->target, array_values(array_filter($result->violations, fn (Violation $v) => $v->rule === 'cycles')));

    // Orders uses Billing (it sees no children), Billing uses its child Payments and Payments uses Orders.
    expect($targets)->toContain('Orders -> Billing', 'Billing -> Billing.Payments', 'Billing.Payments -> Orders');
});

// layers

it('checks layers between the visible modules', function () {
    $rules = ['layers' => 'error', 'internal_access' => 'off'];
    $built = RuleSet::fromConfig($rules, null, [], [['Orders'], ['Billing']]);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $built, RuleSet::severities($rules, $built));
    $result = $analyzer->analyze(nested_modules(), fixture_path('nested-app'));

    expect(nested_findings($result->violations, 'layers'))->toBe([]);

    $built = RuleSet::fromConfig($rules, null, [], [['Billing'], ['Orders']]);
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $built, RuleSet::severities($rules, $built));
    $result = $analyzer->analyze(nested_modules(), fixture_path('nested-app'));

    expect(nested_findings($result->violations, 'layers'))->toBe(['Orders -> Billing OrderService.php Billing']);
});

// public_api_leak

it('warns when a public class of a nested module exposes an internal one, for the modules inside the parent', function () {
    $result = analyse_nested_app();
    $leaks = array_values(array_filter($result->warnings, fn (Violation $v) => $v->rule === 'public_api_leak'));

    expect(array_map(fn (Violation $v) => $v->message, $leaks))->toBe([
        'App\\Modules\\Billing\\Invoices\\Contracts\\InvoiceReader::find() returns App\\Modules\\Billing\\Invoices\\Models\\Invoice, which is internal to [Billing.Invoices]: other modules reach it through the public API.',
    ]);
});

it('warns about a leak only seen from outside the parent', function () {
    // Services is internal for the siblings, but Billing exposes it to the world.
    $result = analyse_nested_app(['Billing' => ['nested' => ['Invoices', 'Payments'], 'public' => ['Invoices\\Services']]]);
    $leaks = array_map(fn (Violation $v) => basename((string) $v->file).' '.$v->target, array_values(array_filter($result->warnings, fn (Violation $v) => $v->rule === 'public_api_leak')));

    expect($leaks)->toBe([
        'InvoiceReader.php App\\Modules\\Billing\\Invoices\\Models\\Invoice',
        'InvoiceService.php App\\Modules\\Billing\\Invoices\\Models\\Invoice',
    ]);
});

// baseline

it('keys the baseline entries with the full name of the nested module', function () {
    $result = analyse_nested_app(rules: ['public_api_leak' => 'off']);
    $keys = array_map(fn (Violation $v) => $v->baselineKey($result->basePath), $result->violations);

    sort($keys);

    expect($keys)->toBe([
        'internal_access|app/Modules/Billing/Invoices/Services/InvoiceService.php|App\\Modules\\Billing\\Payments\\Models\\Payment',
        'internal_access|app/Modules/Billing/Services/ReportBuilder.php|App\\Modules\\Billing\\Invoices\\Models\\Invoice',
        'internal_access|app/Modules/Orders/Services/OrderService.php|App\\Modules\\Billing\\Invoices\\Contracts\\InvoiceReader',
        'internal_access|app/Modules/Orders/Services/OrderService.php|App\\Modules\\Billing\\Payments\\Models\\Payment',
    ]);

    $baseline = Baseline::fromViolations($result->violations, $result->basePath);
    [$new] = $baseline->filter($result->violations, $result->basePath);

    expect($new)->toBe([]);
});

it('keeps the violations of the nested modules when selecting their parent with --module', function () {
    $result = analyse_nested_app(rules: ['public_api_leak' => 'off']);
    $sources = function (Result $r) {
        $names = array_values(array_unique(array_map(fn (Violation $v) => (string) $v->sourceModule, $r->violations)));
        sort($names);

        return $names;
    };

    expect($sources(ModuleFilter::apply($result, ['Billing'])))->toBe(['Billing', 'Billing.Invoices'])
        ->and($sources(ModuleFilter::apply($result, ['Billing.Invoices'])))->toBe(['Billing.Invoices'])
        ->and($sources(ModuleFilter::apply($result, ['Orders'])))->toBe(['Orders'])
        ->and(ModuleFilter::apply($result, ['Billing'])->checkedModules)->toBe(['Billing', 'Billing.Invoices', 'Billing.Payments']);
});

it('gives a project without nested the same result as before', function () {
    $modules = (new NamespaceResolver(fixture_path('namespace-app/app/Modules'), 'App\\Modules'))->resolve()->configure(['Shared' => ['open' => true]]);
    $nested = (new NestedResolver(new NamespaceResolver(fixture_path('namespace-app/app/Modules'), 'App\\Modules'), ['Shared' => ['open' => true]]))->resolve()->configure(['Shared' => ['open' => true]]);
    $built = RuleSet::fromConfig();
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector(['vendor', 'node_modules', 'tests', 'Tests']), new PublicApiPolicy, $built, RuleSet::severities([], $built));

    expect($nested)->toEqual($modules)
        ->and($analyzer->analyze($nested, fixture_path('namespace-app')))->toEqual(analyse_namespace_app());
});

// ConfigValidator

it('accepts nested as a list of folders, true or false', function () {
    $modules = nested_modules(configured: false);

    expect(ConfigValidator::errors(['modules' => [
        'Billing' => ['nested' => ['Invoices', 'Payments']],
        'Billing.Invoices' => ['depends_on' => ['Billing.Payments']],
        'Billing.Payments' => ['depends_on' => []],
        'Orders' => ['depends_on' => ['Billing']],
    ]], $modules))->toBe([])
        ->and(ConfigValidator::errors(['modules' => ['Billing' => ['nested' => true]]], nested_modules(['Billing' => ['nested' => true]], configured: false)))->toBe([]);
});

it('rejects a nested value that is neither true nor a list of folders', function (mixed $value) {
    expect(ConfigValidator::errors(['modules' => ['Billing' => ['nested' => $value]]], nested_modules(configured: false)))
        ->toBe(['Module [Billing]: nested must be true or a list of folder names.']);
})->with([
    'a string' => ['Invoices'],
    'a list with a number' => [['Invoices', 3]],
    'a map' => [['a' => 'Invoices']],
]);

it('rejects a nested folder that does not exist and suggests the closest one', function () {
    $modules = nested_modules(['Billing' => ['nested' => ['Invoices', 'Paymentz']]], configured: false);

    expect(ConfigValidator::errors(['modules' => ['Billing' => ['nested' => ['Invoices', 'Paymentz']]]], $modules))->toBe([
        'Module [Billing]: nested lists [Paymentz], but the module has no such folder. Did you mean [Payments]?',
    ]);
});

it('rejects a nested folder with a dot in its name', function () {
    $root = temp_project('nested-app');
    mkdir($root.'/app/Modules/Billing/V1.2');
    $config = ['Billing' => ['nested' => ['Invoices', 'V1.2']]];

    expect(ConfigValidator::errors(['modules' => $config], nested_modules($config, $root, configured: false)))->toBe([
        'Module [Billing]: nested folder [V1.2] contains a dot, which separates the parent from the child in names such as Billing.Invoices. Rename the folder.',
    ]);
});

it('leaves out of nested => true a folder with a dot in its name', function () {
    $root = temp_project('nested-app');
    mkdir($root.'/app/Modules/Billing/V1.2');

    expect(nested_modules(['Billing' => ['nested' => true]], $root, configured: false)->names())->toBe(['Billing', 'Billing.Invoices', 'Billing.Payments', 'Billing.Services', 'Orders']);
});

it('rejects settings for a nested module that does not exist', function () {
    expect(ConfigValidator::errors(['modules' => ['Billing.Invoicez' => ['open' => true]]], nested_modules(configured: false)))->toBe([
        'config/cordon.php configures unknown module [Billing.Invoicez]. Did you mean [Billing.Invoices]?',
    ]);
});

it('rejects a dependency on a nested module from outside its parent', function () {
    expect(ConfigValidator::errors(['modules' => ['Orders' => ['depends_on' => ['Billing.Invoices']]]], nested_modules(configured: false)))->toBe([
        'Module [Orders] depends on [Billing.Invoices], which is nested in [Billing] and so invisible outside it. Depend on [Billing] instead.',
    ]);
});

it('accepts a dependency on a sibling, and a parent that lists its children', function () {
    expect(ConfigValidator::errors(['modules' => [
        'Billing' => ['nested' => ['Invoices', 'Payments'], 'depends_on' => ['Billing.Invoices', 'Orders']],
        'Billing.Invoices' => ['depends_on' => ['Billing.Payments', 'Billing', 'Orders']],
    ]], nested_modules(configured: false)))->toBe([]);
});

// Graph

it('draws a nested module inside a subgraph of its parent in Mermaid', function () {
    $model = ['modules' => ['Billing', 'Billing.Invoices', 'Billing.Payments', 'Orders'], 'edges' => [
        ['from' => 'Billing.Invoices', 'to' => 'Billing.Payments', 'references' => 2, 'kinds' => ['use'], 'violations' => 0],
        ['from' => 'Orders', 'to' => 'Billing', 'references' => 1, 'kinds' => ['use'], 'violations' => 1],
    ]];

    expect(GraphExporter::flowchart($model))->toBe(implode(PHP_EOL, [
        'flowchart LR',
        '    subgraph s0["Billing"]',
        '        m0["Billing"]',
        '        m1["Billing.Invoices"]',
        '        m2["Billing.Payments"]',
        '    end',
        '    m3["Orders"]',
        '    m1 -->|2| m2',
        '    m3 -->|1| m0',
        '    linkStyle 1 stroke:#d33,stroke-width:2px',
    ]).PHP_EOL);
});

it('draws a nested module inside a cluster of its parent in DOT', function () {
    $model = ['modules' => ['Billing', 'Billing.Invoices', 'Billing.Payments', 'Orders'], 'edges' => [
        ['from' => 'Orders', 'to' => 'Billing.Invoices', 'references' => 1, 'kinds' => ['use'], 'violations' => 0],
    ]];

    expect(GraphExporter::dot($model))->toBe(implode(PHP_EOL, [
        'digraph cordon {',
        '    rankdir=LR;',
        '    node [shape=box];',
        '    subgraph "cluster_Billing" {',
        '        label="Billing";',
        '        "Billing";',
        '        "Billing.Invoices";',
        '        "Billing.Payments";',
        '    }',
        '    "Orders";',
        '    "Orders" -> "Billing.Invoices" [label="1"];',
        '}',
    ]).PHP_EOL);
});

it('draws a flat graph when no module is nested', function () {
    $model = ['modules' => ['A', 'B'], 'edges' => []];

    expect(GraphExporter::flowchart($model))->not->toContain('subgraph')
        ->and(GraphExporter::dot($model))->not->toContain('cluster');
});
