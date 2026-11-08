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
use Cordon\Rules\InternalAccessRule;
use Cordon\Rules\RuleSet;
use Cordon\Rules\UndeclaredDependencyRule;
use Cordon\Rules\UnusedDependencyRule;

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

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $dependencies  [from, to, kind]
 * @param  array<string, array<string, mixed>>  $config  Per-module settings.
 */
function typed_context(array $dependencies, array $config = []): AnalysisContext
{
    $modules = [];

    foreach (['A', 'B'] as $name) {
        $modules[$name] = Module::make($name, $name, '/p/'.$name)->withConfig($config[$name] ?? []);
    }

    $edges = array_map(
        fn (array $dependency) => new Edge($modules[$dependency[0]], $modules[$dependency[1]], new Reference('/p/'.$dependency[0].'/X.php', null, $dependency[1].'\\Events\\Y', 1, $dependency[2])),
        $dependencies,
    );

    return new AnalysisContext(new ModuleMap(array_values($modules)), SymbolTable::fromAnalyses([]), new PublicApiPolicy, $edges);
}

it('does not close a cycle through a listener unless configured to', function () {
    $context = typed_context([['A', 'B', Reference::USE], ['B', 'A', Reference::LISTENER]]);

    expect(iterator_to_array((new CycleRule)->check($context), false))->toBe([])
        ->and(iterator_to_array((new CycleRule(countListeners: true))->check($context), false))->toHaveCount(2);
});

it('still reports a cycle closed by a use, such as a job that injects a service', function () {
    $context = typed_context([['A', 'B', Reference::USE], ['B', 'A', Reference::LISTENER], ['B', 'A', Reference::USE]]);

    expect(iterator_to_array((new CycleRule)->check($context), false))->toHaveCount(2);
});

it('counts a listener as a dependency for depends_on unless configured not to', function () {
    $context = typed_context([['B', 'A', Reference::LISTENER]], ['B' => ['depends_on' => []]]);

    expect(iterator_to_array((new UndeclaredDependencyRule)->check($context), false))->toHaveCount(1)
        ->and(iterator_to_array((new UndeclaredDependencyRule(countListeners: false))->check($context), false))->toBe([]);
});

it('reads the listeners config when building the rules', function () {
    $rules = RuleSet::fromConfig([], null, ['cycles' => true, 'depends_on' => false]);
    $context = typed_context([['A', 'B', Reference::USE], ['B', 'A', Reference::LISTENER]], ['B' => ['depends_on' => []]]);
    $violations = array_merge(...array_map(fn ($rule) => iterator_to_array($rule->check($context), false), $rules));

    expect(array_map(fn (Violation $violation) => $violation->rule, $violations))->toBe(['cycles', 'cycles']);
});

it('warns about a depends_on entry the module never uses', function () {
    $unused = typed_context([], ['A' => ['depends_on' => ['B']]]);
    $used = typed_context([['A', 'B', Reference::USE]], ['A' => ['depends_on' => ['B']]]);
    $open = typed_context([], []);

    $violations = iterator_to_array((new UnusedDependencyRule)->check($unused), false);

    expect(array_map(fn (Violation $violation) => $violation->target, $violations))->toBe(['A -> B'])
        ->and($violations[0]->message)->toBe('Module [A] lists [B] in depends_on, but does not use it. Remove it so depends_on shows the real dependencies.')
        ->and($violations[0]->file)->toBeNull()
        ->and(iterator_to_array((new UnusedDependencyRule)->check($used), false))->toBe([])
        ->and(iterator_to_array((new UnusedDependencyRule)->check($open), false))->toBe([]);
});

it('counts a dependency used only by a listener as used, unless listeners are left out of depends_on', function () {
    $context = typed_context([['A', 'B', Reference::LISTENER]], ['A' => ['depends_on' => ['B']]]);

    expect(iterator_to_array((new UnusedDependencyRule)->check($context), false))->toBe([])
        ->and(iterator_to_array((new UnusedDependencyRule(countListeners: false))->check($context), false))->toHaveCount(1);
});

it('does not call a dependency unused when a file of the module does not parse', function () {
    $a = Module::make('A', 'A', '/p/A')->withConfig(['depends_on' => ['B']]);
    $b = Module::make('B', 'B', '/p/B');
    $context = new AnalysisContext(new ModuleMap([$a, $b]), SymbolTable::fromAnalyses([]), new PublicApiPolicy, [], ['/p/A/Broken.php' => 'Syntax error']);

    expect(iterator_to_array((new UnusedDependencyRule)->check($context), false))->toBe([]);
});

it('points a cross-module Eloquent relation to the recipe', function () {
    $a = Module::make('A', 'A', '/p/A');
    $b = Module::make('B', 'B', '/p/B');
    $edges = [
        new Edge($a, $b, new Reference('/p/A/Order.php', 'A\\Order', 'B\\Models\\Customer', 5, Reference::USE, true)),
        new Edge($a, $b, new Reference('/p/A/Order.php', 'A\\Order', 'B\\Models\\Invoice', 9)),
    ];
    $context = new AnalysisContext(new ModuleMap([$a, $b]), SymbolTable::fromAnalyses([]), new PublicApiPolicy, $edges);
    $messages = array_map(fn (Violation $violation) => $violation->message, iterator_to_array((new InternalAccessRule)->check($context), false));

    expect($messages[0])->toEndWith(' Relations between modules tie their tables together: see https://chrisabner.github.io/Cordon-Modulith/recipes/cross-module-relations')
        ->and($messages[1])->not->toContain('Relations between modules');
});
