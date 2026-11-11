# PHPStan

Cordon Modulith ships PHPStan rules for three of its rules, so `vendor/bin/phpstan` and your editor report what `cordon:verify` reports:

| Rule | PHPStan identifier | Where it runs |
|---|---|---|
| `internal_access` | `cordon.internalAccess` | Every analysed file, including in the editor |
| `undeclared_dependency` | `cordon.undeclaredDependency` | Every analysed file, including in the editor |
| `cycles` | `cordon.cycle` | When PHPStan analyses the whole project, which includes editor mode |

Each error ends with a link to the rule's documentation. The other rules (`unused_dependency`, `host_access`, `layers` and `public_api_leak`) are reported by `cordon:verify` only.

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
         💡 Depend on its public API instead: a class in a public namespace such as Contracts, or one marked #[PublicApi]. Learn more: https://chrisabner.github.io/Cordon-Modulith/guide/rules#internal-access
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

## Cycles

A cycle is a property of the whole graph, so a collector gathers the modules each analysed file uses and one rule looks at all of them at the end, with the same algorithm as `cordon:verify`. Each dependency of a cycle is reported at its first reference (the lowest file and line), so its location stays put while the code around it changes.

- Editor mode analyses the whole project, so cycles are reported there too, and they follow the buffer you are editing.
- When some modules are left out (outside PHPStan's `paths`, or `phpstan analyse app/Modules/Billing`), Cordon says so once (`cordon.incompleteGraph`) instead of guessing. Add their directories to `paths`, or ignore the identifier if you analyse parts of the project on purpose:

  ```yaml
  parameters:
      ignoreErrors:
          - identifier: cordon.incompleteGraph
  ```
- When PHPStan analyses single files (`phpstan analyse a.php b.php`, as some pre-commit hooks do), there is no whole graph, so cycles are not checked and nothing is said. Use `cordon:verify <paths>` for cycles in a pre-commit hook.
- If PHPStan stops on an internal error in any file, it skips rules on collected data, cycles included, for that run.
- If you also run `cordon:verify` in CI, the cycles appear in both. That is expected.

## In the editor

Editors run PHPStan on the buffer you are editing as a temporary file standing in for the real one (`--tmp-file` with `--instead-of`, since PHPStan 2.1.17), over the whole project. Cordon reads the real path PHPStan was given, so the edited file gets the same module, excluded directories and baseline as on the command line, and the editor shows the same findings for the three rules, files without a class (routes, migrations) included.

## Limits

- PHPStan has no warnings, so each rule reports only while it is set to `error` in `config/cordon.php`. With `warn` or `off` it reports nothing; `cordon:verify` still shows the warnings.
- `cordon-baseline.json` is applied, so PHPStan shows the same findings as `cordon:verify`; there is no need to copy them into PHPStan's baseline. A baseline file that cannot be read is reported once (identifier `cordon.configuration`) and not applied. To silence other paths, ignore the rule's identifier.
- `listeners` in `config/cordon.php` applies too: listeners count for `undeclared_dependency` and not for cycles, by default.
