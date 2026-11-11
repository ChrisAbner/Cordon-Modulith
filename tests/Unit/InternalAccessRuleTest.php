<?php

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Edge;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\SymbolTable;
use Cordon\Analysis\Violation;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\InternalAccessRule;

mutates(InternalAccessRule::class);

/**
 * @param  list<array{0: string, 1: int, 2: bool}>  $uses  [target, line, relation], all from Catalog to Billing in one file
 * @return list<Violation>
 */
function internal_violations(array $uses): array
{
    $catalog = Module::make('Catalog', 'App\\Catalog', '/p/Catalog');
    $billing = Module::make('Billing', 'App\\Billing', '/p/Billing');
    $edges = array_map(
        fn (array $use) => new Edge($catalog, $billing, new Reference('/p/Catalog/Stock.php', 'App\\Catalog\\Stock', $use[0], $use[1], relation: $use[2])),
        $uses,
    );

    return iterator_to_array((new InternalAccessRule)->check(new AnalysisContext(new ModuleMap([$catalog, $billing]), SymbolTable::fromAnalyses([]), new PublicApiPolicy, $edges)), false);
}

it('reports the use of an internal class, with who, where and why', function () {
    $violations = internal_violations([['App\\Billing\\Models\\Invoice', 12, false]]);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe('internal_access')
        ->and($violations[0]->message)->toBe('Module [Catalog] uses App\\Billing\\Models\\Invoice, which is internal to module [Billing]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).')
        ->and($violations[0]->file)->toBe('/p/Catalog/Stock.php')
        ->and($violations[0]->line)->toBe(12)
        ->and($violations[0]->sourceModule)->toBe('Catalog')
        ->and($violations[0]->targetModule)->toBe('Billing')
        ->and($violations[0]->target)->toBe('App\\Billing\\Models\\Invoice');
});

it('adds the relation hint only to the class used as the model of a relation', function () {
    $violations = internal_violations([
        ['App\\Billing\\Models\\Invoice', 12, false],
        ['App\\Billing\\Models\\Invoice', 30, true],
        ['App\\Billing\\Models\\Payment', 31, false],
    ]);

    expect($violations)->toHaveCount(2)
        ->and($violations[0]->line)->toBe(12)
        ->and($violations[0]->message)->toEndWith('#[PublicApi]). '.InternalAccessRule::RELATION_HINT)
        ->and($violations[1]->message)->toEndWith('#[PublicApi]).');
});

it('leaves the public API alone and reports an internal class once per file', function () {
    $violations = internal_violations([
        ['App\\Billing\\Contracts\\Charges', 3, false],
        ['App\\Billing\\Models\\Invoice', 4, false],
        ['App\\Billing\\Models\\Invoice', 9, false],
    ]);

    expect(array_map(fn (Violation $violation) => $violation->line, $violations))->toBe([4]);
});
