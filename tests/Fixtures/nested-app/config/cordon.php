<?php

// Read by the PHPStan rule (StandaloneConfig), which runs without Laravel.
return [
    'modules' => [
        'Billing' => ['nested' => ['Invoices', 'Payments']],
    ],
];
