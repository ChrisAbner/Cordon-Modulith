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
| `external_dependency` | A module using a Composer package that is not in its `packages` or in the shared ones, with `packages` configured (it checks nothing without it). One report per file and package. | error |
| `tables` | A module altering, querying or pointing a foreign key at a table that another module creates. A heuristic that reads literal names: off until you turn it on, and meant to run as `warn`. One report per file, table and kind of use. | off |

The built-in rules are enabled by default. `unused_dependency`, `host_access` and `public_api_leak` start as warnings (they arrived after 0.3): their violations are reported but do not fail. `layers` checks nothing until `layers` is configured, and `external_dependency` checks nothing until a module lists `packages`. `tables` is the exception: it is `off` until you turn it on. Set a rule to `error`, `warn` or `off` (`true` and `false` keep working) in the config:

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

### Named interfaces

`Catalog::Pricing` in `depends_on` lists only part of `Catalog`'s public API (the [`Pricing` interface](./configuration#named-interfaces)). A public class of `Catalog` that is not in it is reported, once per file and class:

```text
x [undeclared_dependency] app/Modules/Billing/Services/Invoicer.php:12
  Module [Billing] uses App\Modules\Catalog\Contracts\Stock, which is not in the interfaces it declares on [Catalog] (Pricing).
```

Add the class to an interface `Billing` declares, or declare the interface that has it. `Catalog` and `Catalog::*` allow the whole public API, as before.

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

It is a warning by default (it arrived in 0.4); set `'unused_dependency' => 'error'` to make it fail. Listening to an event of the module counts as using it, unless `listeners.depends_on` is `false`. An entry for a named interface (`Catalog::Pricing`) is unused when no class of that interface is used.

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

A module may depend on modules of its own layer and of every layer below it. Modules left out of `layers` are not checked by this rule, and `depends_on` still applies on top of it. It reports once per file and class of the layer above, so a new class in a file already in the baseline is a new violation. Listeners count as they do for `depends_on` (`listeners.depends_on`). Without `layers`, the rule checks nothing. A [nested module](./configuration#nested-modules) that `layers` does not name takes the layer of its nearest ancestor that it names, as the source and as the target of a dependency, the same inheritance `external_dependency` and `tables` use: with `Billing` in a layer, `Billing.Payments` is in it too. Naming the child in `layers` gives it a layer of its own. The use between a parent and its children is never reported. The PHPStan rule does not report it yet.

## public_api_leak

A public class names an internal class of its own module in its public signature, so other modules cannot use the public class without reaching the internal one:

```text
! [public_api_leak] (warn) app/Modules/Billing/Contracts/Invoices.php:10
  App\Modules\Billing\Contracts\Invoices::find() returns App\Modules\Billing\Models\Invoice, which is internal to [Billing]: other modules reach it through the public API.
```

The public signature is what other modules see: the parameters and return types of public methods, public properties (promoted ones too) and the types of public constants. Private and protected members, the other parameters of a constructor (the container passes them) and anonymous classes do not count. Return a class from a public namespace instead, such as a DTO in `Data`, or mark the class `#[PublicApi]` if it is meant to be shared. It is a warning by default (it arrived in 0.4).

## external_dependency

With `packages` in `config/cordon.php`, a module uses a Composer package it does not list:

```php
'packages' => ['shared' => ['laravel/framework', 'illuminate/*']],
'modules' => [
    'Billing' => ['packages' => ['stripe/stripe-php']],
],
```

```text
x [external_dependency] app/Modules/Billing/Services/Checkout.php:15
  Module [Billing] uses GuzzleHttp\Client from [guzzlehttp/guzzle], which is not in its packages.
```

One report per file and package, at the first use. A module may use the packages it lists and the `shared` ones (names such as `stripe/stripe-php`, or a prefix ending in `*`: `symfony/*`, `illuminate/*`; compared without regard to case). A module without `packages` is not checked, and `packages => []` allows only the shared ones. A nested module without its own `packages` takes the list of the module it is in. Fix a report by moving the use behind a class of a module that may use the package, or by listing the package when the dependency is intended.

Cordon reads `vendor/composer/installed.json` as plain data (it never loads your autoloader), so run `composer install` first: when a module lists `packages` and the file is missing, `cordon:verify` stops with exit code `2`, and a listed package that is not installed is a warning with the closest name. A class belongs to a package only when an installed package provably provides it: the file of a PSR-4 or PSR-0 class has to exist in the package, and a classmap names its classes one by one, counting only what its files declare unconditionally inside a namespace. The stubs of the polyfills (`symfony/polyfill-php80` and the like) declare PHP's own classes under `if (PHP_VERSION_ID < ...)` or in the global namespace, and a class that two packages declare cannot be said to be either's, so none of them is attributed to a package. So PHP's own classes (`DateTimeImmutable`, `PDO`), the classes of your project and of your modules, and those of a package that is not installed (a `require-dev` package left out by `--no-dev`, a PHP extension) belong to none and are never reported, even when a package shares their namespace. When two packages declare nested prefixes (`Illuminate\` and `Illuminate\Support\`), the class goes to the package that holds its file, preferring the longest prefix. A package listed under a name it replaces (`illuminate/support` for `laravel/framework`) also covers it.

Every reference counts the way it does for the other rules: code, [docblocks](./configuration#docblocks) when they are on, and listeners (a listener that type-hints a package's event uses that package). The code outside the modules (`host`) is not checked: it is the glue that wires the modules to the framework, so `packages` cannot be set for it. The rule is an `error` like `layers`, and it checks nothing until some module lists `packages`. `@cordon-ignore external_dependency` works as for the other rules. The PHPStan rule does not report it yet. `cordon:why Billing stripe/stripe-php` lists the uses of a package by a module, and `cordon:explain Stripe\StripeClient` says which package a class belongs to and who uses it.

## tables

::: warning Heuristic, off by default
`tables` reads names out of source code, so it can be wrong in ways the other rules cannot. It is `off` until you turn it on, and `warn` is the severity it is meant for: `'rules' => ['tables' => 'warn']`.
:::

A module alters, queries or points a foreign key at a table that another module creates:

```text
! [tables] app/Modules/Billing/Database/Migrations/2024_02_01_000000_create_invoices_table.php:13 (warn)
  Module [Billing] adds a foreign key to table [orders], which belongs to [Orders]. Read or change another module's data through its public API instead: see https://chrisabner.github.io/Cordon-Modulith/recipes/cross-module-relations
```

There are three messages, one per kind of use: `Module [Billing] alters table [orders], which belongs to [Orders].` (`Schema::table`, `drop`, `dropIfExists`, `rename`), `Module [Billing] adds a foreign key to table [orders], which belongs to [Orders].` and `Module [Billing] queries table [orders], which belongs to [Orders].` (`DB::table('orders')` and the joins that follow it, or a model of the module whose table is `orders`). One report per file, table and kind of use. A foreign key is reported even when `depends_on` lists the owner: coupling through a table is what the [cross-module relations recipe](../recipes/cross-module-relations) advises against.

**Who owns a table.** The module that creates it with `Schema::create('orders', ...)`, wherever its migration lives: inside the module, or in the code outside the modules (`database/migrations`, see [`host`](./configuration#host)), which then owns it. A table that two of them create has no single owner, and a table that nobody creates has none either: neither is ever reported, and `cordon:docs` lists the first in the module cards as "without owner", with the modules that create it. The tables the host owns (`users` almost always) are never reported, and the code outside the modules is never reported either: it is the glue.

**What it reads.** Only literals: `Schema::create('orders', ...)`, `->on('orders')` after `foreign()` or `foreignId()`, `->constrained('orders')` and `DB::table('orders as o')->join('items', ...)`. A table name in a variable, a constant or `config()` is not guessed and not reported. Joins count only on a chain that starts at `DB::table('...')` and stays on the query builder (`where*`, `orWhere*`, `orderBy*`, `select*`, `join*`, `leftJoin*`, `having*`, `groupBy*`, `limit`, `take`, `skip`, `distinct`, `when`...). A terminal call such as `get()`, `pluck()`, `first()`, `count()` or `paginate()`, or any method not in that list, ends it: `DB::table('a')->pluck('n')->join('orders')` is a Collection's `join`, not a table, and the same goes for `Schema` and `DB` that are not the facades.

`foreignId('order_id')->constrained()`, with no table, is reported against `orders`: Cordon asks Laravel's own `Str::plural` for the table, as `constrained()` does, so `category_id` is `categories` and `person_id` is `people`. It does not guess in the cases where it cannot know the table: `foreignIdFor(Order::class)->constrained()` (the table is the model's), `constrained($table)` and `constrained(column: 'uuid')`.

**Models.** A model that sets `protected $table = 'orders'` uses that table. One that sets none uses Laravel's default, `Str::snake(Str::pluralStudly($class))` (`OrderItem` is `order_items`), but only when it provably extends `Illuminate\Database\Eloquent\Model` (or the framework's `User`) through the analysed code. Cordon does not infer that default, and does not read a literal `$table` either, when anything in the model can change its table in a way it cannot follow: the class, a parent or a trait of your project that it uses (or a parent uses) declares `$table`, overrides `getTable()`, assigns `$this->table`, calls `setTable()`, or sets `$connection` (another database, where the same name is another table). A `$table = self::NAME` or a `getTable()` is the same: the table is unknown. Such a model is not reported, and the module card lists it as "without owner" with the reason. Traits that come from packages (`HasFactory`, `SoftDeletes`...) are assumed not to set `$table`: Cordon does not read vendor code. A model that extends a class from a package, or a `Pivot`, is left out. Cordon never loads the model.

**Nested modules.** A table created in `Billing.Invoices` belongs to `Billing.Invoices`. A nested module may use the tables of its ancestors, as it may use their classes, so `Billing.Invoices` touching a table `Billing` created is not reported. The other way round, and between siblings, it is reported, like `internal_access`. From outside `Billing` a nested module does not exist, so `Orders` touching that table gets `which belongs to [Billing]`.

`@cordon-ignore tables` works as for the other rules, and the baseline records the violations of a rule that is an `error`. The PHPStan rule does not report it. The collector only runs when the rule is on, so a project that leaves it off pays nothing, and turning it on starts a new [analysis cache](./configuration#cache).

## What counts as a dependency

Counted: `new`, static calls and properties, class constants, `::class`, type declarations (parameters, returns, properties, union, intersection and DNF types), `extends`, `implements`, traits, attributes, `instanceof` and `catch`.

Not counted: `use` imports on their own, function calls, constants, class names in strings (`'App\\Foo'`, `app('...')`) and dynamic references. Class names in docblock types (`@var`, `@param`, generics) are not counted either, unless you turn on [`docblocks`](./configuration#docblocks). When unsure, Cordon Modulith reports nothing: false positives are treated as bugs.

Files in `vendor`, `node_modules`, `tests` and `Tests` directories and Blade views are skipped. Code outside modules (for example `app/Http`) is checked by [`host_access`](#host-access).

A file belongs to the module of its directory, and a class name to the module of its namespace. A file whose namespace does not match its directory (it breaks PSR-4) is checked as code of the module it sits in.

## Your own rules

See [custom rules](./custom-rules).
