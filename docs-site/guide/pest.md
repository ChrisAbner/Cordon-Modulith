# Pest

Check boundaries from your test suite with the `toRespectBoundaries()` expectation. It is registered automatically when Pest is installed.

```php
use Cordon\Testing\Cordon;

it('keeps Billing inside its boundaries', function () {
    expect('Billing')->toRespectBoundaries();
});

it('keeps every module inside its boundaries', function () {
    expect(Cordon::modules())->each->toRespectBoundaries();
});
```

A failure lists every violation of the module:

```text
Module [Billing] does not respect its boundaries (3 violations):
  - [internal_access] app/Modules/Billing/Services/CheckoutService.php:13 Module [Billing] uses App\Modules\Catalog\Models\Product, which is internal to module [Catalog]. ...
  - [internal_access] app/Modules/Billing/Services/InvoiceRenderer.php:10 Module [Billing] uses App\Modules\Catalog\Contracts\LegacyCatalog, which is internal to module [Catalog]. ...
  - [cycles] Modules [Billing, Catalog] form a dependency cycle: Billing -> Catalog -> Billing. ...
```

## Setup

A fresh Laravel app with Pest has no binding between your tests and the framework, so the expectation fails with "needs a booted Laravel application". Install Pest with its Laravel plugin and initialise it:

```bash
composer require --dev pestphp/pest pestphp/pest-plugin-laravel
./vendor/bin/pest --init
```

`pest --init` creates `tests/Pest.php`. If you already have that file, make sure it extends your `TestCase` for the folder where the test lives:

```php
// tests/Pest.php
pest()->extend(Tests\TestCase::class)->in('Feature');
```

## Details

- The expectation needs a booted application: use it in tests that extend your `Tests\TestCase`. Pest only does that for the folders bound in `tests/Pest.php` (see [Setup](#setup)).
- `expect(Cordon::modules())->each->toRespectBoundaries()` stops at the first module that fails (that is how Pest's `each` works). Fix it and run again, or write one `it()` per module to see every failing module at once.
- A module's violations are the ones it causes plus the dependency cycles it takes part in, like `cordon:verify --module`.
- The baseline is applied, so tests and CI give the same verdict.
- The analysis runs once per test process and is reused while the config and the baseline file don't change. Call `Cordon::flush()` if a test changes files on disk.

## Pest `arch()` and Cordon Modulith

They complement each other. Pest's `arch()` expectations are great for rules inside a module ("models extend Model", "no `dd()`"), but they rely on reflection, so they only see classes that exist and autoload. `toRespectBoundaries()` is about the edges between modules, knows each module's public API and uses static analysis.
