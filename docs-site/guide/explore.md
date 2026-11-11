# Exploring the modules

Four commands answer questions about the modules without failing anything. Each takes `--format=json`, and each runs from artisan (`php artisan cordon:explain`) or `vendor/bin/cordon` (`vendor/bin/cordon explain`).

## Why is this class internal?

```bash
php artisan cordon:explain 'App\Modules\Catalog\Models\Product'
```

```text
App\Modules\Catalog\Models\Product
  Module:  Catalog
  Status:  internal: it is not in a public namespace, not marked #[PublicApi], and its module is not open
  Used by:
    x Billing  app/Modules/Billing/Services/CheckoutService.php:13 (use)
```

The status gives the reason: `#[Internal]` or `#[PublicApi]` on the class, an open module, a public namespace such as `Contracts`, or the module's `public` setting. `x` marks the uses that `cordon:verify` reports as errors and `!` the ones it reports as warnings, after the baseline; `-` is a use it does not report. In JSON, each use has `violation` and its `severity`. `listener` uses come from listeners of the class.

When a module lists [`packages`](./configuration#packages), `explain` also takes a class of an installed package (`cordon:explain 'Stripe\StripeClient'`): it prints the package (`Package:  stripe/stripe-php`) and the modules that use the class, marked the same way for `external_dependency`.

## Why does this module depend on that one?

```bash
php artisan cordon:why Billing Catalog
```

```text
Billing -> Catalog: 4 references
  app/Modules/Billing/Services/CheckoutService.php:11  App\Modules\Catalog\Contracts\ProductCatalog (use)
  ...
Billing does not declare depends_on, so it may depend on any module.
```

It lists every reference, and whether `depends_on` allows the dependency. Use it before adding a module to `depends_on`, or to find what to change to remove a dependency.

The second argument can also be a Composer package: `cordon:why Billing stripe/stripe-php` lists the references of `Billing` to the classes of that package, and whether `packages` allows them (the [`external_dependency`](./rules#external-dependency) rule). A name with a slash is a package, a name without one is a module.

## What can a change to this module affect?

```bash
php artisan cordon:impact Shared
```

```text
Modules that depend on Shared:
  1  Billing (directly)
  2  Catalog (through Billing)
```

The modules that depend on it, directly or through others, nearest first: the ones to retest when it changes.

## What did this branch change?

```bash
php artisan cordon:affected --since=origin/main
```

```text
Modules affected by the changes since origin/main:
  Catalog  changed
  Billing  depends on Catalog
```

The modules with changed files since a git reference, and the ones that depend on them (the graph of `cordon:impact`). With `--tests` it prints the test folders of those modules, one per line, to pass to Pest. See [run only the tests the change can affect](../recipes/affected-tests). `cordon:verify --since=origin/main` checks the files that changed, as `cordon:verify <paths>` does.

A change in a nested module affects its parent too, which is the module the outside sees, and whatever depends on it. A change in a parent affects its nested modules, and so does a parent that is affected only because it depends on a change (its nested modules use its internals). A parent reached only because a nested module changed does not pull in the other nested modules, unless they depend on the one that changed. A change in the code outside the modules (`host`) affects only `host`: nothing depends on it.

## The whole graph

```bash
php artisan cordon:graph                  # Mermaid, to paste in a pull request or a wiki
php artisan cordon:graph --format=dot | dot -Tsvg > modules.svg   # Graphviz
php artisan cordon:graph --format=c4      # C4-PlantUML, for diagrams as code
php artisan cordon:graph --format=json    # for your own tools
php artisan cordon:graph --module=Billing # Billing and its neighbours only
```

Each arrow is a dependency labelled with its number of references, the same number in the four formats. Dependencies with violations (after the baseline) are red, and a dependency made only of listeners is dotted in Mermaid and dashed in DOT. The JSON lists `modules`, and per dependency `from`, `to`, `references`, `kinds` (`use`, `listener`, and `docblock` with [`docblocks`](./configuration#docblocks) on) and `violations`.

The C4 export is a PlantUML file (`!include <C4/C4_Component>`, which PlantUML bundles): one `Container_Boundary` for the application, a `Component` per module with the `description` of its [`metadata`](./living-documentation#components-and-metadata), and a `Rel` per dependency with its number of references (and the named interface it is limited to, as `3 Catalog::Pricing`), drawn in red, through a `violation` relationship tag (`AddRelTag`), when it has violations. A nested module is drawn inside a `Boundary` of its parent, and a module with no dependencies is still there. Names and descriptions are escaped, so a quote or a backslash in them cannot break the file. Render it with PlantUML (`plantuml modules.puml`) or any editor that supports it.

The graph draws the edges as they are in the code, so with [nested modules](./configuration#nested-modules) `Orders` points at `Billing.Invoices` when it uses a class of it, while the rules (and `cordon:impact` and `cordon:affected`) see `Billing` there. The interfaces on an arrow (`Billing::Api`) are the ones the rules apply to it: a dependency between two nested modules of the same parent ignores the interfaces declared on the parent.

The diagrams of `cordon:docs` differ on purpose: their labels count the classes used, as the tables next to them list, and only `internal_access` makes an arrow red.
