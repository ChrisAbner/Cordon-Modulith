# Bagisto

[Bagisto](https://github.com/bagisto/bagisto) is an open source e-commerce platform on Laravel, with about 28,000 stars on GitHub. Its features live in 42 packages under `packages/Webkul`, each with its own namespace (`Webkul\Sales`, `Webkul\Checkout`, ...), service provider and models. Packages are wired together with [Concord](https://konekt.dev/concord), which also provides model proxies so packages can refer to each other's models.

**Analysed:** commit [`7e4b38a`](https://github.com/bagisto/bagisto/tree/7e4b38a894d4f46ab3b691cf301b0908a60f9c4b), October 2026, Laravel 13.39, PHP 8.4.

## Results

| | Default config | Public API declared |
|---|---|---|
| Modules | 42 | 42 |
| Files analysed | 1,997 | 1,997 |
| Cross-module references | 1,497 | 1,497 |
| `internal_access` | 919 | 158 |
| `cycles` | 1 group of 30 modules | 1 group of 30 modules |
| Time / memory | 5.7 s / 113 MB | 6.1 s / 113 MB |

No parse errors and no false positives: every report points at a real reference (see [how we checked](./#what-we-found)).

## From 919 to 158

Most of the first report came from things Bagisto shares on purpose:

| What we declared | Why | Reports removed |
|---|---|---|
| `Core` and `DataGrid` as `open` | Every package extends their base classes: `Repository`, `TranslatableModel`, `CoreModuleServiceProvider`, `DataGrid`. They are a shared kernel. | 340 |
| `Repositories` as a public namespace | Packages read and write each other's data through repositories; the codebase is built around them as the access layer. | 323 |
| `Facades` as a public namespace | Facades such as `Cart` and `MagicAI` are the entry points packages offer each other. | 46 |
| 17 Concord proxies (`OrderProxy`, `CustomerProxy`, ...) | Proxies exist so that packages can refer to each other's models without depending on them. | 52 |

```php
// config/cordon.php
'resolver' => 'namespace',

'resolvers' => [
    'namespace' => ['path' => 'packages/Webkul', 'namespace' => 'Webkul'],
],

'public_namespaces' => ['Contracts', 'Events', 'Data', 'Enums', 'Exceptions', 'Repositories', 'Facades'],

'modules' => [
    'Core' => ['open' => true],
    'DataGrid' => ['open' => true],
    'Sales' => ['public' => ['Models\\OrderProxy', 'Models\\OrderItemProxy', 'Models\\InvoiceProxy']],
    'Customer' => ['public' => ['Models\\CustomerProxy', 'Models\\CustomerGroupProxy']],
    // ... one entry per package that exposes proxies (10 packages)
],
```

Proxies sit in the same `Models` namespace as the models they hide, so they have to be listed one by one. Apps built on Concord (Bagisto, Krayin, UnoPim) would benefit from a pattern such as `Models\*Proxy`. If that is your case, tell us in an issue.

## What remains

The remaining 158 reports are concentrated in a few places. Four patterns stand out.

### Order statuses are read from the model

`Webkul\Sales\Models\Order` is used directly by 7 other packages, and all 15 references read a status constant:

```php
// packages/Webkul/Paypal/src/Helpers/Ipn.php
$this->order = $this->orderRepository->update(['status' => Order::STATUS_PROCESSING], $this->order->id);

// packages/Webkul/Admin/src/Config/system.php
'value' => Order::STATUS_PENDING,
```

Every package that needs a status now depends on the whole Eloquent model. An `OrderStatus` enum in `Webkul\Sales\Enums` would be public by default and remove all 15 reports.

### Every payment gateway builds orders with a Sales transformer

The six payment gateways (PayPal, Stripe, Razorpay, PayU, PhonePe and PayGlocal), plus `Shop` and `Admin`, create an order from a cart the same way:

```php
// packages/Webkul/Stripe/src/Http/Controllers/StripeController.php
$data = (new OrderResource($cart))->jsonSerialize();
```

`OrderResource` lives in `Webkul\Sales\Transformers`, an internal namespace. "Create an order from this cart" is a real capability of `Sales`, used by 8 packages, and it deserves a published contract.

### The base payment class is an extension point

```php
// packages/Webkul/PhonePe/src/Payment/PhonePe.php
class PhonePe extends Payment
```

Six gateways extend `Webkul\Payment\Payment\Payment`. That is intended, so the right fix is to declare it: `'Payment' => ['public' => ['Payment\\Payment']]` or `#[PublicApi]` on the class. Cordon makes the decision explicit instead of leaving it implied.

### Domain code depends on the storefront

```php
// packages/Webkul/Sales/src/Traits/InvoiceReminder.php
use Webkul\Shop\Mail\Customer\InvoiceOverdueReminder;
```

`Sales` sends a mail class that lives in `Shop`, the storefront package, while `Shop` uses 11 classes from `Sales`. Each depends on the other.

## Cycles in the purchase flow

30 of the 42 packages belong to one group of mutually dependent modules. Even the four packages at the heart of a purchase form two cycles:

![Dependencies between Shop, Sales, Product and Checkout in Bagisto. Shop and Sales depend on each other, and so do Product and Checkout.](/case-studies/bagisto-checkout.png)

`Checkout`'s cart item asks `Product` for its type (`AbstractType`), while every product type validates a `Checkout` cart item:

```php
// packages/Webkul/Checkout/src/Models/CartItem.php
public function getTypeInstance(): AbstractType

// packages/Webkul/Product/src/Type/AbstractType.php
public function validateCartItem(CartItem $item): CartItemValidationResult
```

Neither package can change or be tested without the other. The [break a cycle](../recipes/break-a-cycle) recipe shows the usual way out: one side publishes a contract or an event and the other depends only on that.

## Adopting it

With the configuration above, Bagisto could adopt Cordon today without touching its code:

```bash
php artisan cordon:verify --generate-baseline   # records the 159 current violations
php artisan cordon:verify                       # passes
```

From then on, a new controller in `Shop` that reads `Webkul\Sales\Models\Order` fails the build, while the 159 recorded ones are paid down over time. See [adopting in an existing project](../guide/baseline).
