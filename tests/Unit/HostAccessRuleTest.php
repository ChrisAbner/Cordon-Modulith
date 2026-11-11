<?php

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\ClassDeclaration;
use Cordon\Analysis\DeclaredVisibility;
use Cordon\Analysis\Edge;
use Cordon\Analysis\FileAnalysis;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\SymbolTable;
use Cordon\Analysis\Violation;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\HostAccessRule;
use Cordon\Rules\InternalAccessRule;

mutates(HostAccessRule::class);

/**
 * @param  list<array{0: string, 1: string, 2?: int, 3?: bool}>  $uses  [file, target, line, relation]
 * @param  list<ClassDeclaration>  $declarations
 * @return list<Violation>
 */
function host_violations(array $uses, array $declarations = []): array
{
    $billing = Module::make('Billing', 'App\\Billing', '/p/Billing');
    $modules = new ModuleMap([$billing]);
    $edges = array_map(
        fn (array $use) => new Edge(Module::host(), $billing, new Reference($use[0], null, $use[1], $use[2] ?? 1, relation: $use[3] ?? false)),
        $uses,
    );
    $context = new AnalysisContext($modules, SymbolTable::fromAnalyses([new FileAnalysis('/p/All.php', $declarations)]), new PublicApiPolicy, [], hostEdges: $edges);

    return iterator_to_array((new HostAccessRule)->check($context), false);
}

it('reports host code that uses an internal class, with where and why', function () {
    $violations = host_violations([['/p/routes/web.php', 'App\\Billing\\Models\\Invoice', 7]]);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe('host_access')
        ->and($violations[0]->message)->toBe('Code outside the modules uses App\\Billing\\Models\\Invoice, which is internal to module [Billing]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).')
        ->and($violations[0]->file)->toBe('/p/routes/web.php')
        ->and($violations[0]->line)->toBe(7)
        ->and($violations[0]->sourceModule)->toBe('host')
        ->and($violations[0]->targetModule)->toBe('Billing')
        ->and($violations[0]->target)->toBe('App\\Billing\\Models\\Invoice');
});

it('leaves host code that uses the public API alone', function () {
    $marked = new ClassDeclaration('App\\Billing\\Models\\Invoice', '/p/Billing/Models/Invoice.php', 1, DeclaredVisibility::PublicApi);

    expect(host_violations([['/p/routes/web.php', 'App\\Billing\\Contracts\\Charges']]))->toBe([])
        ->and(host_violations([['/p/routes/web.php', 'App\\Billing\\Models\\Invoice']], [$marked]))->toBe([]);
});

it('reports once per file and class', function () {
    $violations = host_violations([
        ['/p/routes/web.php', 'App\\Billing\\Models\\Invoice', 7],
        ['/p/routes/web.php', 'App\\Billing\\Models\\Invoice', 20],
        ['/p/routes/web.php', 'App\\Billing\\Models\\Payment', 21],
        ['/p/routes/api.php', 'App\\Billing\\Models\\Invoice', 3],
    ]);

    expect(array_map(fn (Violation $violation) => basename((string) $violation->file).':'.$violation->line.':'.basename(str_replace('\\', '/', (string) $violation->target)), $violations))->toBe([
        'web.php:7:Invoice',
        'web.php:21:Payment',
        'api.php:3:Invoice',
    ]);
});

it('adds the relation hint when the file uses the class as the model of a relation', function () {
    $hint = ' '.InternalAccessRule::RELATION_HINT;
    $violations = host_violations([
        ['/p/app/Order.php', 'App\\Billing\\Models\\Invoice', 5],
        ['/p/app/Order.php', 'App\\Billing\\Models\\Invoice', 9, true],
        ['/p/app/Order.php', 'App\\Billing\\Models\\Payment', 11],
        ['/p/app/User.php', 'App\\Billing\\Models\\Invoice', 4],
    ]);

    expect(array_map(fn (Violation $violation) => str_ends_with($violation->message, $hint), $violations))->toBe([true, false, false])
        ->and($violations[0]->line)->toBe(5)
        ->and($violations[1]->message)->toBe('Code outside the modules uses App\\Billing\\Models\\Payment, which is internal to module [Billing]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).')
        ->and(str_ends_with($violations[0]->message, '#[PublicApi]).'.$hint))->toBeTrue();
});
