<?php

use Cordon\Module\LayeredLayout;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;

function modules_named(string ...$names): ModuleMap
{
    return new ModuleMap(array_map(fn (string $name) => Module::make($name, 'App\\'.$name, '/app/'.$name), $names));
}

it('recognises the layers of a standard Laravel app', function () {
    expect(LayeredLayout::layersIn(modules_named('Http', 'Models', 'Providers', 'Services')))
        ->toBe(['Http', 'Models', 'Providers', 'Services']);
});

it('does not flag modules split by feature', function (array $names) {
    expect(LayeredLayout::layersIn(modules_named(...$names)))->toBe([]);
})->with([
    'feature modules' => [['Billing', 'Catalog', 'Shared']],
    'one module named like a layer' => [['Billing', 'Catalog', 'Notifications']],
]);
