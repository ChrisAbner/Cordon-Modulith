# Rules

| Rule | Reports | Default |
|---|---|---|
| `internal_access` | A module using a class that is not part of another module's [public API](./public-api). One report per file and target class. | error |
| `undeclared_dependency` | A module with `depends_on` using a module that is not listed. One report per file and target module. | error |
| `cycles` | Modules that depend on each other in a cycle. One report per dependency inside the cycle, with the shortest cycle it closes. | error |
| `unused_dependency` | A `depends_on` entry the module never uses. One report per module and unused entry. | warn |
| `host_access` | Code outside the modules (`host.paths`: `app`, `routes` and `database` by default) using a class that is not part of a module's [public API](./public-api). One report per file and target class. | warn |
| `layers` | A module depending on a module of a layer above its own, with `layers` configured (it checks nothing without it). One report per file and target class. | error |
| `public_api_leak` | A public class whose public signature names an internal class of its own module. One report per file and internal class. | warn |

The built-in rules are enabled by default. `unused_dependency`, `host_access` and `public_api_leak` start as warnings (they arrived after 0.3): their violations are reported but do not fail. `layers` checks nothing until `layers` is configured. Set a rule to `error`, `warn` or `off` (`true` and `false` keep working) in the config:

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

When the internal class is the model of an Eloquent relation (`$this->belongsTo(Product::class)`, `hasMany`, `morphToMany`...), the message says so and links to [replace a cross-module Eloquent relation](../recipes/cross-module-relations): a relation ties the two modules' tables together, which a contract alone does not undo.

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

## host_access

Code outside the modules (controllers in `app/Http`, Filament resources in `app/Filament`, routes, migrations and factories) uses a class that is internal to a module. That code is usually where boundaries leak first: a Filament panel that edits every module's models ties them all to one screen.

```text
! [host_access] (warn) app/Filament/Resources/ProductResource.php:9
  Code outside the modules uses App\Modules\Catalog\Models\Product, which is internal to module [Catalog]. Depend on its public API instead (a class in a public namespace such as Contracts, or one marked #[PublicApi]).
```

The code outside the modules is what `host.paths` lists (`app`, `routes` and `database` by default), without the files of a module. It has no `depends_on`, takes no part in cycles and is not a module in `cordon:modules`, the graph or the docs. The rule is a warning by default (it arrived in 0.4); set `'host_access' => 'error'` to make it fail, or `host.paths` to `[]` to leave that code unchecked. The PHPStan rule does not report it yet.

The code outside the modules is reported as module `host`: `--module Catalog` shows what Catalog's own code does, not the host code that uses Catalog. `cordon:explain`, `why` and `impact` describe the modules only, so they do not list uses from the host either; `cordon:verify` is where they appear.

## layers

With `layers` in `config/cordon.php`, a module depends on a module of a layer above its own:

```php
'layers' => [
    ['Orders', 'Billing'], // layer 1, the top
    ['Catalog'],
    ['Shared'],            // layer 3
],
```

```text
x [layers] app/Modules/Shared/Money.php:12
  Module [Shared] (layer 3) depends on [Orders] (layer 1) through App\Modules\Orders\Models\Order; a layer may only depend on the layers below it.
```

A module may depend on modules of its own layer and of every layer below it. Modules left out of `layers` are not checked by this rule, and `depends_on` still applies on top of it. It reports once per file and class of the layer above, so a new class in a file already in the baseline is a new violation. Listeners count as they do for `depends_on` (`listeners.depends_on`). Without `layers`, the rule checks nothing. The PHPStan rule does not report it yet.

## public_api_leak

A public class names an internal class of its own module in its public signature, so other modules cannot use the public class without reaching the internal one:

```text
! [public_api_leak] (warn) app/Modules/Billing/Contracts/Invoices.php:10
  App\Modules\Billing\Contracts\Invoices::find() returns App\Modules\Billing\Models\Invoice, which is internal to [Billing]: other modules reach it through the public API.
```

The public signature is what other modules see: the parameters and return types of public methods, public properties (promoted ones too) and the types of public constants. Private and protected members, the other parameters of a constructor (the container passes them) and anonymous classes do not count. Return a class from a public namespace instead, such as a DTO in `Data`, or mark the class `#[PublicApi]` if it is meant to be shared. It is a warning by default (it arrived in 0.4).

## What counts as a dependency

Counted: `new`, static calls and properties, class constants, `::class`, type declarations (parameters, returns, properties, union, intersection and DNF types), `extends`, `implements`, traits, attributes, `instanceof` and `catch`.

Not counted: `use` imports on their own, function calls, constants, docblock types (`@var`, generics), class names in strings (`'App\\Foo'`, `app('...')`) and dynamic references. When unsure, Cordon Modulith reports nothing: false positives are treated as bugs.

Files in `vendor`, `node_modules`, `tests` and `Tests` directories and Blade views are skipped. Code outside modules (for example `app/Http`) is checked by [`host_access`](#host-access).

A file belongs to the module of its directory, and a class name to the module of its namespace. A file whose namespace does not match its directory (it breaks PSR-4) is checked as code of the module it sits in.

## Your own rules

See [custom rules](./custom-rules).
