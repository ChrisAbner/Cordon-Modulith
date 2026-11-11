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
        'internal_access' => 'error',                     // error, warn or off
        'undeclared_dependency' => 'error',
        'cycles' => 'error',
        'unused_dependency' => 'warn',                    // new in 0.4: warns by default
        // App\Architecture\MyRule::class,               // custom rules
        // App\Architecture\OtherRule::class => 'warn',
    ],

    'listeners' => [
        'cycles' => false,     // listening to another module's event never closes a cycle
        'depends_on' => true,  // but it is a dependency for depends_on
    ],

    'exclude' => ['vendor', 'node_modules', 'tests', 'Tests'],

    'baseline' => 'cordon-baseline.json',

    'cache' => null,   // 'storage/framework/cache/cordon.json' to reuse the analysis of unchanged files
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
| `strict` | `true` keeps the module's violations out of the baseline: they always fail, and `--generate-baseline` does not record them. For a module that is clean and must stay clean. |
| `nested` | Modules inside this one: a list of folders directly under the module (`['Invoices', 'Payments']`), or `true` for every folder that is not a public namespace. See [nested modules](#nested-modules). |
| `metadata` | Free-form information about the module: `cordon:docs` shows its `description` and `owner`. |

In `depends_on`, `Module::Interface` is reserved for named interfaces in a later version.

## Nested modules

A module can contain modules of its own, as in Spring Modulith 1.3. List their folders in `nested`:

```php
'modules' => [
    'Billing' => ['nested' => ['Invoices', 'Payments']],   // or true: every folder that is not Contracts, Events, Data...
    'Billing.Payments' => ['depends_on' => []],
    'Billing.Invoices' => ['depends_on' => ['Billing.Payments']],
    'Orders' => ['depends_on' => ['Billing']],
],
```

`app/Modules/Billing/Invoices` becomes the module `Billing.Invoices` (the dot separates it from its parent), with the namespace `App\Modules\Billing\Invoices`. Its settings go under that full name, and so do its entries in the baseline, the reports and `cordon:modules`, which lists it under `Billing`. A nested module can have nested modules too. With the nwidart and InterNACHI resolvers the folders are those of the namespace root (`app/` and `src/`). A folder with a dot in its name cannot be nested: `nested` rejects it, and `nested => true` leaves it out.

What changes for the rules:

- **Outside the parent a child does not exist.** `Orders` sees `Billing` and its public API only. A class such as `Billing\Invoices\Contracts\InvoiceReader` is internal to `Orders` (relative to `Billing` it is `Invoices\Contracts\InvoiceReader`, and the public namespaces do not reach into a nested module) unless the public list of `Billing` names it (`'public' => ['Invoices\\Contracts']`), the class has `#[PublicApi]`, or `Billing` is open. `Orders` lists `Billing` in `depends_on`, never `Billing.Invoices`, which is a configuration error.
- **Inside the parent the public API of each child applies.** `Billing` using its children, and the children between them, may only use each other's public API (`Contracts`, `#[PublicApi]`, the child's own `public` and `open`).
- **A child may use anything of its ancestors.** `Billing.Invoices` can use an internal class of `Billing`, such as `Billing\Services\Money`, even one marked `#[Internal]`: the child belongs to the parent. The other way round, the parent using an internal class of a child, is still reported.
- **`depends_on` of a child** names its siblings (`Billing.Payments`) and the modules outside `Billing`. A parent uses its children, and a child its parent, without declaring it. `unused_dependency` and `layers` look at the same visible modules.
- **Cycles** are found between full names (`Billing.Invoices -> Billing.Payments -> Billing.Invoices`). A child using its parent is containment, not a dependency, so it never closes a cycle with the parent using the child.
- `public_api_leak` checks every viewpoint: a public class of a child that exposes an internal one is reported whether it is public to its siblings or to the whole app through `Billing`'s public list.
- `host_access` treats the code outside the modules like any other module outside the parent.

`cordon:verify --module Billing` (and `violationsOf('Billing')` in the Pest helper) keeps the violations of `Billing.Invoices` and the other children too, since from outside they are part of `Billing`. `--module Billing.Invoices` keeps only its own.

A project without `nested` behaves as before.

## rules

The severity of each rule:

| Value | Effect |
|---|---|
| `error` (or `true`) | Violations fail `cordon:verify`, the Pest expectation and the PHPStan rule. |
| `warn` | Violations are reported (as `warnings` in JSON, `::warning` on GitHub) but never fail, and `--generate-baseline` does not record them. Use it while a rule is being adopted. |
| `off` (or `false`) | The rule does not run. |

Custom rules are classes that implement `Cordon\Contracts\Rule`, listed as values (`MyRule::class`) or as keys with a severity (`MyRule::class => 'warn'`). See [custom rules](./custom-rules).

## layers

Layers from the top down, each a list of module names; see [the layers rule](./rules#layers). A module may be in one layer only, a layer may not be empty, and every name must be a detected module. `[]` (the default) turns layers off.

## host

The code outside the modules, which [`host_access`](./rules#host-access) checks.

| Key | Default | Meaning |
|---|---|---|
| `paths` | `['app', 'routes', 'database']` | Directories relative to the project root. Files of a module inside them (such as `app/Modules`) still belong to their module. `[]` leaves that code unchecked. |

While `paths` is set, no module may be named `host`.

## listeners

How a listener's reference to another module's event counts. See [what counts as a listener](../faq#does-listening-to-another-module-s-event-count-as-a-dependency): `handle()`/`__invoke()` with an event under an `Events` namespace (and the same event elsewhere in that class, such as `failed()`), the `$listen` map of an `EventServiceProvider`, and `Event::listen()` with a class name or a closure.

| Key | Default | Meaning |
|---|---|---|
| `cycles` | `false` | Whether listening can close a dependency cycle. The publisher does not depend on its listeners, so by default it does not. |
| `depends_on` | `true` | Whether listening needs a `depends_on` entry. |

## Validation

`cordon:verify` checks the whole file before it analyses anything. Unknown keys, unknown modules or dependencies, settings of the wrong type and unknown rules stop it with exit code `2`, and name the closest valid key:

```text
Unknown key [depend_on] for module [Billing]. Did you mean [depends_on]? Valid keys: depends_on, public, open, strict, nested, metadata.
```

`cordon:modules` shows the same messages as warnings, and the PHPStan rule reports them once as `cordon.configuration`.

## exclude

Directory names skipped at any depth inside a module.

## baseline

Path of the baseline file, relative to the project root. See [adopting in an existing project](./baseline).

## cache

`null` by default: every run parses every file. Set it to a file path, relative to the project root (or absolute), and Cordon Modulith keeps what it read from each PHP file there, keyed by a hash of the file's content. The next run only parses the files whose content changed. In a Laravel project `storage/framework/cache/cordon.json` is a good place, since that directory is already ignored by git.

```php
'cache' => 'storage/framework/cache/cordon.json',
```

The cache never changes a result: the report is the same cold, warm or with `cache` set to `null`. It stores only what was read from each file, not the violations, so changing `depends_on`, the rules or the baseline needs no new cache. It starts from scratch by itself when Cordon Modulith, nikic/php-parser or PHP change version. Deleting the file is always safe, and so is a file that cannot be read or written: `cordon:verify` warns (`Cordon cache at <path> is unreadable; analysing without it.`) and analyses without it.

`cordon:verify --no-cache` neither reads nor writes the cache for one run. See [how much it saves](../faq#how-fast-is-it) and [how to keep it between CI runs](./ci#keeping-the-analysis-cache-between-runs).

## Using the config outside Laravel

The [PHPStan rule](./phpstan) and `vendor/bin/cordon` read `config/cordon.php` without booting Laravel. Keep it a plain array; `env()` is fine, but `.env` is not loaded, so it only sees real environment variables.
