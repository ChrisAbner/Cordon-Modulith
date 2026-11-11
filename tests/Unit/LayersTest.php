<?php

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Edge;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\SymbolTable;
use Cordon\Analysis\Violation;
use Cordon\Baseline\Baseline;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\LayerRule;
use Cordon\Rules\RuleSet;

mutates(LayerRule::class);

const LAYERS = [['Orders', 'Billing'], ['Catalog'], ['Shared']];

/**
 * @param  list<array{0: string, 1: string, 2?: string, 3?: int, 4?: string}>  $dependencies  [from, to, kind, line, target class]
 */
function layered_context(array $dependencies): AnalysisContext
{
    $modules = [];

    foreach (['Billing', 'Catalog', 'Orders', 'Reports', 'Shared'] as $name) {
        $modules[$name] = Module::make($name, $name, '/p/'.$name);
    }

    $edges = array_map(
        fn (array $d) => new Edge($modules[$d[0]], $modules[$d[1]], new Reference('/p/'.$d[0].'/X.php', null, $d[4] ?? $d[1].'\\Contracts\\Y', $d[3] ?? 1, $d[2] ?? Reference::USE)),
        $dependencies,
    );

    return new AnalysisContext(new ModuleMap(array_values($modules)), SymbolTable::fromAnalyses([]), new PublicApiPolicy, $edges);
}

/**
 * @return list<Violation>
 */
function layer_violations(AnalysisContext $context, bool $countListeners = true): array
{
    return iterator_to_array((new LayerRule(LAYERS, $countListeners))->check($context), false);
}

it('lets a layer depend on the layers below it and on its own', function () {
    expect(layer_violations(layered_context([['Orders', 'Catalog'], ['Orders', 'Billing'], ['Catalog', 'Shared'], ['Orders', 'Shared']])))->toBe([]);
});

it('reports a dependency on a layer above, naming the class, once per file and class', function () {
    $violations = layer_violations(layered_context([['Shared', 'Orders', Reference::USE, 4], ['Shared', 'Orders', Reference::USE, 9]]));

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe('layers')
        ->and($violations[0]->line)->toBe(4)
        ->and($violations[0]->sourceModule)->toBe('Shared')
        ->and($violations[0]->targetModule)->toBe('Orders')
        ->and($violations[0]->target)->toBe('Orders\\Contracts\\Y')
        ->and($violations[0]->message)->toBe('Module [Shared] (layer 3) depends on [Orders] (layer 1) through Orders\\Contracts\\Y; a layer may only depend on the layers below it.');
});

it('reports two classes of the same module in one file separately', function () {
    $violations = layer_violations(layered_context([
        ['Shared', 'Orders', Reference::USE, 4, 'Orders\\Http\\StoreRequest'],
        ['Shared', 'Orders', Reference::USE, 9, 'Orders\\Http\\UpdateRequest'],
        ['Shared', 'Orders', Reference::USE, 12, 'Orders\\Http\\StoreRequest'],
    ]));

    expect(array_map(fn (Violation $violation) => $violation->target, $violations))->toBe(['Orders\\Http\\StoreRequest', 'Orders\\Http\\UpdateRequest']);
});

it('compares class names without regard to letter case', function () {
    $violations = layer_violations(layered_context([
        ['Shared', 'Orders', Reference::USE, 4, 'Orders\\Http\\StoreRequest'],
        ['Shared', 'Orders', Reference::USE, 9, 'orders\\http\\storerequest'],
    ]));

    expect($violations)->toHaveCount(1);
});

it('reports a new class of an upper layer even when the file is in the baseline for another one', function () {
    $recorded = layer_violations(layered_context([['Shared', 'Orders', Reference::USE, 4, 'Orders\\Http\\StoreRequest']]));
    $baseline = Baseline::fromViolations($recorded);

    $now = layer_violations(layered_context([
        ['Shared', 'Orders', Reference::USE, 4, 'Orders\\Http\\StoreRequest'],
        ['Shared', 'Orders', Reference::USE, 9, 'Orders\\Http\\UpdateRequest'],
    ]));
    [$new] = $baseline->filter($now);

    expect($new)->toHaveCount(1)
        ->and($new[0]->target)->toBe('Orders\\Http\\UpdateRequest');
});

it('leaves the modules outside every layer unchecked', function () {
    expect(layer_violations(layered_context([['Reports', 'Orders'], ['Shared', 'Reports']])))->toBe([]);
});

it('counts listeners as depends_on does', function () {
    $context = layered_context([['Shared', 'Orders', Reference::LISTENER]]);

    expect(layer_violations($context))->toHaveCount(1)
        ->and(layer_violations($context, countListeners: false))->toBe([]);
});

it('builds the layer rule from the layers config', function () {
    $rules = RuleSet::fromConfig([], null, [], LAYERS);
    $layers = array_values(array_filter($rules, fn ($rule) => $rule->id() === 'layers'));

    expect($layers)->toHaveCount(1)
        ->and(iterator_to_array($layers[0]->check(layered_context([['Shared', 'Orders']])), false))->toHaveCount(1)
        ->and(iterator_to_array((new LayerRule([], true))->check(layered_context([['Shared', 'Orders']])), false))->toBe([]);
});

it('counts listeners by default', function () {
    $context = layered_context([['Shared', 'Orders', Reference::LISTENER]]);

    expect(iterator_to_array((new LayerRule(LAYERS))->check($context), false))->toHaveCount(1);
});

it('keeps the first layer of a module that is listed in several', function () {
    $context = layered_context([['Catalog', 'Orders']]);

    expect(iterator_to_array((new LayerRule([['Orders'], ['Catalog'], ['Orders']]))->check($context), false))->toHaveCount(1);
});

it('reports each class a file depends on, and each file that depends on a class', function () {
    $sameFile = layer_violations(layered_context([['Shared', 'Orders'], ['Shared', 'Catalog'], ['Shared', 'Orders']]));
    $sameTarget = layer_violations(layered_context([['Shared', 'Orders'], ['Catalog', 'Orders']]));

    expect(array_map(fn (Violation $violation) => $violation->targetModule, $sameFile))->toBe(['Orders', 'Catalog'])
        ->and(array_map(fn (Violation $violation) => $violation->sourceModule, $sameTarget))->toBe(['Shared', 'Catalog']);
});
