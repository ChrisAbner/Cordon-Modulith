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

## The whole graph

```bash
php artisan cordon:graph                  # Mermaid, to paste in a pull request or a wiki
php artisan cordon:graph --format=dot | dot -Tsvg > modules.svg   # Graphviz
php artisan cordon:graph --format=json    # for your own tools
php artisan cordon:graph --module=Billing # Billing and its neighbours only
```

Each arrow is a dependency labelled with its number of references, the same number in the three formats. Dependencies with violations (after the baseline) are red, and a dependency made only of listeners is dotted in Mermaid and dashed in DOT. The JSON lists `modules`, and per dependency `from`, `to`, `references`, `kinds` (`use`, `listener`) and `violations`.

The diagrams of `cordon:docs` differ on purpose: their labels count the classes used, as the tables next to them list, and only `internal_access` makes an arrow red.
