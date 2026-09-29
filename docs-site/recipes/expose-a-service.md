# Expose a service to other modules

**Problem:** `Billing` needs product prices and uses `Catalog`'s Eloquent model:

```php
namespace Modules\Billing\Services;

use Modules\Catalog\Models\Product;

final class CheckoutService
{
    public function total(int $productId, int $quantity): int
    {
        return Product::findOrFail($productId)->price_in_cents * $quantity;
    }
}
```

```text
x [internal_access] Modules/Billing/app/Services/CheckoutService.php:11
  Module [Billing] uses Modules\Catalog\Models\Product, which is internal to module [Catalog]. ...
```

Now `Billing` depends on `Catalog`'s table structure, its model events and every attribute of `Product`.

## 1. Define what Catalog offers

A contract and a data object in public namespaces:

```php
namespace Modules\Catalog\Contracts;

use Modules\Catalog\Data\ProductData;

interface ProductCatalog
{
    public function find(int $id): ProductData;
}
```

```php
namespace Modules\Catalog\Data;

final readonly class ProductData
{
    public function __construct(
        public int $id,
        public string $name,
        public int $priceInCents,
    ) {}
}
```

## 2. Implement it inside Catalog

```php
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
```

```php
// Modules/Catalog/app/Providers/CatalogServiceProvider.php
public function register(): void
{
    $this->app->bind(ProductCatalog::class, EloquentProductCatalog::class);
}
```

## 3. Depend on the contract

```php
namespace Modules\Billing\Services;

use Modules\Catalog\Contracts\ProductCatalog;

final class CheckoutService
{
    public function __construct(private ProductCatalog $catalog) {}

    public function total(int $productId, int $quantity): int
    {
        return $this->catalog->find($productId)->priceInCents * $quantity;
    }
}
```

`Billing` still depends on `Catalog`, but only on two small, stable types that `Catalog` chose to publish. It can be tested with a fake `ProductCatalog`.

## When a contract is overkill

If the class is a stable value object designed to be shared (a `PriceFormatter`, a `Money`), mark it `#[PublicApi]` or move it to an `open` shared kernel module instead.
