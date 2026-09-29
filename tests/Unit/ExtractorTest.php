<?php

use Cordon\Analysis\DeclaredVisibility;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\Reference;

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
    $file = sys_get_temp_dir().'/cordon-broken-'.uniqid().'.php';
    file_put_contents($file, '<?php class {');

    $analysis = (new PhpParserExtractor)->extract($file);
    unlink($file);

    expect($analysis->error)->not->toBeNull()
        ->and($analysis->references)->toBeEmpty();
});
