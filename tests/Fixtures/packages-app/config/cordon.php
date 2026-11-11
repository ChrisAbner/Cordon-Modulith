<?php

// Read by the standalone CLI (StandaloneConfig), which runs without Laravel.
return [
    'packages' => [
        'shared' => ['laravel/framework', 'illuminate/*'],
    ],
    'modules' => [
        'Billing' => ['packages' => ['stripe/stripe-php']],
        'Catalog' => ['packages' => ['guzzlehttp/guzzle']],
    ],
];
