# Compatibility

Cordon Modulith is in its 0.x series: a minor release (0.4 → 0.5) may break something, and each one says what in the [changelog](https://github.com/ChrisAbner/Cordon-Modulith/blob/main/CHANGELOG.md) and in [UPGRADE.md](https://github.com/ChrisAbner/Cordon-Modulith/blob/main/UPGRADE.md). Patch releases (0.4.0 → 0.4.1) only fix bugs.

From 1.0, the project follows [Semantic Versioning](https://semver.org) for everything listed under "What 1.0 will freeze". This page says what that is, so you know today what to rely on.

## What 1.0 will freeze

| What | Where |
|---|---|
| The keys of `config/cordon.php` and their defaults | [Configuration](./configuration) |
| The attributes `#[PublicApi]` and `#[Internal]` | [Public API](./public-api) |
| The command names, their options and the exit codes `0`, `1` and `2` | [Continuous integration](./ci) |
| The JSON report: its fields, versioned with `"version"` and described by `resources/schemas/report-v1.schema.json`. A new optional field keeps the version; removing or renaming one changes it, so ignore fields you do not know | [Continuous integration](./ci) |
| The baseline format (version 2: `rule`, `file`, `target`, `count`) | [Adopting in an existing project](./baseline) |
| Rule ids (`internal_access`, `undeclared_dependency`, `cycles`, `unused_dependency`, `host_access`, `layers`, `public_api_leak`) and PHPStan identifiers (`cordon.internalAccess`, `cordon.undeclaredDependency`, `cordon.cycle`, `cordon.incompleteGraph`, `cordon.configuration`) | [Rules](./rules), [PHPStan](./phpstan) |
| `extension.neon` and its `cordon.basePath` parameter | [PHPStan](./phpstan) |
| `toRespectBoundaries()` and `Cordon\Testing\Cordon` | [Pest](./pest) |
| The inputs of the GitHub Action | [Continuous integration](./ci) |
| Classes marked `@api` in `src/` | below |

Classes marked `@api`: `Contracts\Rule`, `Analysis\AnalysisContext`, `Analysis\Violation`, `Analysis\Edge`, `Analysis\Reference`, `Analysis\ClassDeclaration`, `Analysis\SymbolTable`, `Analysis\PublicApiPolicy`, `Analysis\DeclaredVisibility`, `Module\Module`, `Module\ModuleMap`, `Rules\Severity`, `Attributes\PublicApi`, `Attributes\Internal` and `Testing\Cordon` (all under `Cordon\`). They are what a [custom rule](./custom-rules) needs.

## What is never covered

- Classes marked `@internal`: every other class in `src/`. The custom rules guide uses some of them (`Analyzer`, `RuleSet`, `NamespaceResolver`...) to test a rule; that test code may need changes in a minor release until Cordon offers a public test helper.
- The wording of messages and the `text` format, which are for people. Parse the JSON instead.
- The Markdown that `cordon:docs` generates (its `--output` option is covered).
- Exactly which references are found: fixing a missed reference can report a violation that was there all along. Such fixes are listed in the changelog under "Fixed", or under "Breaking" when you have to act.

## New checks

A check that can fail a build that passed before, such as a new rule, does not start as `error`. It arrives as `off` or `warn` in a minor release, so you can see what it finds, and becomes `error` in a later minor release (from 1.0, in a major one). The changelog says which.
