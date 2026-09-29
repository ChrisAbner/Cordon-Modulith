# PHPStan

Cordon Modulith ships a PHPStan rule that reports `internal_access`, so you see violations in your editor while you type.

With [phpstan/extension-installer](https://github.com/phpstan/extension-installer) it is enabled automatically. Otherwise include it:

```yaml
# phpstan.neon
includes:
    - vendor/chrisabner/cordon-modulith/extension.neon
```

```text
 ------ ------------------------------------------------------------------------------------------
  Line   app/Modules/Billing/Services/CheckoutService.php
 ------ ------------------------------------------------------------------------------------------
  13     Module [Billing] uses App\Modules\Catalog\Models\Product, which is internal to module [Catalog].
         🪪  cordon.internalAccess
         💡 Depend on its public API instead: a class in a public namespace such as Contracts, or one marked #[PublicApi].
 ------ ------------------------------------------------------------------------------------------
```

## How it works

- It uses the same extractor and public API rules as `cordon:verify`. `#[Internal]` and `#[PublicApi]` are read through PHPStan's static reflection.
- PHPStan does not boot Laravel, so the rule reads `config/cordon.php` directly (and `config/modules.php` / `config/app-modules.php` when they load without the framework). Keep `config/cordon.php` a plain array.
- The project root is PHPStan's working directory. If you run PHPStan from elsewhere, set it:

```yaml
parameters:
    cordon:
        basePath: /path/to/project
```

## Limits

- Only `internal_access` is reported: `undeclared_dependency` and `cycles` need the whole graph, so they stay in `cordon:verify`.
- Cordon's baseline is not applied. Use PHPStan's own baseline if you want the same exceptions in the editor, or ignore the identifier `cordon.internalAccess` for specific paths.
