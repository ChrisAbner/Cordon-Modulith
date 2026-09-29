# ADR 0007: PHPStan rule and configuration outside Laravel

**Status:** proposed (implemented, pending maintainer approval)

## Context
Developers see PHPStan errors in their editor while they type; `cordon:verify` only runs on demand or in CI. Brief 02B asks for a PHPStan rule that reports `internal_access`. PHPStan does not boot the Laravel application, so the rule cannot read `config()`.

## Decision
- `Cordon\PHPStan\InternalAccessRule` is a `Rule<FileNode>`. For each analysed file inside a module it runs the same extractor and `PublicApiPolicy` as `cordon:verify`, and reports each internal target once per file with the identifier `cordon.internalAccess`.
- Target classes are looked up with PHPStan's static reflection to honour `#[Internal]` and `#[PublicApi]`; nothing is autoloaded.
- `Cordon\Laravel\StandaloneConfig` reads `config/cordon.php` from the project (merged over the package defaults, like `mergeConfigFrom`), plus `config/modules.php` and `config/app-modules.php` when they load without the framework. Files that throw (for example because they call `base_path()`) are ignored and the resolvers use their defaults.
- The project root defaults to PHPStan's `%currentWorkingDirectory%` and can be set with the `cordon.basePath` parameter.
- `extension.neon` registers the rule and is listed under `extra.phpstan.includes`, so `phpstan/extension-installer` enables it automatically.
- Only `internal_access` is reported. `undeclared_dependency` and `cycles` need the whole graph and stay in `cordon:verify`. Excluded directories and `rules.internal_access = false` are honoured. The baseline is not applied: PHPStan has its own baseline.

## Consequences
- `config/cordon.php` should stay a plain array (it may call `env()`), otherwise the PHPStan rule falls back to the defaults.
- The editor shows the same `internal_access` findings as CI, minus those in `cordon-baseline.json`, which should go into PHPStan's baseline if the team wants them silenced there too.
