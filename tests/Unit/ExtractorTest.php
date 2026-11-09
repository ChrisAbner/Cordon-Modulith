<?php

use Cordon\Analysis\DeclaredVisibility;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\Reference;
use Cordon\Tests\Oracle\Temp;

it('collects declarations with their declared visibility', function () {
    $analysis = (new PhpParserExtractor)->extract(fixture_path('namespace-app/app/Modules/Catalog/Support/PriceFormatter.php'));

    expect($analysis->declarations)->toHaveCount(1)
        ->and($analysis->declarations[0]->fqcn)->toBe('App\\Modules\\Catalog\\Support\\PriceFormatter')
        ->and($analysis->declarations[0]->visibility)->toBe(DeclaredVisibility::PublicApi);
});

it('marks #[Internal] declarations', function () {
    $analysis = (new PhpParserExtractor)->extract(fixture_path('namespace-app/app/Modules/Catalog/Contracts/LegacyCatalog.php'));

    expect($analysis->declarations[0]->visibility)->toBe(DeclaredVisibility::Internal);
});

it('records class references but not imports or function calls', function () {
    $analysis = (new PhpParserExtractor)->extract(fixture_path('namespace-app/app/Modules/Catalog/Listeners/UpdateStockOnInvoicePaid.php'));
    $targets = array_map(fn (Reference $reference) => $reference->target, $analysis->references);

    expect($targets)->toBe(['App\\Modules\\Billing\\Events\\InvoicePaid'])
        ->and($analysis->references[0]->sourceClass)->toBe('App\\Modules\\Catalog\\Listeners\\UpdateStockOnInvoicePaid')
        ->and($analysis->references[0]->line)->toBe(9);
});

it('returns a parse error instead of throwing', function () {
    $file = Temp::removeAtExit(sys_get_temp_dir().'/cordon-broken-'.uniqid().'.php');
    file_put_contents($file, '<?php class {');

    $analysis = (new PhpParserExtractor)->extract($file);
    unlink($file);

    expect($analysis->error)->not->toBeNull()
        ->and($analysis->references)->toBeEmpty();
});

/**
 * Kind of each reference from $file in tests/Fixtures/listeners-app to a module class, by target.
 *
 * @return array<string, string>
 */
function listener_app_kinds(string $file): array
{
    $analysis = (new PhpParserExtractor)->extract(fixture_path('listeners-app/app/Modules/'.$file));
    $kinds = [];

    foreach ($analysis->references as $reference) {
        if (str_starts_with($reference->target, 'App\\Modules\\')) {
            $kinds[$reference->target] = $reference->kind;
        }
    }

    return $kinds;
}

it('marks the event type of a listener as a listener reference', function () {
    expect(listener_app_kinds('Billing/Listeners/ChargeOrder.php'))->toBe([
        'App\\Modules\\Orders\\Events\\OrderPlaced' => Reference::LISTENER,
    ]);
});

it('marks the events registered with Event::listen as listener references', function () {
    expect(listener_app_kinds('Billing/Providers/BillingServiceProvider.php'))->toBe([
        'App\\Modules\\Orders\\Events\\OrderPlaced' => Reference::LISTENER,
        'App\\Modules\\Billing\\Listeners\\ChargeOrder' => Reference::USE,
    ]);
});

it('does not mistake a service injected into handle() for an event', function () {
    expect(listener_app_kinds('Billing/Jobs/SyncLedger.php'))->toBe([
        'App\\Modules\\Orders\\Services\\Ledger' => Reference::USE,
    ]);
});

it('marks every other reference as a use', function () {
    expect(listener_app_kinds('Orders/Actions/PlaceOrder.php'))->toBe([
        'App\\Modules\\Billing\\Contracts\\Invoices' => Reference::USE,
        'App\\Modules\\Orders\\Events\\OrderPlaced' => Reference::USE,
    ]);
});

it('marks the event in the other methods of a listener, such as failed()', function () {
    $analysis = (new PhpParserExtractor)->extract(fixture_path('listeners-app/app/Modules/Billing/Listeners/ChargeOrderQueued.php'));
    $kinds = array_map(
        fn (Reference $reference) => $reference->line.':'.$reference->kind,
        array_values(array_filter($analysis->references, fn (Reference $reference) => $reference->target === 'App\\Modules\\Orders\\Events\\OrderPlaced')),
    );

    expect($kinds)->toBe(['11:'.Reference::LISTENER, '13:'.Reference::LISTENER]);
});

it('marks the events of the $listen map of an EventServiceProvider', function () {
    expect(listener_app_kinds('Billing/Providers/EventServiceProvider.php'))->toBe([
        'App\\Modules\\Orders\\Events\\OrderPlaced' => Reference::LISTENER,
        'App\\Modules\\Billing\\Listeners\\ChargeOrder' => Reference::USE,
    ]);
});

it('marks the event of a closure registered with Event::listen', function () {
    expect(listener_app_kinds('Billing/Providers/ClosureListeners.php'))->toBe([
        'App\\Modules\\Orders\\Events\\OrderPlaced' => Reference::LISTENER,
    ]);
});
