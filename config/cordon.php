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
    | metadata:   free-form information about the module (owner, description...).
    |             Cordon does not read it yet; the key is reserved for it.
    |
    | Unknown keys and modules are configuration errors: cordon:verify stops
    | before analysing, so a typo such as depend_on cannot leave a module open.
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
    | The severity of each rule: "error" fails cordon:verify, "warn" reports
    | without failing (handy while a rule is being adopted), "off" skips it.
    | true and false still work as error and off.
    |
    | Add your own rules: class names that implement Cordon\Contracts\Rule
    | (see docs/custom-rules.md), listed as values, or as keys with a severity.
    |
    */

    'rules' => [
        'internal_access' => 'error',
        'undeclared_dependency' => 'error',
        'cycles' => 'error',
        'unused_dependency' => 'warn',
        'host_access' => 'warn',
        'layers' => 'error',
        'public_api_leak' => 'warn',
        // App\Architecture\MyRule::class,
        // App\Architecture\OtherRule::class => 'warn',
    ],

    /*
    |--------------------------------------------------------------------------
    | Layers
    |--------------------------------------------------------------------------
    |
    | A shorthand for depends_on in large projects: layers from the top down,
    | each a list of module names. A module may depend on its own layer and
    | the layers below, never above. Modules left out are not checked.
    |
    */

    'layers' => [
        // ['Orders', 'Billing'],
        // ['Catalog'],
        // ['Shared'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Code outside the modules
    |--------------------------------------------------------------------------
    |
    | Directories, relative to the project root, of the code that is not in a
    | module: controllers in app/Http, Filament resources, routes, migrations.
    | It may only use the public API of the modules (rule host_access); files
    | of a module inside these directories still belong to their module.
    | Set paths to [] to leave this code unchecked.
    |
    */

    'host' => [
        'paths' => ['app', 'routes', 'database'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Event listeners
    |--------------------------------------------------------------------------
    |
    | A listener's reference to another module's event (handle(OrderPlaced $e)
    | with the event under an Events namespace, and failed() in that class;
    | the $listen map of an EventServiceProvider; Event::listen) counts as a
    | dependency for depends_on, but does not close a dependency cycle: the
    | module that publishes an event does not depend on who listens to it.
    |
    */

    'listeners' => [
        'cycles' => false,
        'depends_on' => true,
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
