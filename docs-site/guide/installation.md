# Installation

Requirements: PHP 8.2+ and Laravel 12 or 13 (Laravel 13 needs PHP 8.3+).

## Is Cordon for my project?

Cordon checks the boundaries between **modules split by feature**: `Billing`, `Catalog`, `Orders`, each with its own models, services and events. That is the layout of nwidart/laravel-modules, InterNACHI/modular or an `app/Modules` folder.

Most Laravel apps start **split by layer** instead: `app/Http`, `app/Models`, `app/Services`, `app/Providers`. There every layer uses `Models`, so pointing Cordon at `app/` reports almost every reference as `internal_access`, plus one large cycle. That tells you the app is layered, not that it is broken. For rules between layers ("controllers may use services, services may use models"), use [Deptrac](https://github.com/deptrac/deptrac) or Pest `arch()`; see the [comparison](../comparison).

`cordon:modules` warns when the modules it found look like those layers. If your app is layered today but you are moving features into modules, Cordon fits once the first module exists: point it at the modules folder, not at `app/`.

```bash
composer require --dev chrisabner/cordon-modulith
```

## Check what was detected

Cordon Modulith detects your module layout automatically:

```bash
php artisan cordon:modules
```

```text
Resolver: namespace
+---------+---------------------+---------------------+----------------+------+
| Module  | Namespace           | Path                | Depends on     | Open |
+---------+---------------------+---------------------+----------------+------+
| Billing | App\Modules\Billing | app/Modules/Billing | (not declared) | no   |
| Catalog | App\Modules\Catalog | app/Modules/Catalog | (not declared) | no   |
+---------+---------------------+---------------------+----------------+------+
```

With a plain `app/Modules` folder you see `Resolver: namespace`, as above. With nwidart/laravel-modules the same command prints `Resolver: nwidart`:

```text
Resolver: nwidart
+----------+------------------+-----------------+----------------+------+
| Module   | Namespace        | Path            | Depends on     | Open |
+----------+------------------+-----------------+----------------+------+
| Billing  | Modules\Billing  | Modules/Billing | (not declared) | no   |
| Catalog  | Modules\Catalog  | Modules/Catalog | (not declared) | no   |
+----------+------------------+-----------------+----------------+------+
```

| Resolver | Detected when | Module | Namespace |
|---|---|---|---|
| `nwidart` | `Modules/*/module.json` exists | `Modules/Blog` → `Blog` | `Modules\Blog` (from `config/modules.php`) |
| `internachi` | `app-modules/` exists | `app-modules/billing` → `billing` | from the module's `composer.json` PSR-4 entry |
| `namespace` | fallback | `app/Modules/Billing` → `Billing` | `App\Modules\Billing` |

If nothing is found, publish the config and set the resolver and its path (see [Configuration](./configuration)).

## Verify

```bash
php artisan cordon:verify
```

It exits with `1` when there are violations or no modules are found (`--allow-empty` passes while a project has none yet), `0` otherwise and `2` on invalid options.

## Publish the config (optional)

```bash
php artisan vendor:publish --tag=cordon-config
```

Next: understand [the public API of a module](./public-api), or [adopt it in an existing project](./baseline) if the first run reports many violations.
