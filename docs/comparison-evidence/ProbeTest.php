<?php

// One probe per class: does Pest arch() see the use of the internal Product class?
foreach ([
    'App\Modules\Billing\Services\CheckoutService',
    'App\Modules\Billing\Services\Unloadable',
    'App\Modules\Billing\Services\DocblockOnly',
    'App\Modules\Billing\Services\StringName',
    'App\Modules\Billing\Tests\CheckoutServiceFixture',
] as $class) {
    arch('probe '.$class)
        ->expect($class)
        ->not->toUse('App\Modules\Catalog\Models\Product');
}
