# Cordon for Laravel

**Cordon off your modules.** Verifiable boundaries between the modules of a Laravel application, checked in CI.

> **Status: 0.1 (alpha).** The public API may change before 1.0.
> **Working name:** "Cordon" is pending trademark and availability checks, and `your-vendor` is a placeholder for the final Composer vendor.

Modular monoliths in Laravel usually rely on nwidart/laravel-modules, InterNACHI/modular or a hand-made `app/Modules` folder. All of them organise code, but none of them stops the `Billing` module from reaching into `Catalog`'s models. Six months later the "modules" are just folders.

Cordon reads your code statically, builds the dependency graph between modules and fails the build when a module:

- uses **internal classes** of another module instead of its public API,
- depends on a module it **did not declare**, or
- takes part in a **dependency cycle**.

It is inspired by [Spring Modulith](https://spring.io/projects/spring-modulith) and Shopify's Packwerk, adapted to Laravel conventions.

```text
$ php artisan cordon:verify

Cordon analysed 212 files in 6 modules (148 cross-module references).

x [internal_access] Modules/Billing/app/Services/CheckoutService.php:13
  Module [Billing] uses Modules\Catalog\Models\Product, which is internal to module [Catalog]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).

x [cycles]
  Modules [Billing, Catalog] form a dependency cycle: Billing -> Catalog -> Billing. Break it by inverting one dependency, for example with an event or a contract.

2 boundary violations.
```

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require --dev your-vendor/cordon
```

Cordon detects your module layout automatically. Check what it found:

```bash
php artisan cordon:modules
```

Then verify the boundaries:

```bash
php artisan cordon:verify
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=cordon-config
```

## Adopting Cordon in an existing project

Existing codebases usually start with many violations. Record them in a baseline so the build only fails on **new** ones, then burn the baseline down over time:

```bash
php artisan cordon:verify --generate-baseline   # writes cordon-baseline.json
php artisan cordon:verify                       # passes; new violations fail
php artisan cordon:verify --no-baseline         # shows everything again
```

Commit `cordon-baseline.json`. Entries are keyed by rule, file and target class (not line numbers), so unrelated edits don't invalidate them.

## The public API of a module

Other modules may only use classes that belong to a module's public API. A class is public when, in order of precedence:

1. it is marked `#[Cordon\Attributes\Internal]` → **internal**, always;
2. it is marked `#[Cordon\Attributes\PublicApi]` → public;
3. its module is configured as `open` → public (handy for a shared kernel);
4. it lives under one of the public namespaces, relative to the module: `Contracts`, `Events`, `Data`, `Enums`, `Exceptions` by default, plus the module's own `public` list → public;
5. otherwise → **internal**.

```php
namespace Modules\Catalog\Support;

use Cordon\Attributes\PublicApi;

#[PublicApi]
final class PriceFormatter
{
    // ...
}
```

The recommended way for modules to talk to each other is through contracts (interfaces bound in the module's service provider), events and data objects.

## Configuration

```php
// config/cordon.php
return [
    'resolver' => 'auto', // auto, namespace, nwidart, internachi

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
    ],

    'exclude' => ['vendor', 'node_modules', 'tests', 'Tests'],

    'baseline' => 'cordon-baseline.json',
];
```

`depends_on` is opt-in per module: modules without it are not checked by `undeclared_dependency`.

### Module layouts

| Resolver | Detected when | Module | Namespace |
|---|---|---|---|
| `nwidart` | `Modules/*/module.json` exists | `Modules/Blog` → `Blog` | `Modules\Blog` (from `config/modules.php`) |
| `internachi` | `app-modules/` exists | `app-modules/billing` → `billing` | from the module's `composer.json` PSR-4 entry |
| `namespace` | fallback | `app/Modules/Billing` → `Billing` | `App\Modules\Billing` |

Paths and namespaces can be overridden under `resolvers` in the config file.

## Rules

| Rule | Reports |
|---|---|
| `internal_access` | A module using a class that is not part of another module's public API. One report per file and target class. |
| `undeclared_dependency` | A module with `depends_on` using a module that is not listed. One report per file and target module. |
| `cycles` | Modules that depend on each other in a cycle, with the shortest cycle path. |

## Continuous integration

Use the `github` format to get annotations on pull requests:

```yaml
# .github/workflows/cordon.yml
name: cordon
on: [pull_request]
jobs:
  cordon:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer install --no-interaction --prefer-dist
      - run: php artisan cordon:verify --format=github
```

`--format=json` prints a machine readable report. The command exits with `1` when there are violations, `0` otherwise and `2` on invalid options.

## AI coding agents

Cordon ships a [Laravel Boost](https://laravel.com/docs/boost) guideline (`resources/boost/guidelines/core.blade.php`) so agents know they must go through a module's public API and run `cordon:verify`. Boost picks it up when you run `php artisan boost:install`.

## How it works

Cordon parses every PHP file inside your modules with [nikic/php-parser](https://github.com/nikic/PHP-Parser). Your code is never loaded or executed, so it works on code that doesn't boot and on classes that don't exist yet. It records every class reference (`new`, static calls, type declarations, `extends`/`implements`, traits, attributes, `instanceof`, `catch`...) and maps each one to its module by namespace. Import statements alone, function calls and constants don't count. See [docs/architecture.md](docs/architecture.md).

**Known limitations in 0.1:** docblock-only types (`@var`, generics), string class names (`'App\\Foo'`, `app('...')`) and dynamic references are not detected. Code outside modules (for example `app/Http`) is not analysed.

## Roadmap

- Pest expectation and PHPStan rule
- Living documentation: Mermaid/C4 diagrams per module, event inventory
- Filament plugin with the module graph
- Extension API for custom rules

See [docs/plan/roadmap.md](docs/plan/roadmap.md) (Spanish).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). AI agents: read [AGENTS.md](AGENTS.md) first.

## License

MIT. See [LICENSE.md](LICENSE.md).

Cordon is a community project and is not affiliated with or endorsed by Laravel.
