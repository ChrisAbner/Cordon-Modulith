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

    'packages' => [
        // 'shared' => ['laravel/framework', 'illuminate/*'],   // usable by every module that lists packages
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

    'docblocks' => false,   // true: class names in docblock types (@param, @var, generics...) count too
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
| `interfaces` | Named subsets of the public API that other modules can depend on: `'Pricing' => ['Contracts\\PriceList', 'Data\\Price*']`. See [named interfaces](#named-interfaces). |
| `nested` | Modules inside this one: a list of folders directly under the module (`['Invoices', 'Payments']`), or `true` for every folder that is not a public namespace. See [nested modules](#nested-modules). |
| `packages` | The Composer packages this module may use besides the shared ones: names (`'stripe/stripe-php'`) or prefixes ending in `*` (`'symfony/*'`). Any other package it uses is an `external_dependency`. Omit it to leave the module unrestricted; `[]` allows only the shared ones. See [packages](#packages). |
| `tests` | The folders with the tests of the module, relative to the project root, for `cordon:affected --tests`: `['tests/Unit/Pricing']`. Without it, `tests/<Module>` and the `tests` folder inside the module. `[]` means none. |
| `metadata` | Free-form information about the module: `cordon:docs` shows its `description` and `owner`. |

In `depends_on`, `Catalog::Pricing` limits a module to a [named interface](#named-interfaces) of `Catalog`.

## Named interfaces

A module can name subsets of its public API, as in Spring Modulith, and a module that depends on it can ask for one of them instead of the whole thing:

```php
'modules' => [
    'Catalog' => [
        'interfaces' => [
            'Pricing' => ['Contracts\\PriceList', 'Data\\Price*'],
            'Inventory' => ['Contracts\\Stock'],
        ],
    ],
    'Billing' => ['depends_on' => ['Catalog::Pricing']],
    'Orders' => ['depends_on' => ['Catalog::Pricing', 'Catalog::Inventory']],
    'Reports' => ['depends_on' => ['Catalog']],   // Catalog::* is the same: the whole public API
],
```

Each entry is a class name relative to the module namespace. A trailing `*` makes it a prefix: `Data\*` is every class under `Data`, and `Data\Price*` every class whose name starts so. Names are compared without regard to case, as PHP does. A class can be in several interfaces, or in none.

- `Catalog` and `Catalog::*` mean what they always did: any public class of `Catalog`. A project that does not use `interfaces` behaves as before.
- With `Catalog::Pricing`, `Billing` may use the public classes of `Pricing` only. Using another public class of `Catalog` is an [`undeclared_dependency`](./rules#undeclared-dependency): `Module [Billing] uses App\Modules\Catalog\Contracts\Stock, which is not in the interfaces it declares on [Catalog] (Pricing).` The same goes for a listener that handles an event outside the interface, unless `listeners.depends_on` is `false`. With several entries, the union of their interfaces applies.
- An interface does not make a class public. Its classes have to be public already (a public namespace, `#[PublicApi]`, the module's `public` list or `open`), and `cordon:verify` fails with exit code `2` when an interface names a class that is not: `Module [Catalog] interface [Pricing] lists Models\Price, which is not public.` A pattern with `*` may reach internal classes, which stay internal. A pattern that matches no class (or, with `*`, no public class) is a warning. A class that is internal is reported by `internal_access`, as before, and not a second time here.
- `depends_on` accepts only the interfaces a module defines: `Catalog::Pricng` is a configuration error with a suggestion, and so is an interface of a module that defines none.
- [`unused_dependency`](./rules#unused-dependency) looks at each entry: `Catalog::Pricing` is unused when no class of `Pricing` is used.
- `cordon:explain <class>` lists the interfaces the class belongs to, `cordon:why` says when a dependency is limited to interfaces, and `cordon:graph` labels the arrow (`3 Catalog::Pricing`).
- With [nested modules](#nested-modules), `Billing.Payments::Gateway` is allowed from inside `Billing`, and `Billing::Api` can list classes of its children (they must be public outside `Billing`). A nested module may use any class of its ancestors, so interfaces never restrict it from using its parent.

The rule works in the PHPStan rule too, with the same message. The checks that need the classes (public members, patterns that match nothing) run in `cordon:verify` and the Pest expectation.

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

`tables` is the one built-in rule that starts `off`: it is a [heuristic](./rules#tables) about the tables modules share, and `warn` is the severity it is meant for.

Custom rules are classes that implement `Cordon\Contracts\Rule`, listed as values (`MyRule::class`) or as keys with a severity (`MyRule::class => 'warn'`). See [custom rules](./custom-rules).

## layers

Layers from the top down, each a list of module names; see [the layers rule](./rules#layers). A module may be in one layer only, a layer may not be empty, and every name must be a detected module. `[]` (the default) turns layers off. A nested module (`Billing.Payments`) left out of `layers` is in the layer of its nearest ancestor that is named, so listing `Billing` is enough; list the child to give it a layer of its own.

## packages

Which Composer packages each module may use, checked by [`external_dependency`](./rules#external-dependency). Nothing is checked until a module lists `packages`.

| Key | Meaning |
|---|---|
| `shared` | Packages every module may use, such as `['laravel/framework', 'illuminate/*']`. Names or prefixes ending in `*`, compared without regard to case. |

The per-module `packages` list goes in `modules` (see above). A nested module without its own list takes the one of its parent. The code outside the modules (`host`) takes no `packages`: it is not checked. Cordon reads `vendor/composer/installed.json` (or the `config.vendor-dir` of `composer.json`) as plain JSON, never your autoloader: when a module lists `packages` and the file is missing, `cordon:verify` exits with `2`, and a listed package that is not installed is a warning.

## host

The code outside the modules, which [`host_access`](./rules#host-access) checks.

| Key | Default | Meaning |
|---|---|---|
| `paths` | `['app', 'routes', 'database']` | Directories relative to the project root. Files of a module inside them (such as `app/Modules`) still belong to their module. `[]` leaves that code unchecked. |

| `tests` | none | The folders with the tests of the code outside the modules, for `cordon:affected --tests`. A change in `host` affects only `host`, and by default it has no test folder. |

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
Unknown key [depend_on] for module [Billing]. Did you mean [depends_on]? Valid keys: depends_on, public, open, strict, nested, interfaces, packages, tests, metadata.
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

The cache never changes a result: the report is the same cold, warm or with `cache` set to `null`. It stores only what was read from each file, not the violations, so changing `depends_on`, the severity of a rule or the baseline needs no new cache. The settings that do are the ones the extractor reads: turning [`docblocks`](#docblocks) on or off, turning the [`tables`](./rules#tables) rule on or off (the extractor reads the tables of each file only while it is on), and binding another extractor class. It starts from scratch by itself when any of them changes, and when Cordon Modulith, nikic/php-parser, phpstan/phpdoc-parser, illuminate/support (its inflector names the tables of a model; under `laravel/framework` it takes that package's version) or PHP change version. Deleting the file is always safe, and so is a file that cannot be read or written: `cordon:verify` warns (`Cordon cache at <path> is unreadable; analysing without it.`) and analyses without it.

`cordon:verify --no-cache` neither reads nor writes the cache for one run. See [how much it saves](../faq#how-fast-is-it) and [how to keep it between CI runs](./ci#keeping-the-analysis-cache-between-runs).

## docblocks

`false` by default: only class names written in code are dependencies. With `'docblocks' => true`, the class names in docblock types count too, as another kind of reference (`docblock`) that goes through the same rules as any other: `internal_access`, `undeclared_dependency`, `unused_dependency`, `cycles`, `layers`, named interfaces and the baseline. Turn it on when your types live in docblocks, for example `@var Collection<int, Invoice>` or `@param list<Order> $orders`.

```php
'docblocks' => true,
```

These tags are read, with the generics, unions, intersections, `array<K, V>`, `list<T>`, `class-string<T>`, callable signatures and array shapes inside them: `@param`, `@return`, `@var`, `@property` (also `-read` and `-write`), `@method`, `@throws`, `@extends`, `@implements`, `@use`, `@mixin`, the bound of `@template T of Foo`, and `@phpstan-type` / `@phpstan-import-type` (and their `@psalm-` forms). Names are resolved the way PHP resolves them, with the namespace and the `use` imports of the file; a name written with `namespace\` is relative to the namespace.

Cordon Modulith reports nothing when a name could be something else, so turning this on does not add false positives:

- Template names (`@template T`) and type aliases (`@phpstan-type`) are not classes inside their scope, and neither are PHP and PHPDoc types such as `int`, `array-key`, `positive-int`, `non-empty-string`, `callable`, `self`, `static`, `$this`, or `class-string` without an argument.
- The keys of an array shape (`array{id: int}`) are names, not classes.
- A tag or type that does not parse is skipped, and so is the rest of that tag. Other tags of the same docblock still count. A broken docblock never fails an analysis.
- Only docblocks (`/** */`) are read, not `//` or `/* */` comments, and a name inside free text (`@deprecated use Foo`) or in a tag that is not listed (`@see`, `@param-out`, `@phpstan-assert`) is not a reference.

A class named in a type and in the code is two references (one of kind `use`, one of kind `docblock`). The docblock one is reported at the line of the first use in code, so turning `docblocks` on does not move an existing violation and a `@cordon-ignore` on that line keeps working. A listener's event named in a docblock of that listener counts as the listener's reference, so `listeners.cycles` and `listeners.depends_on` apply to it too. The setting is part of the [analysis cache](#cache) fingerprint, so changing it never reuses a cache written with the other value. The PHPStan rule reads the same key from `config/cordon.php`; to set it in `phpstan.neon` instead, use the `cordon.docblocks` parameter, which takes precedence (see [PHPStan](./phpstan)).

## Using the config outside Laravel

The [PHPStan rule](./phpstan) and `vendor/bin/cordon` read `config/cordon.php` without booting Laravel. Keep it a plain array; `env()` is fine, but `.env` is not loaded, so it only sees real environment variables.
