# Rules

| Rule | Reports |
|---|---|
| `internal_access` | A module using a class that is not part of another module's [public API](./public-api). One report per file and target class. |
| `undeclared_dependency` | A module with `depends_on` using a module that is not listed. One report per file and target module. |
| `cycles` | Modules that depend on each other in a cycle, with the shortest cycle path. |

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

## cycles

```text
x [cycles]
  Modules [Orders, Payments] form a dependency cycle: Orders -> Payments -> Orders. Break it by inverting one dependency, for example with an event or a contract.
```

Listening to another module's event is a dependency on that module too. See [break a cycle with an event](../recipes/break-a-cycle).

## What counts as a dependency

Counted: `new`, static calls and properties, class constants, `::class`, type declarations (parameters, returns, properties, union, intersection and DNF types), `extends`, `implements`, traits, attributes, `instanceof` and `catch`.

Not counted: `use` imports on their own, function calls, constants, docblock types (`@var`, generics), class names in strings (`'App\\Foo'`, `app('...')`) and dynamic references. When unsure, Cordon Modulith reports nothing: false positives are treated as bugs.

Files in `vendor`, `node_modules`, `tests` and `Tests` directories and Blade views are skipped. Code outside modules (for example `app/Http`) is not analysed.

## Your own rules

See [custom rules](./custom-rules).
