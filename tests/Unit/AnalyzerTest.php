<?php

use Cordon\Analysis\Violation;
use Cordon\Rules\Severity;
use Cordon\Support\Paths;

it('reports internal access across modules', function () {
    $targets = array_map(fn (Violation $v) => $v->target, violations_for(analyse_namespace_app(), 'internal_access'));

    expect($targets)->toEqualCanonicalizing([
        'App\\Modules\\Catalog\\Models\\Product',
        'App\\Modules\\Catalog\\Contracts\\LegacyCatalog',
    ]);
});

it('points to the file, line and modules of the offending reference', function () {
    $matches = array_values(array_filter(
        violations_for(analyse_namespace_app(), 'internal_access'),
        fn (Violation $v) => $v->target === 'App\\Modules\\Catalog\\Models\\Product',
    ));

    expect($matches)->toHaveCount(1)
        ->and(str_replace('\\', '/', (string) $matches[0]->file))->toEndWith('Billing/Services/CheckoutService.php')
        ->and($matches[0]->line)->toBe(13)
        ->and($matches[0]->sourceModule)->toBe('Billing')
        ->and($matches[0]->targetModule)->toBe('Catalog');
});

it('allows public namespaces, #[PublicApi] classes, public events and open modules', function () {
    $targets = array_map(fn (Violation $v) => $v->target, violations_for(analyse_namespace_app(), 'internal_access'));

    expect($targets)
        ->not->toContain('App\\Modules\\Catalog\\Contracts\\ProductCatalog')
        ->not->toContain('App\\Modules\\Catalog\\Support\\PriceFormatter')
        ->not->toContain('App\\Modules\\Billing\\Events\\InvoicePaid')
        ->not->toContain('App\\Modules\\Shared\\Money');
});

it('treats classes of a module that is not open as internal', function () {
    $targets = array_map(fn (Violation $v) => $v->target, violations_for(analyse_namespace_app([]), 'internal_access'));

    expect($targets)->toContain('App\\Modules\\Shared\\Money');
});

it('ignores namespaced function calls', function () {
    $targets = array_map(fn (Violation $v) => (string) $v->target, analyse_namespace_app()->violations);

    expect($targets)->not->toContain('App\\Modules\\Billing\\log_payment');
});

it('detects dependency cycles between modules', function () {
    // The fixture's only cycle closes through a listener, which counts with listeners.cycles.
    $cycles = violations_for(analyse_namespace_app(listeners: ['cycles' => true]), 'cycles');

    expect(array_map(fn (Violation $violation) => $violation->target, $cycles))->toBe(['Billing -> Catalog', 'Catalog -> Billing'])
        ->and($cycles[0]->message)->toContain('Billing -> Catalog -> Billing');
});

it('only checks depends_on for modules that declare it', function () {
    expect(violations_for(analyse_namespace_app(), 'undeclared_dependency'))->toBeEmpty();

    $violations = violations_for(analyse_namespace_app([
        'Shared' => ['open' => true],
        'Billing' => ['depends_on' => ['Catalog']],
    ]), 'undeclared_dependency');

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->sourceModule)->toBe('Billing')
        ->and($violations[0]->targetModule)->toBe('Shared');
});

it('can disable rules', function () {
    expect(violations_for(analyse_namespace_app(rules: ['cycles' => false]), 'cycles'))->toBeEmpty();
});

it('skips excluded directories such as tests', function () {
    foreach (analyse_namespace_app()->violations as $violation) {
        expect((string) Paths::relative(fixture_path('namespace-app'), $violation->file))->not->toContain('/tests/');
    }
});

it('reports the expected totals for the fixture app', function () {
    $result = analyse_namespace_app();

    // Billing <-> Catalog closes only through Catalog's listener of InvoicePaid, which is not a cycle by default.
    expect($result->violations)->toHaveCount(2)
        ->and(count($result->modules))->toBe(3)
        ->and($result->parseErrors)->toBeEmpty();
});

it('keeps the violations of a rule in warn apart from the errors', function () {
    $result = analyse_namespace_app(rules: ['cycles' => 'warn'], listeners: ['cycles' => true]);

    expect(violations_for($result, 'cycles'))->toBe([])
        ->and($result->warnings)->not->toBe([])
        ->and(array_unique(array_map(fn (Violation $warning) => $warning->rule, $result->warnings)))->toBe(['cycles'])
        ->and(array_unique(array_map(fn (Violation $warning) => $warning->severity, $result->warnings), SORT_REGULAR))->toBe([Severity::Warn]);
});

it('has no violations when every finding is a warning', function () {
    $result = analyse_namespace_app(rules: ['internal_access' => 'warn', 'undeclared_dependency' => 'warn', 'cycles' => 'warn']);

    expect($result->hasViolations())->toBeFalse()
        ->and($result->violations)->toBe([])
        ->and($result->warnings)->not->toBe([]);
});
