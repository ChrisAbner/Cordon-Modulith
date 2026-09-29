<?php

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\ClassDeclaration;
use Cordon\Analysis\DeclaredVisibility;
use Cordon\Analysis\Edge;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\SymbolTable;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\CycleRule;

it('finds the shortest path of a cycle across three modules', function () {
    $a = Module::make('A', 'A', '/p/A');
    $b = Module::make('B', 'B', '/p/B');
    $c = Module::make('C', 'C', '/p/C');

    $edge = fn (Module $from, Module $to) => new Edge($from, $to, new Reference('/p/'.$from->name.'/X.php', null, $to->name.'\\Y', 1));

    $context = new AnalysisContext(
        new ModuleMap([$a, $b, $c]),
        SymbolTable::fromAnalyses([]),
        new PublicApiPolicy,
        [$edge($a, $b), $edge($b, $c), $edge($c, $a)],
    );

    $violations = iterator_to_array((new CycleRule)->check($context), false);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->target)->toBe('A -> B -> C -> A');
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
