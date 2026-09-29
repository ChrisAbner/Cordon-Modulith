# ADR 0009: Custom rules

**Status:** proposed (implemented, pending maintainer approval)

## Context
Teams have architecture rules beyond module boundaries (a shared kernel that must stay small, modules that must declare their dependencies...). Brief 06A asks for the `rules` config to accept classes implementing `Cordon\Contracts\Rule`.

## Decision
- The `rules` config keeps the built-in switches (`'cycles' => false`) and also accepts class names as values, with or without a key: `[App\Architecture\MyRule::class]` or `['mine' => MyRule::class]`.
- Custom rules run after the built-in ones. Their `id()` must be unique; a duplicated id or a class that does not exist or does not implement `Rule` throws an `InvalidArgumentException` with the fix.
- `RuleSet::fromConfig()` takes an optional factory; the service provider passes the Laravel container so rules can have constructor dependencies. Without a factory (tests, core usage) rules are created with `new`.
- `Rule` and `AnalysisContext` become the extension API: from now on they follow SemVer like the rest of the public API.
- Loading rule classes is not loading analysed code: rules are extension code chosen by the project, like a PHPStan extension. Analysed code is still never loaded.

## Consequences
- Custom violations work with the baseline, the reporters, `--module` and the Pest expectation, because they are regular `Violation`s.
- The PHPStan rule (ADR 0007) does not run custom rules.
