# ADR 0019: Validate `config/cordon.php` before analysing

**Status:** accepted (2026-11-11)

> No decision of the maintainer is recorded for the exact shape of this validation. It comes from the 0.4 plan (a strict config before 1.0 freezes it) and from the decision of 2026-11-08 to ship everything the plans contain. The error texts and the cases below are what the code does now.

## Context
A typo in `config/cordon.php` changed what was checked without a word. `depend_on` instead of `depends_on` left a module open, `'internal_acess' => false` left the rule running, and a module that no longer existed was only a warning on stderr. CI stayed green. A config key becomes a promise at 1.0, so unknown keys had to stop being ignored first.

## Decision
- `cordon:verify` validates the config before it analyses anything, and before it says "no modules found". Any error is printed (one line each, with a suggestion for the closest valid name, up to two edits away) and the command exits with code `2`. `--allow-empty` does not skip it. With no modules, only the keys that do not name modules are checked.
- Errors are:
  - an unknown key at the root, in a module, in `host` or in `listeners`; the valid root keys are `resolver`, `resolvers`, `public_namespaces`, `modules`, `host`, `layers`, `rules`, `listeners`, `exclude` and `baseline`, and the module keys are `depends_on`, `public`, `open`, `strict` and `metadata`;
  - a module in `modules` that does not exist, and a `depends_on` entry that names a module that does not exist;
  - `modules`, `host`, `rules` and `listeners` that are not arrays; a module whose settings are not an array (`'Shared' => null`); a `depends_on` or `public` that is not a list of strings (`null` is accepted only for `depends_on`, to leave dependencies open); `open`, `strict` and `listeners.*` that are not booleans; `metadata` that is not an array; `host.paths` that is not a list of strings;
  - an unknown rule id, a rule value that is not `error`, `warn`, `off`, `true` or `false` (so `null` and `1` are errors), and two custom rules with the same id;
  - invalid `layers` (ADR 0020) and a module named `host` while `host.paths` is set (ADR 0017);
  - `Module::Interface` in `depends_on`, which is reserved for named interfaces.
- The same errors stop the other commands that read the config (`explain`, `why`, `impact`, `graph`), fail `Cordon::assertBoundaries()` and `toRespectBoundaries()`, and are reported by the PHPStan extension with the identifier `cordon.configuration`. `cordon:modules` shows them as warnings, because listing the modules is how a broken config gets fixed.
- `ModuleMap::configurationWarnings()` is removed. `Cordon\Support\ConfigValidator::errors()` reports the same problems as errors.
- The types of `resolver`, `resolvers`, `exclude`, `public_namespaces` and `baseline` are not validated.

## Consequences
- A project with a typo in its config now fails where it passed. That is the point, and UPGRADE tells the user to run `cordon:verify` once and fix the errors.
- Fixing a typo can change what is checked, since the setting that was ignored now applies.
- Config that was accepted by accident (`'Shared' => null`, a rule set to `null`) is now an error.
- A package that called `ModuleMap::configurationWarnings()` has to move to `ConfigValidator::errors()`. `ModuleMap` is marked `@api` in 0.4, so the method was never covered by the compatibility page.
- Values that are not scalars in `depends_on` or `public` are filtered out by `Module::withConfig()` and reported by the validator.

## Covers
- Config: every key of `config/cordon.php`.
- Exit codes: `2` for configuration errors, from `cordon:verify`.
- Removed: `ModuleMap::configurationWarnings()`.
- PHPStan identifier: `cordon.configuration`.
