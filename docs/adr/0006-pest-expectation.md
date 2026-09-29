# ADR 0006: Pest expectation `toRespectBoundaries()`

**Status:** proposed (implemented, pending maintainer approval)

## Context
Most Laravel teams already run Pest. Brief 02A asks for `expect('Billing')->toRespectBoundaries()` and `expect(Cordon::modules())->each->toRespectBoundaries()`, reusing the analyzer.

## Decision
- `Cordon\Testing\Cordon` exposes `modules()`, `result()`, `violationsOf($module)` and `flush()`. It resolves `Cordon\Laravel\Verifier` from the running Laravel container, so the expectation needs a booted application (a test that extends the app's `Tests\TestCase`). Outside one it throws a `LogicException` with that advice.
- The result is computed once per process and reused while the `cordon` config and the baseline file (modification time and size) do not change. Expecting on every module therefore costs one analysis.
- The baseline is applied, exactly as `cordon:verify` does, so adopting projects get the same verdict in CI and in tests.
- A module's violations are those of `cordon:verify --module` (ADR 0005): the ones it causes and the cycles it takes part in.
- The expectation is registered from `src/Testing/expectations.php`, loaded through Composer's `files` autoload. It only calls `Pest\Expectation::extend()` when Pest is installed, so production code paths are unaffected.

## Consequences
- New public API: `Cordon\Testing\Cordon` and the `toRespectBoundaries` expectation name.
- Pest's own `arch()` expectations remain the tool for rules inside a module; this one covers boundaries between modules.
