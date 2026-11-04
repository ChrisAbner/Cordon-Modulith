# Changelog

All notable changes to Cordon Modulith are documented here. The project follows [Semantic Versioning](https://semver.org) from 1.0.0; before that, minor versions may contain breaking changes.

## [Unreleased]

### Changed
- **Breaking:** `cycles` now reports one violation per dependency inside a cycle (target `Orders -> Payments`), with the shortest cycle that dependency closes, instead of one violation per group of modules (ADR 0011). With the old report, a module joining a cycle recorded in the baseline, or a new dependency inside it, stayed suppressed. The baseline format is now version 2: `cordon:verify` warns when a version 1 baseline has `cycles` entries, which no longer match. Regenerate the baseline once after upgrading.
- When modules are tangled in several cycles, the `cycles` message says so and names the whole group, instead of reading as a single cycle. In real apps the group often spans 25 to 30 modules while the example cycle has two.

## [0.1.1] - 2026-09-29

### Changed
- Global `public_namespaces` entries (`Contracts`, `Events`, `Data`, `Enums`, `Exceptions`) now match at any depth of a module, so `Billing\Invoices\Enums\Status` is public API (ADR 0010). Field tests showed these nested classes were about 20% of the reports in some apps. `#[Internal]` still keeps a class internal, and the per-module `public` list stays anchored at the module root.

### Added
- `SECURITY.md` with private vulnerability reporting, a code of conduct, and issue forms (bug, false positive, feature request).

## [0.1.0] - 2026-09-29

### Added
- `cordon:verify` command with `text`, `json` and `github` output formats.
- `cordon:modules` command to inspect the detected modules.
- Module resolvers for plain namespaces, nwidart/laravel-modules and InterNACHI/modular, with auto-detection.
- Static dependency extraction with nikic/php-parser (the analysed code is never loaded).
- Rules: `internal_access`, `undeclared_dependency`, `cycles`.
- Public API model: public namespaces, `#[PublicApi]`, `#[Internal]`, per-module `public` list and `open` modules.
- Baseline file for gradual adoption (`--generate-baseline`, `--no-baseline`).
- Laravel Boost guideline for AI coding agents.
- `--module` option for `cordon:verify` to report the violations of one or more modules.
- Pest expectation `toRespectBoundaries()` and `Cordon\Testing\Cordon::modules()`.
- PHPStan rule `cordon.internalAccess` (`extension.neon`), reading `config/cordon.php` without booting Laravel.
- Reusable GitHub Action (`action.yml`).
- `cordon:docs` command: Mermaid dependency diagrams, a canvas per module and an event inventory.
- `cordon:verify` and `cordon:docs` warn on stderr when Xdebug is active, since it makes the analysis several times slower.
- Custom rules: classes implementing `Cordon\Contracts\Rule` in the `rules` config.
- Laravel Boost skill `cordon-fix-violations`.
- Documentation site (`docs-site/`), benchmark (`composer bench`) and real-project fixtures.

### Fixed
- Commands read the config through the repository contract (PHPStan level 8).
