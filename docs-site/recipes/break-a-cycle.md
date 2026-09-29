# Break a cycle with an event

**Problem:** `Orders` uses `Payments` to charge the customer, and `Payments` calls back into `Orders` to mark the order as paid:

```text
x [cycles]
  Modules [Orders, Payments] form a dependency cycle: Orders -> Payments -> Orders. Break it by inverting one dependency, for example with an event or a contract.
```

With a cycle, neither module can change, be tested or be extracted on its own.

## Before

```php
// Modules/Payments/app/Services/Gateway.php
namespace Modules\Payments\Services;

use Modules\Orders\Contracts\OrderRepository;

final class Gateway
{
    public function __construct(private OrderRepository $orders) {}

    public function capture(int $orderId, int $amount): void
    {
        // ... charge the card
        $this->orders->markAsPaid($orderId);   // Payments -> Orders
    }
}
```

## After

`Payments` announces what happened in its own event and knows nothing about orders:

```php
// Modules/Payments/app/Events/PaymentCaptured.php
namespace Modules\Payments\Events;

final readonly class PaymentCaptured
{
    public function __construct(public int $orderId, public int $amount) {}
}
```

```php
// Modules/Payments/app/Services/Gateway.php
namespace Modules\Payments\Services;

use Modules\Payments\Events\PaymentCaptured;

final class Gateway
{
    public function capture(int $orderId, int $amount): void
    {
        // ... charge the card
        event(new PaymentCaptured($orderId, $amount));
    }
}
```

`Orders` listens:

```php
// Modules/Orders/app/Listeners/MarkOrderAsPaid.php
namespace Modules\Orders\Listeners;

use Modules\Orders\Contracts\OrderRepository;
use Modules\Payments\Events\PaymentCaptured;

final class MarkOrderAsPaid
{
    public function __construct(private OrderRepository $orders) {}

    public function handle(PaymentCaptured $event): void
    {
        $this->orders->markAsPaid($event->orderId);
    }
}
```

Only `Orders -> Payments` remains: `Orders` uses the gateway contract and listens to the event.

::: tip Listening is a dependency too
Referencing `PaymentCaptured` in `Orders` is a dependency on `Payments`. The cycle is gone because `Payments` no longer references `Orders`, not because events are invisible. Check with `php artisan cordon:verify`.
:::

## Alternative: dependency inversion

When the call must stay synchronous, let the lower-level module own the interface. `Payments` defines `Contracts\PaymentListener`, `Orders` implements it and binds it in its service provider. The arrow now points from `Orders` to `Payments` only.
