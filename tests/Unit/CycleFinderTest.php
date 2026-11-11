<?php

use Cordon\Analysis\Violation;
use Cordon\Rules\CycleFinder;
use Cordon\Rules\Severity;

mutates(CycleFinder::class);

/**
 * @param  list<Violation>  $violations
 * @return list<string>
 */
function cycle_targets(array $violations): array
{
    return array_map(fn (Violation $violation) => (string) $violation->target, $violations);
}

it('finds no cycle in a graph without one', function () {
    expect(CycleFinder::violations([]))->toBe([])
        ->and(CycleFinder::violations(['A' => ['B', 'C'], 'B' => ['C'], 'C' => []]))->toBe([])
        ->and(CycleFinder::violations(['A' => ['A'], 'B' => []]))->toBe([]);
});

it('reports one violation per dependency of a ring, in module order', function () {
    $violations = CycleFinder::violations(['C' => ['A'], 'A' => ['B'], 'B' => ['C']]);

    expect(cycle_targets($violations))->toBe(['A -> B', 'B -> C', 'C -> A'])
        ->and(array_map(fn (Violation $violation) => $violation->sourceModule, $violations))->toBe(['A', 'B', 'C'])
        ->and(array_map(fn (Violation $violation) => $violation->targetModule, $violations))->toBe(['B', 'C', 'A'])
        ->and($violations[0])->toBeInstanceOf(Violation::class)
        ->and($violations[0]->rule)->toBe('cycles')
        ->and($violations[0]->file)->toBeNull()
        ->and($violations[0]->line)->toBeNull()
        ->and($violations[0]->severity)->toBe(Severity::Error);
});

it('explains a plain ring with the cycle it closes', function () {
    $violations = CycleFinder::violations(['A' => ['B'], 'B' => ['C'], 'C' => ['A']]);

    expect($violations[0]->message)->toBe('Module A depends on B, which closes the dependency cycle A -> B -> C -> A. Break it by inverting one dependency, for example with an event or a contract.')
        ->and($violations[1]->message)->toBe('Module B depends on C, which closes the dependency cycle B -> C -> A -> B. Break it by inverting one dependency, for example with an event or a contract.');
});

it('explains a direct cycle between two modules', function () {
    $violations = CycleFinder::violations(['A' => ['B'], 'B' => ['A']]);

    expect(array_map(fn (Violation $violation) => $violation->message, $violations))->toBe([
        'Module A depends on B, which closes the dependency cycle A -> B -> A. Break it by inverting one dependency, for example with an event or a contract.',
        'Module B depends on A, which closes the dependency cycle B -> A -> B. Break it by inverting one dependency, for example with an event or a contract.',
    ]);
});

it('says when the modules are tangled in several cycles, naming them in order', function () {
    $violations = CycleFinder::violations(['C' => ['B'], 'B' => ['A', 'C'], 'A' => ['B']]);

    expect(cycle_targets($violations))->toBe(['A -> B', 'B -> A', 'B -> C', 'C -> B'])
        ->and($violations[0]->message)->toBe('Module A depends on B, which closes the dependency cycle A -> B -> A. Modules [A, B, C] are tangled in several cycles: break them one dependency at a time, for example with an event or a contract.')
        ->and($violations[3]->message)->toBe('Module C depends on B, which closes the dependency cycle C -> B -> C. Modules [A, B, C] are tangled in several cycles: break them one dependency at a time, for example with an event or a contract.');
});

it('reports a group of modules that reach each other once, apart from the rest of the graph', function () {
    $graph = [
        'Web' => ['A', 'Util'],
        'A' => ['B', 'Util'],
        'B' => ['A'],
        'Util' => [],
        'X' => ['Y'],
        'Y' => ['X', 'Util'],
    ];

    expect(cycle_targets(CycleFinder::violations($graph)))->toBe(['A -> B', 'B -> A', 'X -> Y', 'Y -> X']);
});

it('ignores dependencies on modules that are not in the graph', function () {
    expect(cycle_targets(CycleFinder::violations(['A' => ['B', 'Missing'], 'B' => ['A']])))->toBe(['A -> B', 'B -> A']);
});

it('closes a cycle through the shortest way back', function (array $graph) {
    $violations = CycleFinder::violations($graph);

    expect($violations[0]->target)->toBe('A -> B')
        ->and($violations[0]->message)->toStartWith('Module A depends on B, which closes the dependency cycle A -> B -> C -> A. ');
})->with([
    'the short way first' => [['A' => ['B'], 'B' => ['C', 'D'], 'C' => ['A'], 'D' => ['E'], 'E' => ['A']]],
    'the long way first' => [['A' => ['B'], 'B' => ['D', 'C'], 'C' => ['A'], 'D' => ['E'], 'E' => ['A']]],
]);

it('does not loop on a cycle inside the way back', function () {
    $graph = ['A' => ['B'], 'B' => ['C', 'D'], 'C' => ['B', 'A'], 'D' => ['B', 'A']];

    $violations = CycleFinder::violations($graph);

    expect($violations[0]->target)->toBe('A -> B')
        ->and($violations[0]->message)->toStartWith('Module A depends on B, which closes the dependency cycle A -> B -> C -> A. ');
});

it('keeps a group of five modules together', function () {
    $graph = ['A' => ['B'], 'B' => ['C'], 'C' => ['D'], 'D' => ['E'], 'E' => ['A']];

    expect(cycle_targets(CycleFinder::violations($graph)))->toBe(['A -> B', 'B -> C', 'C -> D', 'D -> E', 'E -> A'])
        ->and(CycleFinder::violations($graph)[4]->message)->toStartWith('Module E depends on A, which closes the dependency cycle E -> A -> B -> C -> D -> E. ');
});

it('handles modules whose names are numbers, which PHP turns into integer keys', function () {
    $violations = CycleFinder::violations(['1' => ['2'], '2' => ['3'], '3' => ['1']]);

    expect(cycle_targets($violations))->toBe(['1 -> 2', '2 -> 3', '3 -> 1'])
        ->and($violations[0]->message)->toStartWith('Module 1 depends on 2, which closes the dependency cycle 1 -> 2 -> 3 -> 1. ');
});
