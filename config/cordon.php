<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Module resolver
    |--------------------------------------------------------------------------
    |
    | How Cordon Modulith discovers your modules. "auto" picks nwidart/laravel-modules
    | when it finds module.json files, InterNACHI/modular when an app-modules
    | directory exists, and plain namespaces otherwise.
    |
    | Supported: "auto", "namespace", "nwidart", "internachi"
    |
    */

    'resolver' => env('CORDON_RESOLVER', 'auto'),

    'resolvers' => [

        // Every direct sub-directory of "path" is a module under "namespace".
        'namespace' => [
            'path' => 'app/Modules',
            'namespace' => 'App\\Modules',
        ],

        // null = read from config/modules.php, falling back to "Modules".
        'nwidart' => [
            'path' => null,
            'namespace' => null,
        ],

        // null = read from config/app-modules.php, falling back to "app-modules".
        // Namespaces are read from each module's composer.json.
        'internachi' => [
            'path' => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Public API
    |--------------------------------------------------------------------------
    |
    | Other modules may only use classes that belong to a module's public API:
    | classes under one of these namespaces, matched as whole segments at any
    | depth relative to the module namespace (Enums also covers Invoices\Enums)
    | or classes marked with #[Cordon\Attributes\PublicApi].
    | #[Cordon\Attributes\Internal] always wins.
    |
    */

    'public_namespaces' => ['Contracts', 'Events', 'Data', 'Enums', 'Exceptions'],

    /*
    |--------------------------------------------------------------------------
    | Per-module settings
    |--------------------------------------------------------------------------
    |
    | depends_on: modules this module may depend on. When set, any other
    |             dependency is reported. Omit it to leave dependencies open.
    | public:     extra namespaces or classes (relative) exposed as public API.
    | open:       every class is public (useful for a shared kernel module).
    |
    */

    'modules' => [
        // 'Billing' => [
        //     'depends_on' => ['Catalog', 'Shared'],
        //     'public' => ['Services\\BillingService'],
        // ],
        // 'Shared' => ['open' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rules
    |--------------------------------------------------------------------------
    |
    | Switch built-in rules on or off, and add your own: class names that
    | implement Cordon\Contracts\Rule (see docs/custom-rules.md).
    |
    */

    'rules' => [
        'internal_access' => true,
        'undeclared_dependency' => true,
        'cycles' => true,
        // App\Architecture\MyRule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded directories and baseline
    |--------------------------------------------------------------------------
    |
    | Directory names skipped anywhere inside a module. The baseline file
    | stores known violations so existing projects can adopt Cordon Modulith
    | gradually (php artisan cordon:verify --generate-baseline).
    |
    */

    'exclude' => ['vendor', 'node_modules', 'tests', 'Tests'],

    'baseline' => 'cordon-baseline.json',

];
