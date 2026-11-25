<?php

use Illuminate\Support\Facades\Artisan;

/**
 * Point the commands at tests/Fixtures/nested-app, where Billing has the nested modules Invoices and Payments.
 *
 * @param  array<string, mixed>  $modules
 */
function use_nested_app(array $modules = []): void
{
    config()->set('cordon.resolvers.namespace.path', fixture_path('nested-app/app/Modules'));
    config()->set('cordon.modules', [...['Billing' => ['nested' => ['Invoices', 'Payments']]], ...$modules]);
    config()->set('cordon.host.paths', []);
    config()->set('cordon.baseline', fixture_path('nested-app/no-baseline.json'));
}

it('lists the nested modules under their parent', function () {
    use_nested_app();

    expect(Artisan::call('cordon:modules'))->toBe(0);
    $lines = array_values(array_filter(array_map('trim', explode("\n", Artisan::output())), fn (string $line) => str_starts_with($line, '|') || str_starts_with($line, '└')));
    $names = array_map(fn (string $line) => trim(explode('|', $line)[1] ?? ''), $lines);

    expect(array_values(array_filter($names, fn (string $name) => $name !== '')))->toBe(['Module', 'Billing', '└ Billing.Invoices', '└ Billing.Payments', 'Orders']);
});

it('verifies a project with nested modules and reports them by full name', function () {
    use_nested_app(['Orders' => ['depends_on' => ['Billing']]]);

    expect(Artisan::call('cordon:verify', ['--format' => 'json']))->toBe(1);
    $report = json_decode(Artisan::output(), true);
    $found = array_map(fn (array $v) => $v['rule'].' '.$v['source_module'].' -> '.$v['target_module'], $report['violations']);

    sort($found);

    expect($found)->toBe([
        'internal_access Billing -> Billing.Invoices',
        'internal_access Billing.Invoices -> Billing.Payments',
        'internal_access Orders -> Billing.Invoices',
        'internal_access Orders -> Billing.Payments',
    ]);
});

it('exports the nested modules inside clusters of their parent', function () {
    use_nested_app();
    Artisan::call('cordon:graph');

    expect(Artisan::output())->toContain('subgraph s0["Billing"]')->toContain('["Billing.Invoices"]');

    Artisan::call('cordon:graph', ['--format' => 'dot']);

    expect(Artisan::output())->toContain('subgraph "cluster_Billing" {');

    Artisan::call('cordon:graph', ['--format' => 'c4']);

    expect(Artisan::output())->toContain('Boundary(b0, "Billing") {')->toContain('Component(m1, "Billing.Invoices", "Module")');
});

it('explains a nested class as seen from outside and from inside its parent', function () {
    use_nested_app();
    Artisan::call('cordon:explain', ['class' => 'App\\Modules\\Billing\\Invoices\\Contracts\\InvoiceReader', '--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toMatchArray(['module' => 'Billing.Invoices', 'public' => false, 'public_inside' => true, 'inside' => 'Billing'])
        ->and($report['reason_inside'])->toBe('it is under the public namespace [Contracts]');

    Artisan::call('cordon:explain', ['class' => 'App\\Modules\\Billing\\Invoices\\Contracts\\InvoiceReader']);

    expect(Artisan::output())->toContain('Module:  Billing.Invoices')->toContain('Inside Billing:  public');
});

it('sees a nested module through its parent in why and impact', function () {
    use_nested_app();
    Artisan::call('cordon:why', ['from' => 'Orders', 'to' => 'Billing']);

    expect(Artisan::output())->toContain('Orders -> Billing: 4 references');

    Artisan::call('cordon:impact', ['module' => 'Billing']);

    expect(Artisan::output())->toContain('Orders');
});

it('keeps the violations of the nested modules with --module of their parent', function () {
    use_nested_app();

    expect(Artisan::call('cordon:verify', ['--module' => ['Billing'], '--format' => 'json']))->toBe(1);
    $sources = array_values(array_unique(array_column(json_decode(Artisan::output(), true)['violations'], 'source_module')));

    sort($sources);

    expect($sources)->toBe(['Billing', 'Billing.Invoices']);

    Artisan::call('cordon:verify', ['--module' => ['Billing.Invoices'], '--format' => 'json']);

    expect(array_unique(array_column(json_decode(Artisan::output(), true)['violations'], 'source_module')))->toBe(['Billing.Invoices']);
});
