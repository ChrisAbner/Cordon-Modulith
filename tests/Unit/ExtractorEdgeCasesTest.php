<?php

use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\Reference;

/**
 * @return list<string>
 */
function edge_case_targets(string $file): array
{
    $analysis = (new PhpParserExtractor)->extract(fixture_path('edge-cases/'.$file));

    expect($analysis->error)->toBeNull();

    $targets = array_map(fn (Reference $reference) => $reference->target, $analysis->references);

    return array_values(array_unique(array_filter($targets, fn (string $target) => str_starts_with($target, 'Edge\\Target\\'))));
}

it('detects every class position of modern PHP', function (string $target) {
    expect(edge_case_targets('References.php'))->toContain('Edge\\Target\\'.$target);
})->with([
    'enum implements' => 'EnumInterface',
    'trait use inside a trait' => 'SomeTrait',
    'attribute' => 'MarkerAttribute',
    'class constant as attribute argument' => 'AttributeArgument',
    'promoted property' => 'PromotedProperty',
    'anonymous class parent' => 'AnonymousBase',
    'first-class callable' => 'CallableTarget',
    'instanceof inside match' => 'MatchTarget',
    'instanceof' => 'InstanceOfTarget',
    'multi-catch first type' => 'CaughtA',
    'multi-catch second type' => 'CaughtB',
    'union type' => 'UnionA',
    'union type second member' => 'UnionB',
    'intersection type' => 'IntersectionA',
    'intersection type second member' => 'IntersectionB',
    'DNF type' => 'DnfA',
    'DNF type intersection member' => 'DnfB',
    'DNF type union member' => 'DnfC',
    '::class constant' => 'ClassConstant',
    'static closure parameter' => 'ClosureParameter',
    'static closure return type' => 'StaticClosureReturn',
    'static property fetch' => 'StaticPropertyTarget',
    'class constant fetch' => 'ConstantFetchTarget',
    'new in initializer' => 'NewInInitializer',
]);

it('does not report imports that are never used', function () {
    expect(edge_case_targets('References.php'))->not->toContain('Edge\\Target\\UnusedImport');
});

it('does not report functions, constants, docblocks, strings or self references', function () {
    expect(edge_case_targets('NotReferences.php'))->toBe([]);
});

it('attributes references inside anonymous classes to the enclosing class', function () {
    $analysis = (new PhpParserExtractor)->extract(fixture_path('edge-cases/References.php'));
    $reference = array_values(array_filter($analysis->references, fn (Reference $r) => $r->target === 'Edge\\Target\\AnonymousBase'))[0];

    expect($reference->sourceClass)->toBe('Edge\\Source\\References');
});

it('declares enums and traits', function () {
    $analysis = (new PhpParserExtractor)->extract(fixture_path('edge-cases/References.php'));

    expect(array_map(fn ($declaration) => $declaration->fqcn, $analysis->declarations))->toBe([
        'Edge\\Source\\Status',
        'Edge\\Source\\LocalTrait',
        'Edge\\Source\\References',
    ]);
});
