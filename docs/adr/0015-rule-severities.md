# ADR 0015: Give each rule a severity, and keep warnings out of the baseline

**Status:** accepted
**Date:** 2026-11-07 (warnings and the baseline: 2026-11-08)

## Context
`rules` held `true` or `false` per rule. A team adopting a rule had two choices: fail the build or switch the rule off. Checks added after 0.3 (an unused `depends_on` entry, code outside the modules, a public class that exposes an internal one) could not arrive without failing builds that passed before. Tach and dependency-cruiser use `error`, `warn` and `off`, and the shape of `rules` had to be settled before 1.0 freezes it.

Two questions followed from warnings. Should the baseline hide them like it hides errors? And what should an upgrade do with the baseline entries of a rule that moves to `warn`?

## Decision
- A rule is set to `error`, `warn` or `off` in `rules`. `true` and `false` stay as aliases of `error` and `off`. Any other value, and an unknown rule id, is a configuration error (ADR 0019).
- `error` violations fail `cordon:verify` as before. `warn` violations are reported and never fail. `off` rules do not run.
- A custom rule is set to a severity with its class as the key (`MyRule::class => 'warn'`). Listing the class as a value keeps meaning `error`. Whatever severity a custom rule gives its violations, a rule set to `error` reports errors, so the JSON report matches its schema (ADR 0018).
- Built-in rules added after 0.3 start in `warn`: `unused_dependency`, `host_access` (ADR 0017) and `public_api_leak`. `layers` starts in `error` and checks nothing until `layers` is configured. The three original rules start in `error`.
- The baseline does not suppress warnings and does not record them: they are shown on every run. The baseline entries of a rule moved to `warn` are not counted as stale.
- A baseline entry that no longer matches the code is stale. `cordon:verify` reports stale entries (ADR 0013) and `--fail-on-stale` makes them fail the run with exit code `1`, listing each entry with the occurrences it records and the ones found. It is skipped, with a notice, when a file does not parse, because the references of that file are missing; it cannot be combined with `--no-baseline` (exit `2`). The baseline format does not change.
- The PHPStan rules report only the rules set to `error`.

## Consequences
- A project can adopt a rule gradually: `warn` first, `error` when the warnings are gone.
- New built-in checks can ship without failing upgrades, as the compatibility page promises.
- Warnings are always visible, so they cannot become hidden debt. The price is that a team cannot silence a warning it accepts, except in place with `// @cordon-ignore` (ADR 0020) or by setting the rule to `off`.
- JSON consumers see a `warnings` list, `summary.warnings` and a `severity` on each violation (ADR 0018). The GitHub format writes `::warning`.
- `--fail-on-stale` keeps a baseline from growing old: it can only shrink. It is the "later" that ADR 0013 left for `--strict-baseline`.

## Covers
- Config: the values of `rules.<id>` and `rules.<CustomRule::class>`.
- Options: `cordon:verify --fail-on-stale`.
- Types: `Cordon\Rules\Severity`, `Violation::$severity`, `Violation::withSeverity()`, `Result::$warnings`.
- Exit codes: `1` for errors or, with `--fail-on-stale`, stale entries.
