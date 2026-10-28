# LaravelEcomm

[LaravelEcomm](https://github.com/KalimeroMK/LaravelEcomm) is an open source e-commerce application built with [nwidart/laravel-modules](https://github.com/nWidart/laravel-modules). Its 29 modules under `Modules/` cover the catalogue (`Product`, `Category`, `Brand`, `Attribute`), sales (`Cart`, `Order`, `Billing`, `Coupon`, `Shipping`), content (`Post`, `Page`, `Banner`) and a `Front` module for the storefront.

**Analysed:** commit [`02d48b8`](https://github.com/KalimeroMK/LaravelEcomm/tree/02d48b8170a91a86b01d486b776fc679057d2112), October 2026, Laravel 13.39, nwidart/laravel-modules 13.0, PHP 8.4. Cordon read the modules from their `module.json` files; no resolver configuration was needed.

## Results

| | Default config | Public API declared |
|---|---|---|
| Modules | 29 | 29 |
| Files analysed | 1,098 | 1,098 |
| Cross-module references | 1,142 | 1,142 |
| `internal_access` | 777 | 395 |
| `cycles` | 1 group of 25 modules | 1 group of 25 modules |
| Time / memory | 2.6 s / 94 MB | 2.7 s / 92 MB |

## A shared kernel called Core

Almost half of the first report (379 of 777) pointed at one module. `Core` holds the base classes every module builds on:

| Class | References |
|---|---|
| `Core\Http\Requests\CoreRequest` | 51 |
| `Core\Http\Controllers\Api\CoreController` | 44 |
| `Core\Http\Requests\Api\CoreRequest` | 44 |
| `Core\Models\Core` | 43 |
| `Core\Http\Controllers\CoreController` | 30 |
| `Core\Repositories\EloquentRepository` | 28 |

That is a shared kernel, and Cordon expects it to be declared:

```php
'public_namespaces' => ['Contracts', 'Events', 'Data', 'Enums', 'Exceptions', 'Interfaces', 'DTOs'],

'modules' => [
    'Core' => ['open' => true],
],
```

Marking `Core` as `open` removed 379 reports and `DTOs` another 3. (`Interfaces` made no difference here: all of them live in `Core`.)

## What remains

The remaining 395 reports are mostly models used across modules:

| Class | References | From modules |
|---|---|---|
| `User\Models\User` | 87 | 25 |
| `Product\Models\Product` | 54 | 15 |
| `Order\Models\Order` | 25 | 11 |
| `Category\Models\Category` | 18 | 9 |

`User` is a special case: 25 of its 87 references are policies, because Laravel passes the authenticated user to every policy method, and 11 are factories. Many teams would declare the user model public, or depend on `Illuminate\Contracts\Auth\Authenticatable` instead.

The storefront is the biggest consumer: `Front` uses `Product` internals 30 times, plus `Category`, `Brand` and `Post`. A storefront that reads every catalogue model directly is hard to change without touching the catalogue, which is where a small read API per module pays off (see [expose a service](../recipes/expose-a-service)).

27 reports come from or point at model factories (`Database/Factories`). Factories only support tests and seeders, so adding `Factories` to `exclude` is a reasonable choice for this app.

## Cycles through Eloquent relations

25 of the 29 modules belong to one group of mutually dependent modules, with 34 direct back-and-forth pairs. Many of them are Eloquent relations declared on both sides:

```php
// Modules/Product/Models/Product.php
return $this->hasMany(Cart::class);

// Modules/Cart/Models/Cart.php
return $this->belongsTo(Product::class);
```

`Product` knows about `Cart`, and `Cart` knows about `Product`. Relations are the most natural way to create a cycle in Laravel without noticing; the [cross-module relations](../recipes/cross-module-relations) recipe shows how to keep only one direction.
