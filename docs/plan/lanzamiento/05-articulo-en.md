# How to stop your Billing module from using Catalog's models

*Draft for dev.to. Code from `tests/Fixtures/namespace-app` in the Cordon Modulith repository.*

You split your Laravel application into modules. `app/Modules/Billing`, `app/Modules/Catalog`, `app/Modules/Shared`. Each has its own models, services and events. Then this lands in a pull request:

```php
namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Contracts\ProductCatalog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Shared\Money;

final class CheckoutService
{
    public function __construct(private ProductCatalog $catalog) {}

    public function total(Product $product): Money
    {
        return new Money($product->price);
    }
}
```

It works. It passes review. And Billing now depends on Catalog's database table through its Eloquent model.

## Why it matters

A module boundary is a promise: "you can change anything inside, as long as the public API stays the same". Once other modules use your models, you can't rename a column, split a table or change how prices are stored without touching them. The folders still say "modules", but the code no longer does.

## Make the public API explicit

A simple convention covers most cases. Other modules may use:

- `Contracts`: interfaces, bound to internal implementations in the module's service provider;
- `Data`: immutable objects that cross the boundary instead of models;
- `Events`: what happened in the module, for others to react to;
- `Enums` and `Exceptions`.

Everything else (models, services, jobs, actions) is internal. Exceptions to the convention can be marked with an attribute:

```php
namespace App\Modules\Catalog\Support;

use Cordon\Attributes\PublicApi;

#[PublicApi]
final class PriceFormatter
{
    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
```

## Check it on every pull request

Conventions only work if something checks them. [Cordon Modulith](https://github.com/ChrisAbner/Cordon-Modulith) is a dev package that parses your modules statically (nothing is loaded or executed) and reports boundary violations:

```bash
composer require --dev chrisabner/cordon-modulith
php artisan cordon:verify
```

```text
Cordon analysed 9 files in 3 modules (7 cross-module references).

x [internal_access] app/Modules/Billing/Services/CheckoutService.php:13
  Module [Billing] uses App\Modules\Catalog\Models\Product, which is internal to module [Catalog]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).

x [internal_access] app/Modules/Billing/Services/InvoiceRenderer.php:10
  Module [Billing] uses App\Modules\Catalog\Contracts\LegacyCatalog, which is internal to module [Catalog]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).

x [cycles]
  Modules [Billing, Catalog] form a dependency cycle: Billing -> Catalog -> Billing. Break it by inverting one dependency, for example with an event or a contract.

3 boundary violations.
```

`LegacyCatalog` lives in `Contracts`, but Catalog marked it `#[Internal]` because it is on its way out: the attribute always wins.

The `Money` value object is fine because `Shared` is configured as an open module (a shared kernel):

```php
// config/cordon.php
'modules' => [
    'Shared' => ['open' => true],
],
```

## Fix it

Billing needs a price, not a model. Catalog already publishes a contract; add a data object and use it:

```php
namespace App\Modules\Billing\Services;

use App\Modules\Catalog\Contracts\ProductCatalog;
use App\Modules\Shared\Money;

final class CheckoutService
{
    public function __construct(private ProductCatalog $catalog) {}

    public function total(int $productId): Money
    {
        return new Money($this->catalog->find($productId)->priceInCents);
    }
}
```

The cycle is a different kind of problem. Catalog listens to Billing's `InvoicePaid` event to update stock, while Billing uses Catalog's contract to price products. Listening to an event is a dependency too, so the two modules depend on each other. One edge has to go: for example, Billing receives prices from the caller instead of asking Catalog, or Catalog exposes a `ReservesStock` contract that the checkout calls. The tool tells you the cycle exists and its shortest path; which edge to invert is a design decision.

## Adopting it in a real project

Nobody fixes 300 violations in one pull request. Record them in a baseline, commit it, and only new violations will fail:

```bash
php artisan cordon:verify --generate-baseline
```

Then pick one module per sprint: `php artisan cordon:verify --no-baseline --module=Billing`.

## Where else it runs

- Pest: `expect('Billing')->toRespectBoundaries();`
- PHPStan: the `internal_access` rule shows up in your editor.
- Docs: `php artisan cordon:docs` generates Mermaid diagrams of the real dependencies and an event inventory.

## What it is not

It doesn't replace Deptrac (great for layers) or Pest `arch()` (great for conventions inside a module); it focuses on the edges between modules. Static analysis also has limits: class names in strings and docblock-only types are not detected, by design, to avoid false positives.

If you run a modular monolith in Laravel, I'd like to know how you keep boundaries today and where this approach would not fit.
