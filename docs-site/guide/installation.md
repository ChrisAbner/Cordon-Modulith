# Installation

Requirements: PHP 8.3+ and Laravel 12 or 13.

```bash
composer require --dev chrisabner/cordon-modulith
```

## Check what was detected

Cordon Modulith detects your module layout automatically:

```bash
php artisan cordon:modules
```

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

It exits with `1` when there are violations, `0` otherwise and `2` on invalid options.

## Publish the config (optional)

```bash
php artisan vendor:publish --tag=cordon-config
```

Next: understand [the public API of a module](./public-api), or [adopt it in an existing project](./baseline) if the first run reports many violations.
