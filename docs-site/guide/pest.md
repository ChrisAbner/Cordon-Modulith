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
Module [Billing] does not respect its boundaries (4 violations):
  - [internal_access] app/Modules/Billing/Services/CheckoutService.php:13 Module [Billing] uses App\Modules\Catalog\Models\Product, which is internal to module [Catalog]. ...
  - [internal_access] app/Modules/Billing/Services/InvoiceRenderer.php:10 Module [Billing] uses App\Modules\Catalog\Contracts\LegacyCatalog, which is internal to module [Catalog]. ...
  - [cycles] Module Billing depends on Catalog, which closes the dependency cycle Billing -> Catalog -> Billing. ...
  - [cycles] Module Catalog depends on Billing, which closes the dependency cycle Catalog -> Billing -> Catalog. ...
```

## One test for every module

```bash
php artisan vendor:publish --tag=cordon-tests
```

writes `tests/Feature/ModuleBoundariesTest.php`:

```php
it('keeps every module within its boundaries', fn () => Cordon::assertBoundaries());
```

Unlike `each->toRespectBoundaries()`, which stops at the first module that fails, `Cordon::assertBoundaries()` lists every module that crosses its boundaries in one failure. It also fails, as `cordon:verify` does, when `config/cordon.php` has errors, when a `@cordon-ignore` comment is invalid, or when no module is found, so an empty project or a misconfigured resolver never passes in silence. Like `cordon:verify` without `--fail-on-parse-error`, a file that does not parse is skipped and does not fail the test. A dependency cycle is listed under each module that takes part in it.

## Setup

A fresh Laravel app with Pest has no binding between your tests and the framework, so the expectation fails with "needs a booted Laravel application". Install Pest with its Laravel plugin and initialise it:

```bash
composer require --dev pestphp/pest pestphp/pest-plugin-laravel -W
./vendor/bin/pest --init
```

`-W` lets Composer change the PHPUnit version the app has locked. Without it, Composer stops with a conflict on `phpunit/phpunit`.

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

They complement each other. Pest's `arch()` expectations are great for rules inside a module ("models extend Model", "no `dd()`"), but they also load each class with reflection, and a class that cannot be loaded is left out without a warning. `toRespectBoundaries()` is about the edges between modules, knows each module's public API and uses static analysis.
