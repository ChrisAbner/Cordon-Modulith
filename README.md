# Cordon Modulith

[![Tests](https://github.com/ChrisAbner/Cordon-Modulith/actions/workflows/tests.yml/badge.svg)](https://github.com/ChrisAbner/Cordon-Modulith/actions/workflows/tests.yml)
[![Latest version](https://img.shields.io/packagist/v/chrisabner/cordon-modulith.svg)](https://packagist.org/packages/chrisabner/cordon-modulith)
[![Downloads](https://img.shields.io/packagist/dt/chrisabner/cordon-modulith.svg)](https://packagist.org/packages/chrisabner/cordon-modulith)
[![PHP](https://img.shields.io/packagist/dependency-v/chrisabner/cordon-modulith/php.svg)](composer.json)
[![License](https://img.shields.io/github/license/ChrisAbner/Cordon-Modulith.svg)](LICENSE.md)

**Cordon off your modules.** Verifiable boundaries between the modules of a Laravel application, checked in CI.

> **Status: 0.1 (alpha).** The public API may change before 1.0. [Documentation](https://chrisabner.github.io/Cordon-Modulith/)

Modular monoliths in Laravel usually rely on nwidart/laravel-modules, InterNACHI/modular or a hand-made `app/Modules` folder. All of them organise code, but none of them stops the `Billing` module from reaching into `Catalog`'s models. Six months later the "modules" are just folders.

Cordon Modulith reads your code statically, builds the dependency graph between modules and fails the build when a module:

- uses **internal classes** of another module instead of its public API,
- depends on a module it **did not declare**, or
- takes part in a **dependency cycle**.

It also keeps AI coding agents honest: Cordon Modulith ships Laravel Boost resources that teach agents to go through a module's public API and to fix the violations `cordon:verify` reports.

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
composer require --dev chrisabner/cordon-modulith
```

Cordon Modulith detects your module layout automatically. Check what it found:

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

## Adopting Cordon Modulith in an existing project

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

`public_namespaces` match at **any depth** relative to the module: an entry counts when it appears as a whole segment (or a contiguous run of segments, such as `Http\Resources`) in the class's namespace. `Modules\Billing\Contracts\Gateway`, `Modules\Billing\Invoices\Enums\Status` and `Modules\Billing\Invoices\Events\Sub\InvoicePaid` are public; `Modules\Billing\Invoices\MyEnums\Status` and `Modules\Billing\EnumsHelper\Foo` are not (whole segments only). The module's own `public` list is different: its entries stay anchored at the module root, e.g. `Services\BillingService`. If a nested `Enums` or `Events` class must stay internal, mark it with `#[Internal]`.

`DTOs` is not in the defaults (only `Data`). If you use it, add it to `public_namespaces`, e.g. `['Contracts', 'Events', 'Data', 'DTOs', 'Enums', 'Exceptions']` (setting the option replaces the default list).

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

You can add your own rules: list classes implementing `Cordon\Contracts\Rule` under `rules` in the config. See [docs/custom-rules.md](docs/custom-rules.md).

## Continuous integration

Use the reusable GitHub Action to get annotations on pull requests:

```yaml
# .github/workflows/cordon.yml
name: cordon
on: [pull_request]
jobs:
  cordon:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: ChrisAbner/Cordon-Modulith@v0.1.0
```

Or add `php artisan cordon:verify --format=github` to an existing job. `--format=json` prints a machine readable report. The command exits with `1` when there are violations, `0` otherwise and `2` on invalid options. See [docs/ci.md](docs/ci.md) for the action inputs and a job per module.

### One module at a time

```bash
php artisan cordon:verify --module=Billing
```

`--module` (repeatable) reports only the violations a module causes and the dependency cycles it takes part in.

## Pest

Check boundaries from your test suite with the `toRespectBoundaries()` expectation. It needs a booted application, so use it in tests that extend your `Tests\TestCase`. A fresh Laravel app with Pest doesn't bind it yet: run `composer require --dev pestphp/pest pestphp/pest-plugin-laravel` and `./vendor/bin/pest --init`, or make sure `tests/Pest.php` contains `pest()->extend(Tests\TestCase::class)->in('Feature');`.

```php
use Cordon\Testing\Cordon;

it('keeps Billing inside its boundaries', function () {
    expect('Billing')->toRespectBoundaries();
});

it('keeps every module inside its boundaries', function () {
    expect(Cordon::modules())->each->toRespectBoundaries();
});
```

The analysis runs once per test process and honours the baseline, like `cordon:verify`. The `each` form stops at the first failing module (Pest behaviour).

## PHPStan

Cordon Modulith ships a PHPStan rule that reports `internal_access` in your editor. With [phpstan/extension-installer](https://github.com/phpstan/extension-installer) it is enabled automatically. A fresh Laravel app doesn't ship it, so either `composer require --dev phpstan/extension-installer` or include the extension by hand in a minimal `phpstan.neon`:

```neon
# phpstan.neon
includes:
    - vendor/chrisabner/cordon-modulith/extension.neon

parameters:
    level: 5
    paths:
        - app
        - Modules
```

The rule reads `config/cordon.php` without booting Laravel, so keep that file a plain array. Set `parameters.cordon.basePath` if PHPStan does not run from the project root. Dependency cycles and `depends_on` are only checked by `cordon:verify`.

## Living documentation

Generate Markdown documentation of the real architecture, straight from the code:

```bash
php artisan cordon:docs                     # writes docs/architecture/
php artisan cordon:docs --output=docs/modules
```

- `README.md`: every module and a Mermaid diagram of their dependencies (internal access in red);
- `modules/<Module>.md`: a canvas per module with its public API, what it uses and who uses it, its events and its violations;
- `events.md`: every module event with who publishes and who listens to it.

The output folder is created if it doesn't exist. GitHub renders the Mermaid diagrams, and the output is deterministic, so commit it and review architecture changes in pull requests.

## AI coding agents

Cordon Modulith ships [Laravel Boost](https://laravel.com/docs/boost) resources that Boost picks up when you run `php artisan boost:install`:

- a guideline (`resources/boost/guidelines/core.blade.php`) so agents go through a module's public API and run `cordon:verify`;
- the `cordon-fix-violations` skill (`resources/boost/skills/`), which teaches agents to read `cordon:verify --format=json` and fix each kind of violation without touching the baseline.

## How it works

Cordon Modulith parses every PHP file inside your modules with [nikic/php-parser](https://github.com/nikic/PHP-Parser). Your code is never loaded or executed, so it works on code that doesn't boot and on classes that don't exist yet. It records every class reference (`new`, static calls, type declarations, `extends`/`implements`, traits, attributes, `instanceof`, `catch`...) and maps each one to its module by namespace. Import statements alone, function calls and constants don't count. Speed is around 1 ms per file on a typical CI runner (about a second for 1,000 files, Linux, no Xdebug). Run with Xdebug off (`XDEBUG_MODE=off php artisan cordon:verify`): Xdebug makes it 3-4x slower, and the first run on Windows can be slower because of cold file reads and antivirus scanning. See [docs/architecture.md](docs/architecture.md).

**Known limitations in 0.1:** docblock-only types (`@var`, generics), string class names (`'App\\Foo'`, `app('...')`) and dynamic references are not detected. Code outside modules (for example `app/Http`) is not analysed. When Cordon runs standalone (the PHPStan rule), project config files are evaluated without booting Laravel. If one can't be evaluated, the rule reports a `cordon.configuration` error and falls back to defaults; see [PHPStan](https://chrisabner.github.io/Cordon-Modulith/guide/phpstan).

## Roadmap

What's next:

- Filament plugin with the module graph
- 1.0 with a frozen public API and a SemVer policy
- File-hash cache for large projects
- C4 diagrams

Ideas and bugs are welcome in [GitHub issues](https://github.com/ChrisAbner/Cordon-Modulith/issues).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). AI agents: read [AGENTS.md](AGENTS.md) first.

## License

MIT. See [LICENSE.md](LICENSE.md).

Cordon Modulith is a community project and is not affiliated with or endorsed by Laravel, or by the Spring team, VMware or Broadcom (Spring Modulith inspired the name and the approach).
