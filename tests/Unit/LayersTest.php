<?php

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Edge;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\SymbolTable;
use Cordon\Analysis\Violation;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\LayerRule;
use Cordon\Rules\RuleSet;

const LAYERS = [['Orders', 'Billing'], ['Catalog'], ['Shared']];

/**
 * @param  list<array{0: string, 1: string, 2?: string, 3?: int}>  $dependencies  [from, to, kind, line]
 */
function layered_context(array $dependencies): AnalysisContext
{
    $modules = [];

    foreach (['Billing', 'Catalog', 'Orders', 'Reports', 'Shared'] as $name) {
        $modules[$name] = Module::make($name, $name, '/p/'.$name);
    }

    $edges = array_map(
        fn (array $d) => new Edge($modules[$d[0]], $modules[$d[1]], new Reference('/p/'.$d[0].'/X.php', null, $d[1].'\\Contracts\\Y', $d[3] ?? 1, $d[2] ?? Reference::USE)),
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

it('reports a dependency on a layer above, once per file and module', function () {
    $violations = layer_violations(layered_context([['Shared', 'Orders', Reference::USE, 4], ['Shared', 'Orders', Reference::USE, 9]]));

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe('layers')
        ->and($violations[0]->line)->toBe(4)
        ->and($violations[0]->target)->toBe('Orders')
        ->and($violations[0]->message)->toBe('Module [Shared] (layer 3) depends on [Orders] (layer 1); a layer may only depend on the layers below it.');
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
