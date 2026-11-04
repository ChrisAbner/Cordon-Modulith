<?php

// The closest Pest arch() version of Cordon's rules for this fixture.
// Pest does not read #[Internal] or #[PublicApi], so the public API is listed by hand.

arch('Billing only uses the public API of Catalog')
    ->expect('App\Modules\Billing')
    ->not->toUse([
        'App\Modules\Catalog\Models',
        'App\Modules\Catalog\Listeners',
        'App\Modules\Catalog\Contracts\LegacyCatalog',
    ]);

arch('Catalog only uses the public API of Billing')
    ->expect('App\Modules\Catalog')
    ->not->toUse('App\Modules\Billing\Services');

// No cycle detection: one direction has to be forbidden by hand.
arch('Catalog does not depend on Billing')
    ->expect('App\Modules\Catalog')
    ->not->toUse('App\Modules\Billing');
