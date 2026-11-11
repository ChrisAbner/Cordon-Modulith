<?php

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Edge;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\SymbolTable;
use Cordon\Analysis\Violation;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\UndeclaredDependencyRule;

mutates(UndeclaredDependencyRule::class);

/**
 * @param  list<array{0: string, 1: string, 2: string, 3?: int, 4?: string}>  $uses  [from, to, file, line, kind]
 * @param  array<string, list<string>|null>  $declared  depends_on by module
 * @return list<Violation>
 */
function undeclared_violations(array $uses, array $declared, bool $countListeners = true): array
{
    $modules = [];

    foreach (['Billing', 'Catalog', 'Shared'] as $name) {
        $modules[$name] = new Module($name, 'App\\'.$name, '/p/'.$name, $declared[$name] ?? null);
    }

    $edges = array_map(
        fn (array $use) => new Edge($modules[$use[0]], $modules[$use[1]], new Reference($use[2], null, 'App\\'.$use[1].'\\Contracts\\Thing', $use[3] ?? 1, $use[4] ?? Reference::USE)),
        $uses,
    );

    $context = new AnalysisContext(new ModuleMap(array_values($modules)), SymbolTable::fromAnalyses([]), new PublicApiPolicy, $edges);

    return iterator_to_array((new UndeclaredDependencyRule($countListeners))->check($context), false);
}

it('reports a dependency that is not listed, with who, where and why', function () {
    $violations = undeclared_violations([['Billing', 'Catalog', '/p/Billing/A.php', 8]], ['Billing' => ['Shared']]);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe('undeclared_dependency')
        ->and($violations[0]->message)->toBe('Module [Billing] depends on module [Catalog] (via App\\Catalog\\Contracts\\Thing), but [Catalog] is not listed in its depends_on.')
        ->and($violations[0]->file)->toBe('/p/Billing/A.php')
        ->and($violations[0]->line)->toBe(8)
        ->and($violations[0]->sourceModule)->toBe('Billing')
        ->and($violations[0]->targetModule)->toBe('Catalog')
        ->and($violations[0]->target)->toBe('Catalog');
});

it('leaves listed dependencies and modules that declare nothing alone', function () {
    expect(undeclared_violations([['Billing', 'Catalog', '/p/Billing/A.php']], ['Billing' => ['Catalog']]))->toBe([])
        ->and(undeclared_violations([['Billing', 'Catalog', '/p/Billing/A.php']], ['Billing' => null]))->toBe([])
        ->and(undeclared_violations([['Billing', 'Catalog', '/p/Billing/A.php']], ['Billing' => []]))->toHaveCount(1);
});

it('reports once per file and module, and again for another file or module', function () {
    $violations = undeclared_violations([
        ['Billing', 'Catalog', '/p/Billing/A.php', 1],
        ['Billing', 'Catalog', '/p/Billing/A.php', 2],
        ['Billing', 'Shared', '/p/Billing/A.php', 3],
        ['Billing', 'Catalog', '/p/Billing/B.php', 4],
    ], ['Billing' => []]);

    expect(array_map(fn (Violation $violation) => $violation->line, $violations))->toBe([1, 3, 4]);
});

it('does not mix up two files and modules whose names join into the same text', function () {
    $violations = undeclared_violations([
        ['Billing', 'Catalog', '/p/Billing/A.php', 1],
        ['Billing', 'Shared', '/p/Billing/A.php', 2],
    ], ['Billing' => []]);

    expect($violations)->toHaveCount(2);
});

it('counts listeners unless told not to', function () {
    $uses = [['Billing', 'Catalog', '/p/Billing/A.php', 1, Reference::LISTENER]];

    expect(undeclared_violations($uses, ['Billing' => []]))->toHaveCount(1)
        ->and(undeclared_violations($uses, ['Billing' => []], countListeners: false))->toBe([])
        ->and(undeclared_violations([['Billing', 'Catalog', '/p/Billing/A.php']], ['Billing' => []], countListeners: false))->toHaveCount(1);
});
