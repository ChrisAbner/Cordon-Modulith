# Configuration

Publish the config file with `php artisan vendor:publish --tag=cordon-config`. Every key is optional.

```php
// config/cordon.php
return [
    'resolver' => 'auto', // auto, namespace, nwidart, internachi

    'resolvers' => [
        'namespace' => ['path' => 'app/Modules', 'namespace' => 'App\\Modules'],
        'nwidart' => ['path' => null, 'namespace' => null],   // null: read config/modules.php
        'internachi' => ['path' => null],                       // null: read config/app-modules.php
    ],

    'public_namespaces' => ['Contracts', 'Events', 'Data', 'Enums', 'Exceptions'],

    'modules' => [
        'Billing' => [
            'depends_on' => ['Catalog', 'Shared'],        // anything else is reported
            'public' => ['Services\\BillingService'],     // extra public classes/namespaces
        ],
        'Shared' => ['open' => true],                     // every class is public
    ],

    'rules' => [
        'internal_access' => true,
        'undeclared_dependency' => true,
        'cycles' => true,
        // App\Architecture\MyRule::class,               // custom rules
    ],

    'exclude' => ['vendor', 'node_modules', 'tests', 'Tests'],

    'baseline' => 'cordon-baseline.json',
];
```

## resolver

`auto` picks `nwidart` when it finds `module.json` files in the nwidart modules path, `internachi` when an `app-modules` directory exists, and `namespace` otherwise. Force one with `CORDON_RESOLVER` or the `resolver` key.

## resolvers

- `namespace`: every direct sub-directory of `path` is a module named after it, under `namespace`. Use it for DDD layouts too: `'path' => 'src/Domain', 'namespace' => 'Domain'`.
- `nwidart`: every directory with a `module.json`. Path and namespace default to `modules.paths.modules` and `modules.namespace` from nwidart's config.
- `internachi`: every directory with a `composer.json`; the namespace comes from its PSR-4 entry (the one mapped to `src/` when there are several).

## modules

Per-module settings, keyed by module name as shown by `cordon:modules`:

| Key | Meaning |
|---|---|
| `depends_on` | Modules this module may depend on. Any other dependency is an `undeclared_dependency`. Omit it to leave dependencies open; `[]` means "no dependencies". |
| `public` | Extra public namespaces or classes, relative to the module namespace. |
| `open` | Every class of the module is public. |

`cordon:verify` warns about unknown module names in this section.

## exclude

Directory names skipped at any depth inside a module.

## baseline

Path of the baseline file, relative to the project root. See [adopting in an existing project](./baseline).

## Using the config outside Laravel

The [PHPStan rule](./phpstan) reads `config/cordon.php` without booting Laravel. Keep it a plain array; `env()` is fine.
