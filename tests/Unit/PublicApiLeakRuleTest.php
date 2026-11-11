<?php

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\ClassDeclaration;
use Cordon\Analysis\DeclaredVisibility;
use Cordon\Analysis\FileAnalysis;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\SymbolTable;
use Cordon\Analysis\Violation;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\PublicApiLeakRule;

mutates(PublicApiLeakRule::class);

// A notice in the rule, such as reading the name of a module that is not there, fails the test.
beforeEach(function () {
    set_error_handler(static function (int $level, string $message, string $path, int $line): never {
        throw new ErrorException($message, 0, $level, $path, $line);
    });
});

afterEach(fn () => restore_error_handler());

const LEAK_SOURCE = 'App\\Billing\\Contracts\\Invoices';
const LEAK_TARGET = 'App\\Billing\\Models\\Invoice';

/**
 * @param  list<Reference>  $signatures
 * @param  list<ClassDeclaration>  $declarations
 * @return list<Violation>
 */
function leak_violations(array $signatures, array $declarations = []): array
{
    $modules = new ModuleMap([
        Module::make('Billing', 'App\\Billing', '/p/Billing'),
        Module::make('Catalog', 'App\\Catalog', '/p/Catalog'),
    ]);
    $context = new AnalysisContext($modules, SymbolTable::fromAnalyses([new FileAnalysis('/p/All.php', $declarations)]), new PublicApiPolicy, [], signatures: $signatures);

    return iterator_to_array((new PublicApiLeakRule)->check($context), false);
}

function leak_reference(?string $class = LEAK_SOURCE, string $target = LEAK_TARGET, ?string $signature = 'find() returns', string $file = '/p/Billing/Contracts/Invoices.php', int $line = 12): Reference
{
    return new Reference($file, $class, $target, $line, signature: $signature);
}

it('reports an internal class in the signature of a public one, with where and why', function () {
    $violations = leak_violations([leak_reference()]);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe('public_api_leak')
        ->and($violations[0]->message)->toBe('App\\Billing\\Contracts\\Invoices::find() returns App\\Billing\\Models\\Invoice, which is internal to [Billing]: other modules reach it through the public API.')
        ->and($violations[0]->file)->toBe('/p/Billing/Contracts/Invoices.php')
        ->and($violations[0]->line)->toBe(12)
        ->and($violations[0]->sourceModule)->toBe('Billing')
        ->and($violations[0]->targetModule)->toBe('Billing')
        ->and($violations[0]->target)->toBe(LEAK_TARGET);
});

it('reports a leak once per file and internal class, at the first member', function () {
    $violations = leak_violations([
        leak_reference(signature: 'find() returns', line: 12),
        leak_reference(signature: 'save() takes', line: 15),
        leak_reference(target: 'App\\Billing\\Models\\Payment', signature: 'pay() takes', line: 18),
        leak_reference(file: '/p/Billing/Contracts/Other.php', class: 'App\\Billing\\Contracts\\Other', signature: 'find() returns', line: 7),
    ]);

    expect(array_map(fn (Violation $violation) => $violation->file.':'.$violation->line.':'.$violation->target, $violations))->toBe([
        '/p/Billing/Contracts/Invoices.php:12:App\\Billing\\Models\\Invoice',
        '/p/Billing/Contracts/Invoices.php:18:App\\Billing\\Models\\Payment',
        '/p/Billing/Contracts/Other.php:7:App\\Billing\\Models\\Invoice',
    ]);
});

it('reports nothing when the reference is not a leak', function (Reference $reference) {
    expect(leak_violations([$reference]))->toBe([]);
})->with([
    'no source class' => [fn () => leak_reference(class: null)],
    'a source class outside the modules' => [fn () => leak_reference(class: 'Vendor\\Invoices')],
    'not in a signature' => [fn () => leak_reference(signature: null)],
    'a class of another module' => [fn () => leak_reference(target: 'App\\Catalog\\Models\\Product')],
    'a class outside the modules' => [fn () => leak_reference(target: 'Vendor\\Money')],
    'a source that is internal itself' => [fn () => leak_reference(class: 'App\\Billing\\Services\\Charger')],
    'a target that is public' => [fn () => leak_reference(target: 'App\\Billing\\Contracts\\Payment')],
]);

it('follows #[Internal] and #[PublicApi] on both ends', function () {
    $source = new ClassDeclaration('App\\Billing\\Services\\Charger', '/p/Billing/Services/Charger.php', 1, DeclaredVisibility::PublicApi);
    $target = new ClassDeclaration('App\\Billing\\Contracts\\Payment', '/p/Billing/Contracts/Payment.php', 1, DeclaredVisibility::Internal);
    $internalSource = new ClassDeclaration(LEAK_SOURCE, '/p/Billing/Contracts/Invoices.php', 1, DeclaredVisibility::Internal);

    expect(leak_violations([leak_reference(class: 'App\\Billing\\Services\\Charger')], [$source]))->toHaveCount(1)
        ->and(leak_violations([leak_reference(target: 'App\\Billing\\Contracts\\Payment')], [$target]))->toHaveCount(1)
        ->and(leak_violations([leak_reference()], [$internalSource]))->toBe([]);
});
