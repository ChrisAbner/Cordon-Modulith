<?php

// Read by the PHPStan rule (StandaloneConfig), which runs without Laravel.
return [
    'modules' => [
        'Catalog' => [
            'interfaces' => [
                'Pricing' => ['Contracts\\PriceList', 'Data\\Price*'],
                'Inventory' => ['Contracts\\Stock'],
            ],
        ],
    ],
];
