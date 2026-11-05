# PHPStan

Cordon Modulith ships a PHPStan rule that reports `internal_access`, so you see violations in your editor while you type.

A fresh Laravel app doesn't ship PHPStan, so install it first (Larastan already brings it):

```bash
composer require --dev phpstan/phpstan
```

With [phpstan/extension-installer](https://github.com/phpstan/extension-installer) the rule is enabled automatically. It is a Composer plugin, so allow it before requiring it:

```bash
composer config allow-plugins.phpstan/extension-installer true
composer require --dev phpstan/extension-installer
```

Or include the extension by hand. A complete minimal `phpstan.neon` for modules in `app/Modules`:

```yaml
# phpstan.neon
includes:
    - vendor/chrisabner/cordon-modulith/extension.neon

parameters:
    level: 5
    paths:
        - app
```

Point `paths` at the folders that contain your modules: `app` for `app/Modules`, `Modules` for nwidart/laravel-modules, `app-modules` for InterNACHI/modular. PHPStan stops if a listed path doesn't exist.

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

- It uses the same reference detection, public API rules and baseline as `cordon:verify`, on the AST PHPStan already parsed. `#[Internal]` and `#[PublicApi]` are read through PHPStan's static reflection.
- PHPStan does not boot Laravel, so the rule reads `config/cordon.php` directly (and `config/modules.php` / `config/app-modules.php` when they load without the framework). Keep `config/cordon.php` a plain array.
- The project root is PHPStan's working directory. If you run PHPStan from elsewhere, set it:

```yaml
parameters:
    cordon:
        basePath: /path/to/project
```

## Configuration errors

Project config files (`config/cordon.php`, `config/modules.php`, `config/app-modules.php`) are evaluated without booting Laravel. Helpers such as `base_path()`, `app_path()` and `config_path()` resolve against the project root, but anything that needs the framework (facades, the container, `env()` values that live in a booted app) can't be evaluated. In that case the rule reports one error with the identifier `cordon.configuration` and the resolvers fall back to their defaults.

Fix it by setting the values explicitly in `config/cordon.php` (for example `resolvers.nwidart.path`). To silence it, ignore the identifier:

```yaml
parameters:
    ignoreErrors:
        - identifier: cordon.configuration
```

With parallel workers the error may appear once per worker.

## Limits

- Only `internal_access` is reported: `undeclared_dependency` and `cycles` need the whole graph, so they stay in `cordon:verify`.
- `cordon-baseline.json` is applied, so the editor shows the same `internal_access` findings as `cordon:verify`; there is no need to copy them into PHPStan's baseline. A baseline file that cannot be read is reported once (identifier `cordon.configuration`) and not applied. To silence other paths, ignore the identifier `cordon.internalAccess`.
