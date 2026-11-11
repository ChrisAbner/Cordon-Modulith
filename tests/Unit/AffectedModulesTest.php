<?php

use Cordon\Analysis\AffectedModules;
use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Edge;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\SymbolTable;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;

/**
 * The context of hand-made modules ([name, parent] each) and edges ([from, to] each).
 *
 * @param  list<array{0: string, 1?: string|null}>  $modules
 * @param  list<array{0: string, 1: string}>  $links
 */
function affected_context(array $modules, array $links): AnalysisContext
{
    $map = new ModuleMap(array_map(
        static fn (array $module): Module => new Module($module[0], 'App\\'.str_replace('.', '\\', $module[0]), '/app/'.$module[0], parent: $module[1] ?? null),
        $modules,
    ));
    $edges = array_map(
        static fn (array $link): Edge => new Edge($map->get($link[0]), $map->get($link[1]), new Reference('/app/'.$link[0].'.php', null, $link[1].'\\Thing', 1)),
        $links,
    );

    return new AnalysisContext($map, SymbolTable::fromAnalyses([]), new PublicApiPolicy, $edges);
}

const AFFECTED_MODULES = [['Billing'], ['Billing.Invoices', 'Billing'], ['Billing.Payments', 'Billing'], ['Catalog']];

it('brings in the other nested modules of a parent that is affected only because a child is', function () {
    // Payments uses Catalog. Invoices uses the internals of Billing (not an edge: a child using its parent), which call Payments.
    $context = affected_context(AFFECTED_MODULES, [['Billing.Payments', 'Catalog'], ['Billing', 'Billing.Payments']]);

    expect(AffectedModules::of($context, ['Catalog']))->toBe([
        ['module' => 'Catalog', 'reason' => 'changed', 'through' => null],
        ['module' => 'Billing.Payments', 'reason' => 'depends_on', 'through' => 'Catalog'],
        ['module' => 'Billing', 'reason' => 'parent', 'through' => 'Billing.Payments'],
        ['module' => 'Billing.Invoices', 'reason' => 'nested', 'through' => 'Billing'],
    ]);
});

it('brings in the nested modules of a module that was already affected when it is reached again', function () {
    // Billing is reached as the parent of Payments first and as a dependent of Catalog later: its children come in all the same.
    $context = affected_context(AFFECTED_MODULES, [['Billing', 'Catalog']]);
    $affected = AffectedModules::of($context, ['Billing.Payments', 'Catalog']);

    expect($affected)->toContain(['module' => 'Billing', 'reason' => 'parent', 'through' => 'Billing.Payments'])
        ->and($affected)->toContain(['module' => 'Billing.Invoices', 'reason' => 'nested', 'through' => 'Billing'])
        ->and(array_column($affected, 'module'))->toHaveCount(4);
});

it('does not list a module twice', function () {
    $context = affected_context(AFFECTED_MODULES, [['Billing.Payments', 'Catalog'], ['Billing', 'Catalog'], ['Billing.Invoices', 'Catalog']]);
    $names = array_column(AffectedModules::of($context, ['Catalog', 'Billing.Payments']), 'module');

    expect($names)->toBe(array_values(array_unique($names)))->and($names)->toHaveCount(4);
});
