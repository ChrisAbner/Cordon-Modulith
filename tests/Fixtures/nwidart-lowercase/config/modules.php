<?php

// Uses framework path helpers, which must resolve against the project root
// when Cordon loads this file without a booted application.
return [
    'namespace' => 'Modules',
    'paths' => [
        'modules' => base_path('modules'),
        'assets' => public_path('modules'),
    ],
];
