# Evidence for "Comparison in practice"

Raw material behind the [Comparison in practice](https://chrisabner.github.io/Cordon-Modulith/comparison-in-practice) page. Everything here was run on 2026-11-04. The versions are in [`outputs/versions.txt`](outputs/versions.txt): PHP 8.4.27, Laravel 13.40.0, Cordon Modulith 0.1.1, Pest 4.7.8 with pest-plugin-arch 4.0.2 (engine `ta-tikoma/phpunit-architecture-test` 0.8.7), and Deptrac 4.7.2.

## Setup

1. A new Laravel 13.40 app.
2. `app/Modules` is a copy of [`tests/Fixtures/namespace-app/app/Modules`](../../tests/Fixtures/namespace-app/app/Modules), with modules Billing, Catalog and Shared. [`fixture-extra/`](fixture-extra/) adds three files to `Billing/Services`:
   - `DocblockOnly.php`: the internal `Product` class appears only in a `@param` docblock.
   - `StringName.php`: `Product` appears only as a string, `app('App\Modules\Catalog\Models\Product')`.
   - `Unloadable.php`: uses `Product` and extends `Vendor\Missing\BaseService`, which does not exist, so the class cannot be autoloaded.
3. `config/cordon.php` sets `'resolver' => 'namespace'` and marks `Shared` as open.
4. Each tool gets the closest version of Cordon's rules it supports:
   - Pest: [`BoundariesTest.php`](BoundariesTest.php), in `tests/Arch`.
   - Deptrac: [`deptrac.yaml`](deptrac.yaml). Each module is split into a public and an internal layer, and the `attribute` collector handles `#[Internal]` and `#[PublicApi]`.
5. [`ProbeTest.php`](ProbeTest.php) has one Pest expectation per class, because Pest stops at the first failure of each expectation.

## Commands

```bash
php artisan cordon:verify
vendor/bin/pest tests/Arch/BoundariesTest.php
vendor/bin/pest tests/Arch/ProbeTest.php
vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-cache --report-uncovered
```

The outputs are in [`outputs/`](outputs/), unedited, except that Deptrac's absolute `/app/` prefix was removed from paths.

## Why Pest passes `Unloadable`

In `ta-tikoma/phpunit-architecture-test` 0.8.7, `Elements/ObjectDescriptionBase::make()` parses the file with nikic/php-parser. It then runs `new ReflectionClass($name)` and, on `Error|ReflectionException`, returns `null`, so the file is dropped from the analysis without a warning. In this setup, `new ReflectionClass(Unloadable::class)` throws `Error: Class "Vendor\Missing\BaseService" not found`.

`Asserts/Dependencies/ObjectDependenciesDescription` also keeps only the referenced names that pass `class_exists()`, `interface_exists()` and similar checks.

`App\Modules\Billing\Tests\CheckoutServiceFixture` is dropped for the same reason. `class_exists()` returns `false` for it, because its folder is `tests/` while PSR-4 expects `Tests/`.
