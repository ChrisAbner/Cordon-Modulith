<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Snapshot;
use Cordon\Documentation\DocumentationGenerator;
use Cordon\Documentation\EventEntry;
use Cordon\Documentation\EventInventory;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;

/**
 * @return array{0: Analyzer, 1: Snapshot}
 */
function events_app(): array
{
    $modules = (new NamespaceResolver(fixture_path('events-app/app/Modules'), 'App\\Modules'))->resolve();
    $analyzer = new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, RuleSet::fromConfig());

    return [$analyzer, $analyzer->snapshot($modules)];
}

/**
 * @return array<string, EventEntry>
 */
function events_by_class(): array
{
    $events = [];

    foreach (EventInventory::build(events_app()[1])->events as $event) {
        $events[$event->fqcn] = $event;
    }

    return $events;
}

it('finds events by namespace and by explicit dispatch or listen, but not jobs', function () {
    expect(array_keys(events_by_class()))->toBe([
        'App\\Modules\\Billing\\Events\\InvoiceVoided',
        'App\\Modules\\Billing\\Events\\PaymentFailed',
        'App\\Modules\\Orders\\Events\\OrderPlaced',
        'App\\Modules\\Shipping\\Domain\\ParcelLost',
    ]);
});

it('records who publishes and who listens to each event', function () {
    $placed = events_by_class()['App\\Modules\\Orders\\Events\\OrderPlaced'];
    $failed = events_by_class()['App\\Modules\\Billing\\Events\\PaymentFailed'];

    expect(array_column($placed->publishers, 'class'))->toBe(['App\\Modules\\Orders\\Actions\\PlaceOrder'])
        ->and(array_column($placed->listeners, 'class'))->toBe([
            'App\\Modules\\Billing\\Listeners\\ChargeOrder',
            'App\\Modules\\Shipping\\Providers\\ShippingServiceProvider',
        ])
        ->and(array_column($failed->publishers, 'module'))->toBe(['Billing'])
        ->and(array_column($failed->listeners, 'module'))->toBe(['Shipping'])
        ->and(events_by_class()['App\\Modules\\Billing\\Events\\InvoiceVoided']->publishers)->toBe([]);
});

it('generates an overview, a canvas per module and the event inventory', function () {
    [$analyzer, $snapshot] = events_app();
    $violations = $analyzer->check($snapshot)->violations;

    $files = (new DocumentationGenerator)->generate($snapshot, $violations, EventInventory::build($snapshot), fixture_path('events-app'));

    expect(array_keys($files))->toBe(['README.md', 'modules/Billing.md', 'modules/Orders.md', 'modules/Shipping.md', 'events.md'])
        ->and($files['README.md'])
        ->toContain('```mermaid')
        ->toContain('m0["Billing"]')
        ->toContain('m2 -->|2| m1')
        ->toContain('linkStyle 3 stroke:#d33')
        ->toContain('| [Shipping](modules/Shipping.md) | `App\\Modules\\Shipping` | Billing, Orders | - | 1 |')
        ->and($files['modules/Shipping.md'])
        ->toContain('| Orders | `Events\\OrderPlaced`, `Models\\Order` (internal) |')
        ->toContain('**internal_access** `app/Modules/Shipping/Listeners/PrepareParcel.php:11`')
        ->toContain('- Owned: `App\\Modules\\Shipping\\Domain\\ParcelLost`')
        ->and($files['modules/Orders.md'])
        ->toContain('- `Events\\OrderPlaced`')
        ->not->toContain('- `Models\\Order`')
        ->toContain('| Shipping | `Events\\OrderPlaced`, `Models\\Order` (internal) |')
        ->and($files['events.md'])
        ->toContain('| `App\\Modules\\Orders\\Events\\OrderPlaced` | Orders | Orders `App\\Modules\\Orders\\Actions\\PlaceOrder` |');
});

it('produces the same output on every run', function () {
    [$analyzer, $snapshot] = events_app();
    $violations = $analyzer->check($snapshot)->violations;
    $generate = fn () => (new DocumentationGenerator)->generate($snapshot, $violations, EventInventory::build($snapshot), fixture_path('events-app'));

    expect($generate())->toBe($generate());
});
