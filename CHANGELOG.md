# Changelog

All notable changes to Cordon Modulith are documented here. The project follows [Semantic Versioning](https://semver.org) from 1.0.0; before that, minor versions may contain breaking changes.

## [Unreleased]

## [0.3.1] - 2026-11-05

A patch release: class names are matched regardless of case, and `cordon:modules` tells you when an app is split by layer rather than by feature.

### Added
- `cordon:modules` warns when the detected modules look like the layers of a standard Laravel app (`Http`, `Models`, `Providers`...) rather than modules split by feature, and the installation guide explains which projects Cordon fits.

### Fixed
- Class references are matched to modules and to public namespaces regardless of case, like PHP resolves class names. A reference written as `modules\catalog\Models\Product` was not attributed to `Catalog`, so its use of an internal class was not reported. Thanks to @bfs2021 (#26).

## [0.3.0] - 2026-11-05

This release makes Cordon easier to run on existing projects: CI no longer boots the application, PHP 8.2 is supported, the baseline can shrink safely, and the PHPStan rule applies it.

**Upgrading from 0.2:**
- The GitHub Action now runs `vendor/bin/cordon verify`, which does not boot the application. If your `config/cordon.php` needs the booted app, or a custom rule needs services the app registers, set `artisan: true`.
- The PHPStan rule now applies `cordon-baseline.json`. If you copied Cordon's violations into PHPStan's baseline, remove the `cordon.internalAccess` entries from it.
- To shrink the baseline, use `cordon:verify --prune-baseline` instead of regenerating it.

### Changed
- PHP 8.2 is now supported, with Laravel 12. Nothing in Cordon needed 8.3; only the test tooling did. CI now runs the suite on PHP 8.2, 8.3, 8.4 and 8.5 (Laravel 13 on 8.3 and later).
- `vlucas/phpdotenv` is now a dependency. Without it, `env()` failed outside a Laravel app, so the package defaults in `config/cordon.php` were silently dropped there. Laravel apps already have it.

### Fixed
- With PHPStan 2.3 or later, changing `cordon-baseline.json`, `config/cordon.php`, `config/modules.php` or `config/app-modules.php` now invalidates PHPStan's result cache. It used to keep showing the old `internal_access` errors until the analysed files changed.

### Added
- `cordon:verify` reports baseline entries that no longer match the code, and `--prune-baseline` removes them without adding new violations. Regenerating the baseline to shrink it also accepted any violation that had slipped in. The Boost skill now tells agents to prune instead.
- The PHPStan rule applies `cordon-baseline.json`, so the editor shows the same `internal_access` findings as `cordon:verify` and teams no longer keep a second baseline in PHPStan. It also reads the AST PHPStan already parsed instead of parsing each file again.
- `vendor/bin/cordon verify|modules|docs` runs the same commands as artisan without booting the application, so CI needs no `.env`, database or `APP_KEY`. The GitHub Action uses it by default; set `artisan: true` to keep `php artisan cordon:verify`.
- Case studies in the documentation: Cordon run on Bagisto (namespaces), Advising App (InterNACHI/modular) and LaravelEcomm (nwidart/laravel-modules), with the configuration used for each and what remained after declaring their public API.

## [0.2.0] - 2026-11-04

This release fixes three cases where `cordon:verify` passed when it should not. Two of them change behaviour.

**Upgrading from 0.1:**
- If `cordon-baseline.json` has `cycles` entries, regenerate it once with `php artisan cordon:verify --generate-baseline`. Cycles are now recorded per dependency, so the old entries no longer match and `cordon:verify` warns about them.
- A project with no modules yet now fails `cordon:verify`. Pass `--allow-empty` until the first module exists.

### Added
- `--format=github` reports each file that does not parse as a warning annotation, and the summary counts them. Their references are not checked, and the GitHub output used to leave them out. `--fail-on-parse-error` fails the run when there are any.

### Changed
- **Breaking:** `cordon:verify` now exits with `1` when no modules are found, instead of passing without analysing anything. A misconfigured resolver or a moved `app/Modules` used to keep CI green. With `--format=github` the reason is printed as an error annotation. Pass `--allow-empty` while a project has no modules yet.
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
