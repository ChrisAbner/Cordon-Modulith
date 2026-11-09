<?php

use Cordon\Analysis\DeclaredVisibility;
use Cordon\Analysis\FileAnalysis;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\Reference;
use Cordon\Tests\Oracle\Temp;

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

it('finds references inside property hooks', function () {
    expect(edge_case_targets('PropertyHooks.php'))->toEqualCanonicalizing([
        'Edge\\Target\\HookType',
        'Edge\\Target\\HookParam',
        'Edge\\Target\\HookBody',
        'Edge\\Target\\PromotedHookType',
        'Edge\\Target\\PromotedHookBody',
    ]);
});

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

it('marks the models of Eloquent relations', function () {
    $analysis = (new PhpParserExtractor)->extract(fixture_path('edge-cases/EloquentRelations.php'));
    $relations = [];

    foreach ($analysis->references as $reference) {
        if (str_starts_with($reference->target, 'Edge\\Target\\')) {
            $relations[substr($reference->target, strlen('Edge\\Target\\'))] = $reference->relation;
        }
    }

    ksort($relations);

    expect($relations)->toBe([
        'BelongsToManyTarget' => true,
        'BelongsToTarget' => true,
        'HasManyTarget' => true,
        'HasManyThroughTarget' => true,
        'HasOneTarget' => true,
        'HasOneThroughTarget' => true,
        'MorphManyTarget' => true,
        'MorphOneTarget' => true,
        'MorphToManyTarget' => true,
        'MorphedByManyTarget' => true,
        'QueryTarget' => false,
        'TypeTarget' => false,
    ]);
});

/**
 * @return FileAnalysis
 */
function extract_code(string $code)
{
    $file = Temp::removeAtExit(sys_get_temp_dir().'/cordon-extract-'.bin2hex(random_bytes(4)).'.php');
    file_put_contents($file, $code);

    return (new PhpParserExtractor)->extract($file);
}

it('reads listeners and visibility attributes whatever the letter case of their names', function () {
    $analysis = extract_code(implode("\n", [
        '<?php',
        'namespace App\\Listeners;',
        '#[\\cordon\\attributes\\publicapi]',
        'final class SendReceipt',
        '{',
        '    public function handle(\\app\\modules\\billing\\events\\invoicepaid $event): void {}',
        '    public function failed(\\App\\Modules\\Billing\\Events\\InvoicePaid $event): void {}',
        '}',
    ]));

    $events = array_values(array_filter($analysis->references, fn (Reference $reference) => stripos($reference->target, 'App\\Modules\\') === 0));

    expect(array_map(fn (Reference $reference) => $reference->kind, $events))->toBe([Reference::LISTENER, Reference::LISTENER])
        ->and($analysis->declarations[0]->visibility)->toBe(DeclaredVisibility::PublicApi);
});

it('keeps the signature of a reference it turns into a listener', function () {
    $analysis = extract_code("<?php\nnamespace App\\Listeners;\nfinal class SendReceipt\n{\n    public function handle(\\App\\Billing\\Events\\InvoicePaid \$event): void {}\n    public function failed(\\App\\Billing\\Events\\InvoicePaid \$event): void {}\n}\n");

    expect($analysis->references[1]->kind)->toBe(Reference::LISTENER)
        ->and($analysis->references[1]->signature)->toBe('failed() takes');
});
