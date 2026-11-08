# Rules

| Rule | Reports |
|---|---|
| `internal_access` | A module using a class that is not part of another module's [public API](./public-api). One report per file and target class. |
| `undeclared_dependency` | A module with `depends_on` using a module that is not listed. One report per file and target module. |
| `cycles` | Modules that depend on each other in a cycle. One report per dependency inside the cycle, with the shortest cycle it closes. |

All three are enabled by default. Switch them off in the config:

```php
'rules' => [
    'internal_access' => true,
    'undeclared_dependency' => true,
    'cycles' => false,
],
```

## internal_access

```text
x [internal_access] Modules/Orders/app/Models/Order.php:13
  Module [Orders] uses Modules\Catalog\Models\Product, which is internal to module [Catalog]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).
```

Fix it by depending on a contract or data object of the target module. See [expose a service](../recipes/expose-a-service).

## undeclared_dependency

`depends_on` is opt-in per module: modules without it are not checked.

```php
'modules' => [
    'notifications' => ['depends_on' => ['accounts']],
],
```

```text
x [undeclared_dependency] app-modules/notifications/src/Listeners/SendInvoiceEmail.php:12
  Module [notifications] depends on module [invoicing] (via Modules\Invoicing\Events\InvoiceIssued), but [invoicing] is not listed in its depends_on.
```

Add the module to `depends_on` only when the dependency is intended.

Using a module's **public** class without listing that module reports only `undeclared_dependency`, never `internal_access`. With `'Orders' => ['depends_on' => ['Catalog']]`:

| Orders uses | Reported |
|---|---|
| `Modules\Catalog\Contracts\ProductCatalog` (public, `Catalog` listed) | nothing |
| `Modules\Catalog\Contracts\ProductCatalog` (public, `Catalog` not listed) | `undeclared_dependency` only |
| `Modules\Catalog\Models\Product` (internal, `Catalog` listed) | `internal_access` only |
| `Modules\Catalog\Models\Product` (internal, `Catalog` not listed) | both |

## cycles

Cordon reports each dependency that takes part in a cycle, with the shortest cycle it closes. Two modules that depend on each other give two reports:

```text
x [cycles]
  Module Orders depends on Payments, which closes the dependency cycle Orders -> Payments -> Orders. Break it by inverting one dependency, for example with an event or a contract.

x [cycles]
  Module Payments depends on Orders, which closes the dependency cycle Payments -> Orders -> Payments. Break it by inverting one dependency, for example with an event or a contract.
```

Removing one of them breaks the cycle, and both reports go away.

Listening to another module's event is a dependency on that module too. See [break a cycle with an event](../recipes/break-a-cycle).

When the modules reach each other through several cycles, the message names the whole group:

```text
x [cycles]
  Module Orders depends on Catalog, which closes the dependency cycle Orders -> Catalog -> Orders. Modules [Catalog, Orders, Payments] are tangled in several cycles: break them one dependency at a time, for example with an event or a contract.
```

Reporting per dependency keeps the [baseline](./baseline) honest: a module that joins a recorded cycle, or a new dependency inside it, is reported as new, and removing one dependency does not bring back the others.

Every cross-module reference is an edge, including references to public classes such as events, with one exception: listening to another module's event does not close a cycle, because the module that publishes the event does not depend on its listeners. Two modules where one calls the other through a contract and the other listens to its events are not a cycle. Set `listeners.cycles` to `true` to count listeners too (see the [FAQ](../faq#does-listening-to-another-module-s-event-count-as-a-dependency)). Listening still counts for `depends_on` unless `listeners.depends_on` is `false`.

A cycle made of real calls is broken by moving one direction: publish an event from one side, or introduce a shared or orchestrating module that both depend on.

## unused_dependency

A module lists a module in `depends_on` but none of its classes uses it. It usually means a dependency was removed and the config was not, so `depends_on` no longer shows what the module really needs.

```text
! [unused_dependency] (warn)
  Module [Catalog] lists [Shared] in depends_on, but does not use it. Remove it so depends_on shows the real dependencies.
```

It is a warning by default (it arrived in 0.4); set `'unused_dependency' => 'error'` to make it fail. Listening to an event of the module counts as using it, unless `listeners.depends_on` is `false`.

## What counts as a dependency

Counted: `new`, static calls and properties, class constants, `::class`, type declarations (parameters, returns, properties, union, intersection and DNF types), `extends`, `implements`, traits, attributes, `instanceof` and `catch`.

Not counted: `use` imports on their own, function calls, constants, docblock types (`@var`, generics), class names in strings (`'App\\Foo'`, `app('...')`) and dynamic references. When unsure, Cordon Modulith reports nothing: false positives are treated as bugs.

Files in `vendor`, `node_modules`, `tests` and `Tests` directories and Blade views are skipped. Code outside modules (for example `app/Http`) is not analysed.

## Your own rules

See [custom rules](./custom-rules).
