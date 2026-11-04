<?php

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\ClassDeclaration;
use Cordon\Analysis\DeclaredVisibility;
use Cordon\Analysis\Edge;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\SymbolTable;
use Cordon\Analysis\Violation;
use Cordon\Baseline\Baseline;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\CycleRule;

/**
 * @param  list<array{0: string, 1: string}>  $dependencies
 * @return list<Violation>
 */
function cycle_violations(array $dependencies): array
{
    $modules = [];

    foreach (['A', 'B', 'C'] as $name) {
        $modules[$name] = Module::make($name, $name, '/p/'.$name);
    }

    $edges = array_map(
        fn (array $dependency) => new Edge($modules[$dependency[0]], $modules[$dependency[1]], new Reference('/p/'.$dependency[0].'/X.php', null, $dependency[1].'\\Y', 1)),
        $dependencies,
    );

    $context = new AnalysisContext(new ModuleMap(array_values($modules)), SymbolTable::fromAnalyses([]), new PublicApiPolicy, $edges);

    return iterator_to_array((new CycleRule)->check($context), false);
}

it('reports every dependency of a cycle with the shortest cycle it closes', function () {
    $violations = cycle_violations([['A', 'B'], ['B', 'C'], ['C', 'A']]);

    expect(array_map(fn ($violation) => $violation->target, $violations))->toBe(['A -> B', 'B -> C', 'C -> A'])
        ->and($violations[0]->sourceModule)->toBe('A')
        ->and($violations[0]->targetModule)->toBe('B')
        ->and($violations[0]->message)->toBe('Module A depends on B, which closes the dependency cycle A -> B -> C -> A. Break it by inverting one dependency, for example with an event or a contract.')
        ->and($violations[1]->message)->toContain('B -> C -> A -> B');
});

it('says when the modules of a cycle are tangled in several cycles', function () {
    $violations = cycle_violations([['A', 'B'], ['B', 'A'], ['B', 'C'], ['C', 'A']]);

    expect(array_map(fn ($violation) => $violation->target, $violations))->toBe(['A -> B', 'B -> A', 'B -> C', 'C -> A'])
        ->and($violations[2]->message)->toBe('Module B depends on C, which closes the dependency cycle B -> C -> A -> B. Modules [A, B, C] are tangled in several cycles: break them one dependency at a time, for example with an event or a contract.')
        ->and($violations[1]->message)->toContain('B -> A -> B');
});

it('reports a module that joins a cycle recorded in the baseline', function () {
    $baseline = Baseline::fromViolations(cycle_violations([['A', 'B'], ['B', 'A']]));

    [$remaining, $suppressed] = $baseline->filter(cycle_violations([['A', 'B'], ['B', 'A'], ['A', 'C'], ['C', 'A']]));

    expect(array_map(fn ($violation) => $violation->target, $remaining))->toBe(['A -> C', 'C -> A'])
        ->and($suppressed)->toBe(2);
});

it('keeps the rest of a recorded cycle suppressed when one dependency is removed', function () {
    $baseline = Baseline::fromViolations(cycle_violations([['A', 'B'], ['B', 'A'], ['B', 'C'], ['C', 'A']]));

    [$remaining, $suppressed] = $baseline->filter(cycle_violations([['A', 'B'], ['B', 'C'], ['C', 'A']]));

    expect($remaining)->toBe([])
        ->and($suppressed)->toBe(3);
});

it('applies the public API precedence rules', function () {
    $policy = new PublicApiPolicy(['Contracts']);
    $module = Module::make('Billing', 'App\\Billing', '/p/Billing');
    $open = Module::make('Shared', 'App\\Shared', '/p/Shared')->withConfig(['open' => true]);
    $custom = $module->withConfig(['public' => ['Services\\Checkout']]);

    $internal = new ClassDeclaration('App\\Billing\\Contracts\\Old', '/f.php', 1, DeclaredVisibility::Internal);
    $public = new ClassDeclaration('App\\Billing\\Models\\Invoice', '/f.php', 1, DeclaredVisibility::PublicApi);

    expect($policy->isPublic('App\\Billing\\Contracts\\Gateway', $module))->toBeTrue()
        ->and($policy->isPublic('App\\Billing\\ContractsHelper', $module))->toBeFalse()
        ->and($policy->isPublic('App\\Billing\\Models\\Invoice', $module))->toBeFalse()
        ->and($policy->isPublic('App\\Billing\\Models\\Invoice', $module, $public))->toBeTrue()
        ->and($policy->isPublic('App\\Billing\\Contracts\\Old', $module, $internal))->toBeFalse()
        ->and($policy->isPublic('App\\Shared\\Money', $open))->toBeTrue()
        ->and($policy->isPublic('App\\Billing\\Services\\Checkout', $custom))->toBeTrue()
        ->and($policy->isPublic('App\\Billing\\Services\\Checkout\\Step', $custom))->toBeTrue()
        ->and($policy->isPublic('App\\Billing\\Services\\Refunds', $custom))->toBeFalse();
});
