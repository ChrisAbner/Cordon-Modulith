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
    | nested:     modules inside this one: folders directly under it
    |             (['Invoices', 'Payments']) or true for every folder that is not
    |             a public namespace. They are named Parent.Child (Billing.Invoices)
    |             and configured under that name; outside the parent only its
    |             public API exists.
    | interfaces: named subsets of the public API, e.g.
    |             'Pricing' => ['Contracts\\PriceList', 'Data\\Price*'] (relative
    |             class names, a trailing * for a namespace or prefix). Another module
    |             then lists 'Catalog::Pricing' in depends_on to use only that part;
    |             'Catalog' and 'Catalog::*' still mean the whole public API.
    | packages:   the Composer packages this module may use, besides the shared
    |             ones below: names ('stripe/stripe-php') or prefixes ending in *
    |             ('symfony/*'). Any other package it uses is an
    |             external_dependency violation. Omit it to leave the module
    |             unrestricted; [] allows only the shared packages. A nested
    |             module without its own packages takes its parent's.
    | tests:      folders with the module's tests, relative to the project root,
    |             for cordon:affected --tests (['tests/Unit/Pricing']). Without it:
    |             tests/<Module> and the tests folder inside the module.
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
        'external_dependency' => 'error',
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
    | Packages
    |--------------------------------------------------------------------------
    |
    | Which Composer packages each module may use. A module that lists
    | `packages` (see Per-module settings) may only use those and the shared
    | ones here, read from vendor/composer/installed.json: run composer
    | install first. Modules that list nothing are not checked, and neither is
    | the code outside the modules. PHP's own classes, the project's and those of
    | a package that is not installed never count as packages.
    |
    */

    'packages' => [
        // 'shared' => ['laravel/framework', 'illuminate/*'],
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

    /*
    |--------------------------------------------------------------------------
    | Analysis cache
    |--------------------------------------------------------------------------
    |
    | A file where Cordon Modulith keeps what it read from each PHP file, keyed
    | by the file's content, so later runs only parse the files that changed.
    | The result is the same with or without it. null (the default) turns it
    | off. In Laravel a good place is 'storage/framework/cache/cordon.json';
    | a relative path is resolved against the project root. Do not commit it.
    | Skip it for one run with cordon:verify --no-cache.
    |
    */

    'cache' => null,

    /*
    |--------------------------------------------------------------------------
    | Class names in docblocks
    |--------------------------------------------------------------------------
    |
    | false (the default): only class names in code count as dependencies.
    | true: the class names in docblock types count too, as another kind of
    | reference that follows the same rules: @param, @return, @var, @property,
    | @method, @throws, @extends, @implements, @use, @mixin, the bound of
    | @template, and generics, unions and shapes inside them. Template names,
    | type aliases and pseudo-types (array-key, positive-int, class-string...)
    | are never classes, and a docblock that does not parse is skipped.
    | Turn it on when your code relies on generics such as Collection<int, Invoice>.
    |
    */

    'docblocks' => false,

];
