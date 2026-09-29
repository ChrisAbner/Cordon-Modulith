# ADR 0005: `--module` option and shared verification flow

**Status:** accepted

## Context
Teams want to check one module at a time: while working on it, in a module-owned CI job, or from a test. Brief 02D asks for `cordon:verify --module=Billing`, and the Pest expectation (ADR 0006) needs the same flow as the command without going through Artisan.

## Decision
- `cordon:verify` gains a repeatable `--module=NAME` option. It narrows the report to the violations **of** those modules: the ones whose source module is one of them, plus the dependency cycles that include them. A cycle belongs to every module on its path.
- The analysis still covers every module, because cycles and the public API of target modules need the whole graph. Only the report is filtered.
- An unknown module name exits with `2` (invalid option). `--module` cannot be combined with `--generate-baseline`, because a partial baseline would silently drop the other modules' entries.
- The flow resolve → configure → analyse → baseline moves from the command into `Cordon\Laravel\Verifier`, bound in the container. `Cordon\Analysis\ModuleFilter` implements the filtering without changing `Result`.

## Consequences
- No change to contracts, value objects, config shape or the baseline format.
- Exit codes are unchanged: `1` when the filtered report has violations, `0` otherwise.
