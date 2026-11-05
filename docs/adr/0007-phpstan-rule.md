# ADR 0007: PHPStan rule and configuration outside Laravel

**Status:** accepted

## Context
Developers see PHPStan errors in their editor while they type; `cordon:verify` only runs on demand or in CI. Brief 02B asks for a PHPStan rule that reports `internal_access`. PHPStan does not boot the Laravel application, so the rule cannot read `config()`.

## Decision
- `Cordon\PHPStan\InternalAccessRule` is a `Rule<FileNode>`. For each analysed file inside a module it collects references with the same `ReferenceCollector` and applies the same `PublicApiPolicy` as `cordon:verify`, on the AST PHPStan already parsed (amended on 2026-11-05; it used to parse each file again), and reports each internal target once per file with the identifier `cordon.internalAccess`.
- Target classes are looked up with PHPStan's static reflection to honour `#[Internal]` and `#[PublicApi]`; nothing is autoloaded.
- `Cordon\Laravel\StandaloneConfig` reads `config/cordon.php` from the project (merged over the package defaults, like `mergeConfigFrom`), plus `config/modules.php` and `config/app-modules.php` when they load without the framework. Files that throw (for example because they call `base_path()`) are ignored and the resolvers use their defaults.
- The project root defaults to PHPStan's `%currentWorkingDirectory%` and can be set with the `cordon.basePath` parameter.
- `extension.neon` registers the rule and is listed under `extra.phpstan.includes`, so `phpstan/extension-installer` enables it automatically.
- Only `internal_access` is reported. `undeclared_dependency` and `cycles` need the whole graph and stay in `cordon:verify`. Excluded directories and `rules.internal_access = false` are honoured. `cordon-baseline.json` is applied with the same keys as `cordon:verify` (amended on 2026-11-05; it used to be ignored, so teams kept a second baseline in PHPStan). A baseline that cannot be read is reported once as `cordon.configuration` and not applied. The baseline and the three config files are tracked as file dependencies (PHPStan 2.3+), so PHPStan's result cache is invalidated when they change.

## Consequences
- `config/cordon.php` should stay a plain array (it may call `env()`), otherwise the PHPStan rule falls back to the defaults.
- The editor shows the same `internal_access` findings as CI: those in `cordon-baseline.json` are suppressed in both.
