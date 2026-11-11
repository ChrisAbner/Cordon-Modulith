---
name: cordon-fix-violations
description: Fix module boundary violations reported by Cordon Modulith (php artisan cordon:verify). Use when cordon:verify fails, when a change touches more than one module, or when asked to make modules respect their boundaries.
---

# Fixing Cordon Modulith violations

Cordon Modulith checks that modules only talk to each other through their public API. This skill explains how to read its report and fix each kind of violation properly.

## 1. Get the report

```bash
php artisan cordon:verify --format=json
```

The report has a `version` (1; its schema is `resources/schemas/report-v1.schema.json` in the package), a `summary`, the `violations` that fail the check, the `warnings` of rules set to `warn` (they never fail, but fix them too when your change touches them) and the `parse_errors`.

Each entry in `violations` and `warnings` has:

| Field | Meaning |
|---|---|
| `rule` | `internal_access`, `undeclared_dependency`, `cycles`, `unused_dependency`, `host_access`, `layers`, `public_api_leak`, or the id of a custom rule |
| `severity` | `error` (in `violations`) or `warn` (in `warnings`) |
| `file`, `line` | Where the offending reference is (null for `cycles` and `unused_dependency`) |
| `source_module` | The module that has the problem (`host` for `host_access`: the code outside the modules) |
| `target_module` | The module it reaches into |
| `target` | The class used (`internal_access`, `host_access`, `layers`, `public_api_leak`), the module (`undeclared_dependency`) or the dependency, such as `Orders -> Payments` (`cycles`, `unused_dependency`) |

Run `php artisan cordon:modules` to see each module's namespace, path and declared dependencies.

## 2. Rules you must follow

- **Never add a violation to the baseline** (`--generate-baseline`) to make the build pass. The baseline is only for adopting Cordon Modulith in an existing project, and the maintainers decide when to regenerate it.
- **Never add a module to `depends_on`** in `config/cordon.php` only to silence `undeclared_dependency`. Ask the user if the dependency is intended.
- **Never mark a class `#[PublicApi]` just to silence `internal_access`.** Only do it when the class was designed to be used by other modules (stable, no persistence details). Prefer a contract or a data object.
- Do not move classes into a public namespace (`Contracts`, `Events`, `Data`, `Enums`, `Exceptions`, at any depth) unless they really are contracts, events, data objects, enums or exceptions.
- Re-run `php artisan cordon:verify` after each fix.

## 3. internal_access

The source module uses a class that is internal to the target module (a model, service, job, action...).

**Fix: depend on a contract and a data object that the target module exposes.**

Before (in the `Billing` module):

```php
use Modules\Catalog\Models\Product;

final class CheckoutService
{
    public function total(int $productId): int
    {
        return Product::findOrFail($productId)->price_in_cents;
    }
}
```

After. In the `Catalog` module, add a contract, a data object and an implementation, and bind it in the module's service provider:

```php
// Modules/Catalog/app/Contracts/ProductCatalog.php
namespace Modules\Catalog\Contracts;

use Modules\Catalog\Data\ProductData;

interface ProductCatalog
{
    public function find(int $id): ProductData;
}

// Modules/Catalog/app/Data/ProductData.php
namespace Modules\Catalog\Data;

final readonly class ProductData
{
    public function __construct(public int $id, public string $name, public int $priceInCents) {}
}

// Modules/Catalog/app/Services/EloquentProductCatalog.php (internal)
namespace Modules\Catalog\Services;

use Modules\Catalog\Contracts\ProductCatalog;
use Modules\Catalog\Data\ProductData;
use Modules\Catalog\Models\Product;

final class EloquentProductCatalog implements ProductCatalog
{
    public function find(int $id): ProductData
    {
        $product = Product::findOrFail($id);

        return new ProductData($product->id, $product->name, $product->price_in_cents);
    }
}

// In CatalogServiceProvider::register()
$this->app->bind(ProductCatalog::class, EloquentProductCatalog::class);
```

Then in `Billing`:

```php
use Modules\Catalog\Contracts\ProductCatalog;

final class CheckoutService
{
    public function __construct(private ProductCatalog $catalog) {}

    public function total(int $productId): int
    {
        return $this->catalog->find($productId)->priceInCents;
    }
}
```

Other valid fixes:

- The source module only needs to **react** to something in the target module: listen to a public event instead of calling its services.
- The target class is a stable value object already designed for sharing (for example a `Money` class): mark it `#[\Cordon\Attributes\PublicApi]`, or move it to a shared kernel module configured with `'open' => true`. Confirm with the user first.

Eloquent relations across modules (`belongsTo(OtherModule\Models\X::class)`) are a common source of `internal_access`. Store the foreign key and load the data through the other module's contract instead of a relation.

## 4. undeclared_dependency

The source module declares `depends_on` in `config/cordon.php` and uses a module that is not listed.

1. Decide whether the dependency is intended. If the user confirms it is, add the module to `depends_on`.
2. If it is not intended, remove the dependency: move the logic to the module that owns the data, or invert it so the other module depends on this one (for example, this module publishes an event in its own `Events` namespace and the other module listens to it). Listening to the other module's event still counts as depending on it.
3. Never add the dependency without confirmation.

```php
// config/cordon.php
'modules' => [
    'Notifications' => ['depends_on' => ['Accounts', 'Invoicing']], // only when intended
],
```

## 5. cycles

Two or more modules depend on each other, for example `Orders -> Payments -> Orders`. There is one violation per dependency inside the cycle: its `target` is that dependency (`Orders -> Payments`) and the message shows the shortest cycle it closes.

**Fix: invert one of the dependencies.** Pick the edge that is least essential and replace it:

- **With an event:** instead of `Payments` calling `Orders` to mark an order as paid, `Payments` dispatches its own public event and `Orders` listens to it. Then only `Orders -> Payments` remains.

Before (in `Payments`):

```php
use Modules\Orders\Contracts\OrderRepository;

final class Gateway
{
    public function __construct(private OrderRepository $orders) {}

    public function capture(int $orderId): void
    {
        // ... charge the card
        $this->orders->markAsPaid($orderId);
    }
}
```

After:

```php
// Modules/Payments/app/Events/PaymentCaptured.php
namespace Modules\Payments\Events;

final readonly class PaymentCaptured
{
    public function __construct(public int $orderId) {}
}

// Modules/Payments/app/Services/Gateway.php
use Modules\Payments\Events\PaymentCaptured;

final class Gateway
{
    public function capture(int $orderId): void
    {
        // ... charge the card
        event(new PaymentCaptured($orderId));
    }
}

// Modules/Orders/app/Listeners/MarkOrderAsPaid.php
use Modules\Payments\Events\PaymentCaptured;

final class MarkOrderAsPaid
{
    public function handle(PaymentCaptured $event): void { /* ... */ }
}
```

- **With a contract owned by the caller (dependency inversion):** the lower-level module defines an interface in its own `Contracts` namespace and the higher-level module implements it and binds it in its service provider.

After the change, run `php artisan cordon:verify` again: a cycle is fixed only when no edge remains in one of the directions.

## 6. The other rules

- `host_access`: code outside the modules (a controller, a Filament resource, a route file) uses an internal class of a module. Fix it like `internal_access`: depend on a contract or a data object of that module.
- `layers`: a module depends on a module of a layer above its own, through the class in `target`. Invert the dependency with a contract or an event. Do not reorder `layers` in `config/cordon.php` to make it pass.
- `public_api_leak`: a public class names an internal class of its own module in a public method, property or constant. Return or accept a data object or a contract instead.
- `unused_dependency`: the module lists a dependency in `depends_on` that it never uses. Removing the entry is the fix, and the one case where editing `depends_on` is right.
- Never silence a violation with `// @cordon-ignore` unless the user decided to accept it. The comment needs a reason, and it is a decision, not a fix.

## 7. Finish

- `php artisan cordon:verify` exits with 0.
- The baseline file is unchanged (or smaller, if you fixed violations that were in it: run `php artisan cordon:verify --prune-baseline` to remove the fixed entries; it never adds new violations. Never use `--generate-baseline` for this).
- Explain to the user which contracts, events or data objects you added and why.
